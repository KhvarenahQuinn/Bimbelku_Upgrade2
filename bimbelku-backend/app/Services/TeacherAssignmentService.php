<?php

namespace App\Services;

use App\Models\BookingRequest;
use App\Models\MatchingOperationLog;
use App\Models\Notification;
use App\Models\PackageSession;
use App\Models\TeacherOffer;
use App\Models\TeacherReplacementRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeacherAssignmentService
{
    public function __construct(
        private readonly TeacherMatchingService $matchingService,
        private readonly PackageCheckoutService $packageCheckoutService,
        private readonly TeacherReplacementService $teacherReplacementService,
    ) {}

    public function candidateSummaries(BookingRequest $bookingRequest, string $search = ''): Collection
    {
        $search = mb_strtolower(trim($search));
        $bookingRequest->loadMissing([
            'packageSubject.sessions',
            'offers',
        ]);

        return $this->matchingService
            ->eligibleCandidates($bookingRequest)
            ->filter(fn (User $teacher) => $this->eligibilityError($teacher, $bookingRequest) === null)
            ->filter(function (User $teacher) use ($search) {
                if ($search === '') {
                    return true;
                }

                return str_contains(mb_strtolower($teacher->name), $search)
                    || str_contains(mb_strtolower((string) $teacher->email), $search);
            })
            ->take(50)
            ->map(function (User $teacher) use ($bookingRequest) {
                $profile = $teacher->teacherProfile;
                $previousOffer = $bookingRequest->offers
                    ->where('teacher_id', $teacher->id)
                    ->sortByDesc('offered_at')
                    ->first();

                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'rating_average' => round((float) ($teacher->matching_rating_average ?? 0), 1),
                    'rating_count' => (int) ($teacher->matching_rating_count ?? 0),
                    'distance_km' => isset($teacher->match_distance_km)
                        ? round((float) $teacher->match_distance_km, 2)
                        : null,
                    'max_travel_km' => $profile?->max_travel_km,
                    'previous_offer' => $previousOffer ? [
                        'status' => $previousOffer->status,
                        'responded_at' => $previousOffer->responded_at?->toIso8601String(),
                        'rejection_reason' => $previousOffer->rejection_reason,
                    ] : null,
                ];
            })
            ->values();
    }

    public function eligibilityError(User $teacher, BookingRequest $bookingRequest): ?string
    {
        if ($teacher->role !== 'teacher' || $teacher->status !== 'active') {
            return 'Akun yang dipilih bukan tutor aktif.';
        }

        $teacher->loadMissing(['teacherProfile.subjects', 'availabilities']);
        $profile = $teacher->teacherProfile;
        if (! $profile?->verified_at) {
            return 'Tutor belum lolos verifikasi.';
        }
        if (! $profile->is_accepting_requests) {
            return 'Tutor sedang tidak menerima permintaan.';
        }
        if ($profile->suspended_until?->isFuture()) {
            return 'Tutor sedang dibatasi sampai '.$profile->suspended_until->translatedFormat('d M Y, H:i').' WIB.';
        }

        $profile->setRelation('user', $teacher);
        if ($error = $this->matchingService->compatibilityError($profile, $bookingRequest)) {
            return $error;
        }

        if ($bookingRequest->package_subject_id) {
            $bookingRequest->loadMissing(['packageSubject.sessions', 'teacherReplacement.sessions.packageSession']);
            $sessions = $bookingRequest->teacherReplacement
                ? $bookingRequest->teacherReplacement->sessions->pluck('packageSession')
                : ($bookingRequest->packageSubject?->sessions ?? collect());
            foreach ($sessions as $session) {
                if ($this->matchingService->teacherHasConflict(
                    $teacher->id,
                    $session->scheduled_start_at,
                    $session->scheduled_end_at
                )) {
                    return 'Salah satu jadwal paket bertabrakan dengan kelas tutor.';
                }
                if ($this->teacherHasPackageConflict(
                    $teacher->id,
                    $session->scheduled_start_at,
                    $session->scheduled_end_at,
                    $bookingRequest->package_subject_id
                )) {
                    return 'Salah satu jadwal paket bertabrakan dengan paket lain yang ditangani tutor.';
                }
                if (! $this->teacherAvailableAt(
                    $teacher,
                    $session->scheduled_start_at,
                    $session->scheduled_end_at
                )) {
                    return 'Salah satu jadwal paket berada di luar jam tersedia tutor.';
                }
            }

            return null;
        }

        if ($this->matchingService->teacherHasConflict(
            $teacher->id,
            $this->matchingService->startAt($bookingRequest),
            $this->matchingService->endAt($bookingRequest)
        )) {
            return 'Jadwal tutor bertabrakan dengan kelas lain.';
        }

        return null;
    }

    public function assignManually(
        BookingRequest $bookingRequest,
        User $teacher,
        User $admin,
        string $reason
    ): array {
        return DB::transaction(function () use ($bookingRequest, $teacher, $admin, $reason) {
            if ($bookingRequest->teacher_replacement_request_id) {
                TeacherReplacementRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($bookingRequest->teacher_replacement_request_id);
            }
            $lockedRequest = BookingRequest::query()
                ->with([
                    'packageSubject.package',
                    'packageSubject.sessions',
                    'offers',
                ])
                ->lockForUpdate()
                ->findOrFail($bookingRequest->id);

            abort_unless($lockedRequest->package_subject_id, 410, 'Flow pemesanan langsung lama sudah dipensiunkan.');
            abort_unless(
                in_array($lockedRequest->status, ['matching', 'teacher_pending', 'no_teacher'], true),
                422,
                'Permintaan ini tidak lagi menunggu tutor.'
            );
            abort_if(
                $this->matchingService->startAt($lockedRequest)->lte(now()),
                422,
                'Jadwal kelas sudah dimulai atau terlewati.'
            );
            abort_if(
                $error = $this->eligibilityError($teacher, $lockedRequest),
                422,
                $error
            );

            $requestIds = $this->activeRequestIds($lockedRequest);
            $beforeState = [
                'status' => $lockedRequest->status,
                'matched_teacher_id' => $lockedRequest->matched_teacher_id,
                'search_radius_km' => (int) $lockedRequest->search_radius_km,
                'pending_offer_teacher_ids' => TeacherOffer::query()
                    ->whereIn('booking_request_id', $requestIds)
                    ->where('status', 'pending')
                    ->pluck('teacher_id')
                    ->values()
                    ->all(),
            ];

            TeacherOffer::query()
                ->whereIn('booking_request_id', $requestIds)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'responded_at' => now(),
                ]);

            $expiresAt = now()->addMinutes(10)
                ->min($this->matchingService->startAt($lockedRequest));
            abort_if($expiresAt->lte(now()), 422, 'Waktu penetapan tutor sudah tidak mencukupi.');

            $manualDistance = null;
            if (
                $lockedRequest->learning_mode === 'offline'
                && $lockedRequest->latitude !== null
                && $lockedRequest->longitude !== null
                && $teacher->teacherProfile?->latitude !== null
                && $teacher->teacherProfile?->longitude !== null
            ) {
                $manualDistance = $this->distanceKm(
                    (float) $lockedRequest->latitude,
                    (float) $lockedRequest->longitude,
                    (float) $teacher->teacherProfile->latitude,
                    (float) $teacher->teacherProfile->longitude,
                );
            }

            $manualOffer = TeacherOffer::updateOrCreate(
                [
                    'booking_request_id' => $lockedRequest->id,
                    'teacher_id' => $teacher->id,
                ],
                [
                    'status' => 'pending',
                    'distance_km' => $manualDistance,
                    'offered_at' => now(),
                    'expires_at' => $expiresAt,
                    'reminder_sent_at' => null,
                    'responded_at' => null,
                    'rejection_reason' => null,
                ]
            );

            BookingRequest::query()
                ->whereIn('id', $requestIds)
                ->whereIn('status', ['matching', 'teacher_pending', 'no_teacher'])
                ->update([
                    'status' => 'teacher_pending',
                    'matched_teacher_id' => $teacher->id,
                    'teacher_response_deadline' => $expiresAt,
                    'matching_attempts' => DB::raw('matching_attempts + 1'),
                ]);

            if ($lockedRequest->package_subject_id && ! $lockedRequest->teacher_replacement_request_id) {
                $lockedRequest->packageSubject?->update(['status' => 'teacher_pending']);
                $lockedRequest->packageSubject?->package?->update(['status' => 'teacher_pending']);
            }

            $assignment = $this->acceptOffer($manualOffer, $teacher);
            $freshRequest = $lockedRequest->fresh();

            MatchingOperationLog::create([
                'booking_request_id' => $lockedRequest->id,
                'actor_id' => $admin->id,
                'action' => 'teacher_assigned_manually',
                'reason' => trim($reason),
                'before_state' => $beforeState,
                'after_state' => [
                    'status' => $freshRequest->status,
                    'matched_teacher_id' => $teacher->id,
                    'booking_id' => $freshRequest->booking_id,
                ],
                'metadata' => [
                    'teacher_id' => $teacher->id,
                    'teacher_name' => $teacher->name,
                    'manual_offer_id' => $manualOffer->id,
                    'affected_request_ids' => $requestIds->values()->all(),
                    'source_type' => $lockedRequest->teacher_replacement_request_id ? 'teacher_replacement' : 'package',
                ],
            ]);

            Notification::create([
                'user_id' => $teacher->id,
                'title' => 'Penetapan kelas oleh admin',
                'message' => "Admin menetapkan Anda untuk {$lockedRequest->subject_name}. Buka jadwal dan ruang kelas untuk memeriksa detail.",
                'type' => 'info',
                'target_url' => '/guru/kelas',
            ]);

            return [
                'assignment' => $assignment,
                'booking_request' => $freshRequest,
            ];
        }, 3);
    }

    public function acceptOffer(TeacherOffer $teacherOffer, User $teacher): array
    {
        $teacherOffer->loadMissing('bookingRequest.packageSubject');
        abort_unless(
            $teacherOffer->bookingRequest?->package_subject_id,
            410,
            'Flow pemesanan langsung lama sudah dipensiunkan. Gunakan Paket Belajar.'
        );

        if ($teacherOffer->bookingRequest?->teacher_replacement_request_id) {
            return [
                'type' => 'teacher_replacement',
                'data' => $this->teacherReplacementService->acceptOffer($teacherOffer, $teacher),
            ];
        }

        return [
            'type' => 'package',
            'data' => $this->packageCheckoutService->acceptPackageOffer($teacherOffer, $teacher),
        ];
    }

    private function teacherHasPackageConflict(
        int $teacherId,
        Carbon $start,
        Carbon $end,
        ?int $ignoreSubjectId = null
    ): bool {
        return PackageSession::query()
            ->whereHas('subject', fn ($subjects) => $subjects
                ->where('assigned_teacher_id', $teacherId)
                ->when($ignoreSubjectId, fn ($query) => $query->whereKeyNot($ignoreSubjectId))
                ->whereHas('package', fn ($packages) => $packages
                    ->whereIn('status', [
                        'matching',
                        'teacher_pending',
                        'no_teacher',
                        'awaiting_payment',
                        'payment_rejected',
                        'payment_submitted',
                        'active',
                    ])))
            // Released sessions (refund/cancel/expire) no longer hold the slot.
            ->whereNotIn('status', ['cancelled', 'payment_expired', 'completed', 'refund_pending', 'refunded'])
            ->where('scheduled_start_at', '<', $end)
            ->where('scheduled_end_at', '>', $start)
            ->exists();
    }

    private function teacherAvailableAt(User $teacher, Carbon $start, Carbon $end): bool
    {
        return $this->matchingService->teacherAvailableAt($teacher, $start, $end);
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    private function activeRequestIds(BookingRequest $bookingRequest): Collection
    {
        return collect([$bookingRequest->id]);
    }
}
