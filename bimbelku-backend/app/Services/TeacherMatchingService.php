<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\LearningPackage;
use App\Models\MatchingOperationLog;
use App\Models\Notification;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\Setting;
use App\Models\TeacherAvailabilityException;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\TeacherReplacementRequest;
use App\Models\User;
use App\Support\TeacherReplacementState;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeacherMatchingService
{
    /**
     * Admin matching and the tutor offer engine share one strict search
     * window. Keeping it here prevents the detail view, audit payloads, and
     * the scheduler from disagreeing about when the search really ends.
     */
    public const SEARCH_WINDOW_MINUTES = 60;

    private const ACTIVE_BOOKING_STATUSES = [
        'teacher_selected',
        'awaiting_payment',
        'payment_collecting',
        'payment_submitted',
        'confirmed',
        'in_progress',
        'awaiting_student_approval',
        'disputed',
        'absence_review',
    ];

    public function dispatchNextOffer(BookingRequest $bookingRequest): ?TeacherOffer
    {
        $bookingRequest->refresh();

        if (! in_array($bookingRequest->status, ['matching', 'teacher_pending', 'no_teacher'], true)) {
            return null;
        }

        $bookingRequest->offers()
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->get()
            ->each(fn (TeacherOffer $offer) => $this->expireOfferAndContinue($offer, false));

        $startAt = $this->startAt($bookingRequest);
        $acceptanceCutoff = $startAt->copy()->subHours($this->matchingCutoffHours());
        // searchDeadline() already honors an earlier stored search_expires_at,
        // but never lets a legacy 24-hour deadline extend the strict window.
        $maximumSearchDeadline = collect([
            $this->searchDeadline($bookingRequest),
            $acceptanceCutoff,
        ])->filter()->sortBy(fn (Carbon $deadline) => $deadline->timestamp)->first()
            ?? $acceptanceCutoff;

        if (now()->greaterThanOrEqualTo($acceptanceCutoff) || now()->greaterThanOrEqualTo($maximumSearchDeadline)) {
            $failure = [
                'code' => 'search_deadline_exhausted',
                'message' => 'Batas waktu pencarian tutor telah berakhir. Kamu dapat mengubah jadwal, mencoba pencarian sesuai opsi yang tersedia, atau membatalkan pesanan.',
                'recommended_action' => 'change_schedule',
            ];
            $alreadyRecorded = $bookingRequest->matchingOperationLogs()
                ->where('action', 'matching_exhausted')
                ->when($bookingRequest->search_started_at, fn ($query) => $query
                    ->where('created_at', '>=', $bookingRequest->search_started_at))
                ->exists();
            if (! $alreadyRecorded) {
                MatchingOperationLog::create([
                    'booking_request_id' => $bookingRequest->id,
                    'actor_id' => null,
                    'action' => 'matching_exhausted',
                    'reason' => $failure['message'],
                    'before_state' => ['status' => $bookingRequest->status, 'search_radius_km' => (int) $bookingRequest->search_radius_km],
                    'after_state' => ['status' => 'no_teacher', 'search_radius_km' => (int) $bookingRequest->search_radius_km],
                    'metadata' => $failure,
                ]);
                Notification::create([
                    'user_id' => $bookingRequest->student_id,
                    'title' => 'Batas pencarian tutor berakhir',
                    'message' => $failure['message'],
                    'type' => 'warning',
                    'target_url' => '/student/packages',
                ]);
            }
            $bookingRequest->packageSubject?->update(['status' => 'no_teacher']);
            $this->updateRequestGroup($bookingRequest, [
                'status' => 'no_teacher',
                'matched_teacher_id' => null,
                'teacher_response_deadline' => null,
                'next_matching_at' => null,
            ]);

            return null;
        }

        if ($bookingRequest->next_matching_at?->isFuture()) {
            return $bookingRequest->offers()
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->latest('offered_at')
                ->first();
        }

        $endAt = $this->endAt($bookingRequest);
        $activeOffers = $bookingRequest->offers()
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderBy('offered_at')
            ->get();
        $availableSlots = max(0, $this->offerWaveSize($bookingRequest) - $activeOffers->count());
        if ($availableSlots === 0) {
            return $activeOffers->last();
        }

        $offerRequestIds = collect([$bookingRequest->id]);
        $offerCycleResetAt = $bookingRequest->matchingOperationLogs()
            ->where('action', 'schedule_changed')
            ->latest('created_at')
            ->value('created_at');
        $cycleOffers = TeacherOffer::query()
            ->whereIn('booking_request_id', $offerRequestIds)
            ->when($offerCycleResetAt, fn ($query) => $query->where('offered_at', '>=', $offerCycleResetAt))
            ->get(['teacher_id', 'status', 'offered_at']);
        $alreadyOffered = $cycleOffers->pluck('teacher_id')->unique()->values();
        $candidates = $this->candidateQuery($bookingRequest, $alreadyOffered, $startAt, $endAt)
            ->limit(500)
            ->get();
        $candidates = $this->rankCandidates($bookingRequest, $candidates)
            ->take($availableSlots)
            ->values();

        // Tutor yang diam boleh memperoleh satu pengingat setelah masa jeda,
        // tetapi tutor yang menolak tidak pernah ditawari ulang pada pesanan sama.
        if ($candidates->isEmpty() && $cycleOffers->isNotEmpty()) {
            $retryCutoff = now()->subMinutes($this->offerRetryCooldownMinutes());
            $retryableTeacherIds = $cycleOffers
                ->groupBy('teacher_id')
                ->filter(function (Collection $offers) use ($retryCutoff) {
                    return $offers->count() < 2
                        && ! $offers->contains('status', 'rejected')
                        && $offers->every(fn (TeacherOffer $offer) => $offer->status === 'expired')
                        && $offers->max('offered_at')?->lte($retryCutoff);
                })
                ->keys()
                ->map(fn ($teacherId) => (int) $teacherId)
                ->values();

            if ($retryableTeacherIds->isNotEmpty()) {
                $retryCandidates = $this->candidateQuery($bookingRequest, collect(), $startAt, $endAt)
                    ->whereIn('users.id', $retryableTeacherIds)
                    ->limit(500)
                    ->get();
                $candidates = $this->rankCandidates($bookingRequest, $retryCandidates)
                    ->take($availableSlots)
                    ->values();
            }
        }

        if ($candidates->isEmpty()) {
            if ($activeOffers->isNotEmpty()) {
                $this->updateRequestGroup($bookingRequest, [
                    'status' => 'teacher_pending',
                    'matched_teacher_id' => null,
                    'teacher_response_deadline' => $activeOffers->max('expires_at'),
                    'next_matching_at' => null,
                ]);

                return $activeOffers->last();
            }

            $failure = $this->matchingFailureContext($bookingRequest, $alreadyOffered);
            $recentManualRadiusExpansion = $bookingRequest->matchingOperationLogs()
                ->where('action', 'radius_expanded')
                ->where('created_at', '>=', now()->subMinute())
                ->exists();
            $automaticRadius = $bookingRequest->learning_mode === 'offline' && ! $recentManualRadiusExpansion
                ? $this->nextRadius((int) $bookingRequest->search_radius_km)
                : null;
            $previousRadius = (int) $bookingRequest->search_radius_km;
            if ($automaticRadius !== null && in_array($failure['code'], ['radius_exhausted', 'no_current_candidate'], true)) {
                $failure = [
                    'code' => 'automatic_radius_expansion',
                    'message' => "Belum ada tutor dalam radius {$previousRadius} km. Sistem akan memeriksa ulang otomatis dengan radius {$automaticRadius} km.",
                    'recommended_action' => 'wait_for_retry',
                ];
            }
            $nextMatchingAt = now()->addMinutes($this->waitingRetryMinutes())->min($maximumSearchDeadline);
            $firstWaitingNotice = ! $bookingRequest->matchingOperationLogs()
                ->where('action', 'waiting_for_availability')
                ->when($offerCycleResetAt, fn ($query) => $query->where('created_at', '>=', $offerCycleResetAt))
                ->exists();
            $this->updateRequestGroup($bookingRequest, [
                'status' => 'no_teacher',
                'matched_teacher_id' => null,
                'teacher_response_deadline' => null,
                'next_matching_at' => $nextMatchingAt,
                'search_radius_km' => $automaticRadius ?? $previousRadius,
            ]);

            MatchingOperationLog::create([
                'booking_request_id' => $bookingRequest->id,
                'actor_id' => null,
                'action' => 'waiting_for_availability',
                'reason' => $failure['message'],
                'before_state' => [
                    'status' => 'matching',
                    'search_radius_km' => (int) $bookingRequest->search_radius_km,
                ],
                'after_state' => [
                    'status' => 'no_teacher',
                    'search_radius_km' => $automaticRadius ?? $previousRadius,
                ],
                'metadata' => [
                    ...$failure,
                    'automatic_radius_from_km' => $automaticRadius ? $previousRadius : null,
                    'automatic_radius_to_km' => $automaticRadius,
                ],
            ]);

            if ($firstWaitingNotice) {
                Notification::create([
                    'user_id' => $bookingRequest->student_id,
                    'title' => 'Masih mencari tutor',
                    'message' => 'Belum ada tutor yang tersedia. Sistem akan memeriksa kandidat baru secara otomatis dan admin sudah diberi tahu.',
                    'type' => 'warning',
                    'target_url' => '/student/packages',
                ]);
                User::query()
                    ->where('role', 'admin')
                    ->where('status', 'active')
                    ->pluck('id')
                    ->each(fn (int $adminId) => Notification::create([
                        'user_id' => $adminId,
                        'title' => 'Pencarian tutor membutuhkan perhatian',
                        'message' => "Permintaan #{$bookingRequest->id} belum memiliki kandidat tutor yang tersedia.",
                        'type' => 'warning',
                        'target_url' => '/admin/tutor-searches',
                    ]));
            }

            return null;
        }

        $expiresAt = $this->offerResponseDeadline($bookingRequest, $maximumSearchDeadline);

        $offerResult = DB::transaction(function () use (
            $bookingRequest,
            $candidates,
            $expiresAt
        ) {
            $lockedRequest = BookingRequest::query()
                ->lockForUpdate()
                ->findOrFail($bookingRequest->id);
            if (! in_array($lockedRequest->status, ['matching', 'teacher_pending', 'no_teacher'], true)) {
                return ['offers' => collect(), 'created' => false];
            }

            $existing = $lockedRequest->offers()
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->orderBy('offered_at')
                ->get();
            $availableSlots = max(0, $this->offerWaveSize($lockedRequest) - $existing->count());
            if ($availableSlots === 0) {
                return ['offers' => $existing, 'created' => false];
            }

            $offers = $candidates->take($availableSlots)->map(fn (User $candidate) => TeacherOffer::updateOrCreate(
                [
                    'booking_request_id' => $lockedRequest->id,
                    'teacher_id' => $candidate->id,
                ],
                [
                    'status' => 'pending',
                    'distance_km' => $candidate->match_distance_km,
                    'offered_at' => now(),
                    'expires_at' => $expiresAt,
                    'reminder_sent_at' => null,
                    'responded_at' => null,
                    'rejection_reason' => null,
                ]
            ));

            if ($offers->isEmpty()) {
                return ['offers' => $existing, 'created' => false];
            }

            $latestDeadline = $lockedRequest->offers()
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->max('expires_at');
            $beforeStatus = $lockedRequest->status;
            $this->updateRequestGroup($lockedRequest, [
                'status' => 'teacher_pending',
                'matched_teacher_id' => null,
                'teacher_response_deadline' => $latestDeadline,
                'next_matching_at' => null,
            ], incrementAttempts: true);
            $updatedRequest = $lockedRequest->refresh();

            MatchingOperationLog::create([
                'booking_request_id' => $updatedRequest->id,
                'actor_id' => null,
                'action' => 'offers_dispatched',
                'reason' => 'Mesin pencocokan mengirim penawaran otomatis ke '
                    .$offers->count().' tutor.',
                'before_state' => [
                    'status' => $beforeStatus,
                    'search_radius_km' => (int) $updatedRequest->search_radius_km,
                ],
                'after_state' => [
                    'status' => $updatedRequest->status,
                    'search_radius_km' => (int) $updatedRequest->search_radius_km,
                    'matched_teacher_id' => null,
                    'teacher_response_deadline' => $latestDeadline,
                    'search_expires_at' => $this->searchDeadline($updatedRequest)->toIso8601String(),
                    'package_status' => $updatedRequest->packageSubject?->package?->status,
                ],
                'metadata' => [
                    'source' => 'matching_engine',
                    'offer_count' => $offers->count(),
                    'teacher_ids' => $offers->pluck('teacher_id')->values()->all(),
                    'offer_expires_at' => $expiresAt->toIso8601String(),
                    'wave_size' => $this->offerWaveSize($updatedRequest),
                    'package_subject_id' => $updatedRequest->package_subject_id,
                ],
            ]);

            return ['offers' => $offers, 'created' => true];
        });

        $offers = $offerResult['offers'];
        if ($offers->isEmpty() || ! $offerResult['created']) {
            return $offers->first();
        }

        $offers->each(function (TeacherOffer $offer) use ($bookingRequest, $expiresAt) {
            Notification::create([
                'user_id' => $offer->teacher_id,
                'title' => 'Permintaan bimbel baru',
                'message' => "Permintaan {$bookingRequest->subject_name} menunggu jawaban sampai {$expiresAt->translatedFormat('d M Y, H:i')} WIB. Terima sebelum batas waktu jika jadwal ini sesuai.",
                'type' => 'info',
                'target_url' => '/guru/permintaan',
            ]);
        });

        return $offers->first();
    }

    public function offerResponseDeadline(
        BookingRequest $bookingRequest,
        ?Carbon $maximumSearchDeadline = null
    ): Carbon {
        $deadline = now()->addMinutes($this->teacherResponseMinutes($bookingRequest));
        $deadline = $deadline->min(
            $this->startAt($bookingRequest)->subHours($this->matchingCutoffHours())
        );

        $searchDeadline = $maximumSearchDeadline;
        $requestDeadline = $this->searchDeadline($bookingRequest);
        if (! $searchDeadline || $requestDeadline->lt($searchDeadline)) {
            $searchDeadline = $requestDeadline;
        }

        return $deadline->min($searchDeadline);
    }

    public function searchWindowMinutes(): int
    {
        return self::SEARCH_WINDOW_MINUTES;
    }

    /**
     * The search clock starts at payment completion when available, otherwise
     * at request creation. Admin payloads and the engine must use the same
     * anchor so their duration and deadline never contradict each other.
     */
    public function searchStartedAt(BookingRequest $bookingRequest): Carbon
    {
        return ($bookingRequest->search_started_at ?? $bookingRequest->created_at ?? now())->copy();
    }

    /**
     * Strict one-hour deadline. A stored search_expires_at is only honored when
     * it is earlier than the one-hour window (for example, a legacy row or an
     * explicit earlier cutoff), never when it would extend the search.
     */
    public function searchDeadline(BookingRequest $bookingRequest): Carbon
    {
        $deadline = $this->searchStartedAt($bookingRequest)->addMinutes($this->searchWindowMinutes());
        $storedDeadline = $bookingRequest->search_expires_at;

        return $storedDeadline && $storedDeadline->lt($deadline)
            ? $storedDeadline->copy()
            : $deadline;
    }

    public function searchAgeSeconds(BookingRequest $bookingRequest): int
    {
        $startedAt = $this->searchStartedAt($bookingRequest);
        $now = now();

        if ($startedAt->gte($now)) {
            return 0;
        }

        $deadline = $this->searchDeadline($bookingRequest);
        if ($now->gt($deadline)) {
            $now = $deadline->copy();
        }

        return max(0, (int) $startedAt->diffInSeconds($now));
    }

    public function expireOfferAndContinue(TeacherOffer $offer, bool $continue = true): void
    {
        $expiredOffer = DB::transaction(function () use ($offer) {
            $lockedRequest = BookingRequest::query()
                ->lockForUpdate()
                ->find($offer->booking_request_id);
            $lockedOffer = TeacherOffer::query()
                ->lockForUpdate()
                ->find($offer->id);

            if (
                ! $lockedOffer
                || $lockedOffer->status !== 'pending'
                || $lockedOffer->expires_at->isFuture()
            ) {
                return null;
            }

            $lockedOffer->update([
                'status' => 'expired',
                'responded_at' => now(),
                'no_response_penalty_applied' => true,
            ]);

            if ($lockedRequest && in_array($lockedRequest->status, ['teacher_pending', 'matching'], true)) {
                $otherDeadline = $lockedRequest->offers()
                    ->where('status', 'pending')
                    ->where('expires_at', '>', now())
                    ->max('expires_at');
                $beforeStatus = $lockedRequest->status;
                $nextStatus = $otherDeadline ? 'teacher_pending' : 'matching';
                $this->updateRequestGroup($lockedRequest, $otherDeadline ? [
                    'status' => $nextStatus,
                    'matched_teacher_id' => null,
                    'teacher_response_deadline' => $otherDeadline,
                ] : [
                    'status' => $nextStatus,
                    'matched_teacher_id' => null,
                    'teacher_response_deadline' => null,
                ]);
                $updatedRequest = $lockedRequest->refresh();

                MatchingOperationLog::create([
                    'booking_request_id' => $updatedRequest->id,
                    'actor_id' => null,
                    'action' => 'offer_expired',
                    'reason' => 'Penawaran tutor tidak dijawab sebelum batas waktu.',
                    'before_state' => [
                        'status' => $beforeStatus,
                        'search_radius_km' => (int) $updatedRequest->search_radius_km,
                    ],
                    'after_state' => [
                        'status' => $updatedRequest->status,
                        'search_radius_km' => (int) $updatedRequest->search_radius_km,
                        'matched_teacher_id' => null,
                        'teacher_response_deadline' => $otherDeadline,
                        'search_expires_at' => $this->searchDeadline($updatedRequest)->toIso8601String(),
                        'package_status' => $updatedRequest->packageSubject?->package?->status,
                    ],
                    'metadata' => [
                        'source' => 'offer_expiry',
                        'offer_id' => $lockedOffer->id,
                        'teacher_id' => $lockedOffer->teacher_id,
                    ],
                ]);
            }

            return $lockedOffer;
        });

        if (! $expiredOffer) {
            return;
        }

        $this->applyNoResponseRestriction($expiredOffer);

        $bookingRequest = $expiredOffer->bookingRequest;
        if (! $bookingRequest || ! in_array($bookingRequest->status, ['teacher_pending', 'matching'], true)) {
            return;
        }

        if ($continue && in_array($bookingRequest->fresh()->status, ['teacher_pending', 'matching'], true)) {
            $this->dispatchNextOffer($bookingRequest);
        }
    }

    public function expireStaleOffersForTeacher(int $teacherId): void
    {
        TeacherOffer::query()
            ->where('teacher_id', $teacherId)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->with('bookingRequest')
            ->get()
            ->each(fn (TeacherOffer $offer) => $this->expireOfferAndContinue($offer));
    }

    public function teacherHasConflict(int $teacherId, Carbon $startAt, Carbon $endAt, ?int $ignoreBookingId = null): bool
    {
        return Booking::query()
            ->where('teacher_id', $teacherId)
            ->whereIn('status', self::ACTIVE_BOOKING_STATUSES)
            ->when($ignoreBookingId, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();
    }

    public function hasAvailableCandidate(BookingRequest $bookingRequest): bool
    {
        return $this->availableCandidateCountForPreview($bookingRequest) > 0;
    }

    public function availableCandidateCountForPreview(BookingRequest $bookingRequest): int
    {
        $startAt = $this->startAt($bookingRequest);
        $endAt = $this->endAt($bookingRequest);
        $candidates = $this->candidateQuery(
            $bookingRequest,
            collect(),
            $startAt,
            $endAt
        )
            ->limit(100)
            ->get();

        return $this->rankCandidates($bookingRequest, $candidates)->count();
    }

    public function availableCandidateCount(BookingRequest $bookingRequest): int
    {
        if (! in_array($bookingRequest->status, ['matching', 'teacher_pending', 'no_teacher'], true)) {
            return 0;
        }

        $requestIds = collect([$bookingRequest->id]);
        $alreadyOffered = TeacherOffer::query()
            ->whereIn('booking_request_id', $requestIds)
            ->pluck('teacher_id');
        $candidates = $this->candidateQuery(
            $bookingRequest,
            $alreadyOffered,
            $this->startAt($bookingRequest),
            $this->endAt($bookingRequest)
        )
            ->limit(500)
            ->get();

        return $this->rankCandidates($bookingRequest, $candidates)->count();
    }

    /**
     * Kandidat yang masih memenuhi syarat saat ini. Daftar admin tidak
     * mengecualikan tutor yang pernah ditawari karena penetapan manual dapat
     * dilakukan setelah admin memastikan ulang kesediaan tutor.
     */
    public function eligibleCandidates(BookingRequest $bookingRequest, int $limit = 500): Collection
    {
        if (! in_array($bookingRequest->status, ['matching', 'teacher_pending', 'no_teacher'], true)) {
            return collect();
        }

        $candidates = $this->candidateQuery(
            $bookingRequest,
            collect(),
            $this->startAt($bookingRequest),
            $this->endAt($bookingRequest)
        )
            ->limit(max(1, min(500, $limit)))
            ->get();

        return $this->rankCandidates($bookingRequest, $candidates)->values();
    }

    public function compatibilityError(
        TeacherProfile $profile,
        BookingRequest $bookingRequest
    ): ?string {
        $modeColumn = $bookingRequest->learning_mode === 'offline' ? 'is_offline' : 'is_online';
        $classTypeColumn = 'is_private_active';

        $subjectMatches = $profile->subjects()
            ->where(function ($subjects) use ($bookingRequest) {
                if ($bookingRequest->curriculum_subject_id) {
                    $subjects->where(function ($identity) use ($bookingRequest) {
                        $identity->where('curriculum_subject_id', $bookingRequest->curriculum_subject_id)
                            ->orWhere(function ($legacy) use ($bookingRequest) {
                                $legacy->whereNull('curriculum_subject_id')
                                    ->where('name', $bookingRequest->subject_name);
                            });
                    });
                } else {
                    $subjects->where('name', $bookingRequest->subject_name);
                }
            })
            ->where('is_active', true)
            ->where($modeColumn, true)
            ->where($classTypeColumn, true)
            ->where(function ($levels) use ($bookingRequest) {
                $levels->whereNull('levels')
                    ->orWhereJsonContains('levels', $bookingRequest->education_level);
            })
            ->exists();

        if (! $subjectMatches) {
            return 'Mata pelajaran, jenjang, mode, atau jenis kelas tidak lagi sesuai dengan profil Anda.';
        }

        if (! $this->teacherAvailableForRequest($profile->user, $bookingRequest)) {
            return 'Salah satu hari atau jam yang dipilih murid tidak lagi tersedia pada profil Anda.';
        }

        if ($bookingRequest->learning_mode !== 'offline') {
            return null;
        }

        if (
            $bookingRequest->latitude === null
            || $bookingRequest->longitude === null
            || $profile->latitude === null
            || $profile->longitude === null
        ) {
            return 'Titik lokasi tutor atau murid belum lengkap untuk kelas offline.';
        }

        $distance = $this->distanceKm(
            (float) $bookingRequest->latitude,
            (float) $bookingRequest->longitude,
            (float) $profile->latitude,
            (float) $profile->longitude,
        );
        $maximumDistance = min(
            (int) $bookingRequest->search_radius_km,
            max(1, (int) $profile->max_travel_km)
        );

        return $distance <= $maximumDistance
            ? null
            : "Jarak terbaru {$distance} km telah melampaui jangkauan {$maximumDistance} km.";
    }

    public function startAt(BookingRequest $bookingRequest): Carbon
    {
        return Carbon::parse(
            $bookingRequest->scheduled_date->format('Y-m-d').' '.$bookingRequest->start_time,
            config('app.timezone', 'Asia/Jakarta')
        );
    }

    public function endAt(BookingRequest $bookingRequest): Carbon
    {
        $endAt = Carbon::parse(
            $bookingRequest->scheduled_date->format('Y-m-d').' '.$bookingRequest->end_time,
            config('app.timezone', 'Asia/Jakarta')
        );
        if ($endAt->lte($this->startAt($bookingRequest))) {
            $endAt->addDay();
        }

        return $endAt;
    }

    public function nextRadius(int $currentRadius): ?int
    {
        $radii = [3, 5, 8, 12];
        $index = array_search($currentRadius, $radii, true);

        if ($index === false) {
            return 3;
        }

        return $radii[$index + 1] ?? null;
    }

    public function expandRadius(
        BookingRequest $bookingRequest,
        User $actor,
        string $reason,
        string $source = 'admin'
    ): int {
        return DB::transaction(function () use ($bookingRequest, $actor, $reason, $source) {
            $lockedRequest = BookingRequest::query()
                ->with(['packageSubject.package'])
                ->lockForUpdate()
                ->findOrFail($bookingRequest->id);

            abort_unless($lockedRequest->learning_mode === 'offline', 422, 'Radius hanya berlaku untuk kelas offline.');
            abort_unless($lockedRequest->status === 'no_teacher', 422, 'Radius hanya dapat diperluas setelah kandidat pada radius saat ini habis.');
            abort_if($this->startAt($lockedRequest)->lte(now()), 422, 'Jadwal kelas sudah dimulai atau terlewati.');

            $nextRadius = $this->nextRadius((int) $lockedRequest->search_radius_km);
            abort_if($nextRadius === null, 422, 'Radius maksimum 12 km sudah digunakan.');

            $requestIds = $this->activeRequestIds($lockedRequest);
            $beforeState = [
                'status' => $lockedRequest->status,
                'search_radius_km' => (int) $lockedRequest->search_radius_km,
                'matched_teacher_id' => $lockedRequest->matched_teacher_id,
            ];

            BookingRequest::query()
                ->whereIn('id', $requestIds)
                ->where('status', 'no_teacher')
                ->update([
                    'search_radius_km' => $nextRadius,
                    'status' => 'matching',
                    'matched_teacher_id' => null,
                    'teacher_response_deadline' => null,
                    'next_matching_at' => null,
                ]);

            if ($lockedRequest->package_subject_id) {
                $lockedRequest->packageSubject?->update(['status' => 'matching']);
                $lockedRequest->packageSubject?->package?->update(['status' => 'matching']);
            }

            MatchingOperationLog::create([
                'booking_request_id' => $lockedRequest->id,
                'actor_id' => $actor->id,
                'action' => 'radius_expanded',
                'reason' => trim($reason),
                'before_state' => $beforeState,
                'after_state' => [
                    'status' => 'matching',
                    'search_radius_km' => $nextRadius,
                    'matched_teacher_id' => null,
                ],
                'metadata' => [
                    'source' => $source,
                    'affected_request_ids' => $requestIds->values()->all(),
                ],
            ]);

            return $nextRadius;
        }, 3);
    }

    private function candidateQuery(
        BookingRequest $bookingRequest,
        Collection $alreadyOffered,
        Carbon $startAt,
        Carbon $endAt
    ) {
        $dayName = $this->indonesianDayName($bookingRequest->scheduled_date->dayOfWeekIso);
        $modeColumn = $bookingRequest->learning_mode === 'offline' ? 'is_offline' : 'is_online';

        $query = User::query()
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->whereNotIn('id', $alreadyOffered)
            ->whereHas('teacherProfile', function ($query) {
                $query->whereNotNull('verified_at')
                    ->where('is_accepting_requests', true)
                    ->where(function ($cooldown) {
                        $cooldown->whereNull('suspended_until')->orWhere('suspended_until', '<=', now());
                    });
            })
            ->whereHas('teacherProfile.subjects', function ($query) use ($bookingRequest, $modeColumn) {
                $classTypeColumn = 'is_private_active';

                $query->where(function ($subjects) use ($bookingRequest) {
                    if ($bookingRequest->curriculum_subject_id) {
                        $subjects->where(function ($identity) use ($bookingRequest) {
                            $identity->where('curriculum_subject_id', $bookingRequest->curriculum_subject_id)
                                ->orWhere(function ($legacy) use ($bookingRequest) {
                                    $legacy->whereNull('curriculum_subject_id')
                                        ->where('name', $bookingRequest->subject_name);
                                });
                        });
                    } else {
                        $subjects->where('name', $bookingRequest->subject_name);
                    }
                })
                    ->where('is_active', true)
                    ->where($modeColumn, true)
                    ->where($classTypeColumn, true)
                    ->where(function ($levels) use ($bookingRequest) {
                        $levels->whereNull('levels')
                            ->orWhereJsonContains('levels', $bookingRequest->education_level);
                    });
            })
            ->whereHas('availabilities', function ($query) use ($dayName) {
                $query->where('day', $dayName)
                    ->where('is_active', true);
            })
            ->whereDoesntHave('teacherBookings', function ($query) use ($startAt, $endAt) {
                $query->whereIn('status', self::ACTIVE_BOOKING_STATUSES)
                    ->where('start_at', '<', $endAt)
                    ->where('end_at', '>', $startAt);
            })
            ->whereDoesntHave('teacherOffers', function ($query) use ($bookingRequest) {
                $query->where('status', 'pending')
                    ->where('expires_at', '>', now())
                    ->whereHas('bookingRequest', function ($requests) use ($bookingRequest) {
                        $requests->whereKeyNot($bookingRequest->id)
                            ->where('scheduled_date', $bookingRequest->scheduled_date->format('Y-m-d'))
                            ->where('start_time', '<', $bookingRequest->end_time)
                            ->where('end_time', '>', $bookingRequest->start_time);
                    });
            })
            ->with(['teacherProfile.subjects', 'availabilities'])
            ->withCount([
                'teacherBookings as matching_active_booking_count' => fn ($bookings) => $bookings
                    ->whereIn('status', self::ACTIVE_BOOKING_STATUSES),
                'teacherOffers as matching_offer_total_count' => fn ($offers) => $offers
                    ->whereIn('status', ['accepted', 'rejected', 'expired']),
                'teacherOffers as matching_offer_responded_count' => fn ($offers) => $offers
                    ->whereIn('status', ['accepted', 'rejected']),
            ])
            ->withMax('teacherOffers as matching_last_offered_at', 'offered_at')
            ->withAvg('ratings as matching_rating_average', 'rating')
            ->withCount('ratings as matching_rating_count');

        $programPackage = $bookingRequest->packageSubject?->package;
        if ($programPackage?->learning_program_id) {
            $otherAssigned = $programPackage->subjects()
                ->whereKeyNot($bookingRequest->package_subject_id)
                ->whereNotNull('assigned_teacher_id')
                ->pluck('assigned_teacher_id');
            $query->whereNotIn('users.id', $otherAssigned)
                ->whereDoesntHave('teacherOffers', function ($offers) use ($bookingRequest, $programPackage) {
                    $offers->where('status', 'pending')->where('expires_at', '>', now())
                        ->whereHas('bookingRequest.packageSubject', fn ($subjects) => $subjects
                            ->whereKeyNot($bookingRequest->package_subject_id)
                            ->where('learning_package_id', $programPackage->id));
                });
        }

        if (
            $bookingRequest->learning_mode === 'offline'
            && $bookingRequest->latitude !== null
            && $bookingRequest->longitude !== null
        ) {
            $radius = max(1, min(12, (int) $bookingRequest->search_radius_km));
            $latitude = (float) $bookingRequest->latitude;
            $longitude = (float) $bookingRequest->longitude;
            $latitudeDelta = $radius / 111;
            $longitudeScale = max(0.2, abs(cos(deg2rad($latitude))));
            $longitudeDelta = $radius / (111 * $longitudeScale);

            $query->whereHas('teacherProfile', function ($profiles) use (
                $latitude,
                $longitude,
                $latitudeDelta,
                $longitudeDelta
            ) {
                $profiles
                    ->whereBetween('latitude', [
                        $latitude - $latitudeDelta,
                        $latitude + $latitudeDelta,
                    ])
                    ->whereBetween('longitude', [
                        $longitude - $longitudeDelta,
                        $longitude + $longitudeDelta,
                    ]);
            });
        }

        return $query;
    }

    public function teacherAvailableForRequest(User $teacher, BookingRequest $bookingRequest): bool
    {
        return $this->requestIntervals($bookingRequest)->every(
            fn (array $interval) => $this->teacherAvailableAt($teacher, $interval['start'], $interval['end'])
        );
    }

    public function teacherAvailableAt(User $teacher, Carbon $startAt, Carbon $endAt): bool
    {
        if ($startAt->format('i') !== '00' || $endAt->format('i') !== '00') {
            return false;
        }

        if (TeacherAvailabilityException::query()
            ->where('user_id', $teacher->id)
            ->whereDate('start_date', '<=', $startAt->toDateString())
            ->whereDate('end_date', '>=', $startAt->toDateString())
            ->exists()) {
            return false;
        }

        $teacher->loadMissing('availabilities');
        $dayName = $this->indonesianDayName($startAt->dayOfWeekIso);
        $availability = $teacher->availabilities->first(
            fn ($item) => $item->day === $dayName && $item->is_active
        );

        return $availability?->covers($startAt->format('H:i'), $endAt->format('H:i')) ?? false;
    }

    private function teacherCanCoverRequestSchedule(User $teacher, BookingRequest $bookingRequest): bool
    {
        return $this->requestIntervals($bookingRequest)->every(function (array $interval) use ($teacher, $bookingRequest) {
            return $this->teacherAvailableAt($teacher, $interval['start'], $interval['end'])
                && ! $this->teacherHasConflict($teacher->id, $interval['start'], $interval['end'])
                && ! $this->teacherHasPendingOfferConflict(
                    $teacher->id,
                    $interval['start'],
                    $interval['end'],
                    $bookingRequest->id
                )
                && ! $this->teacherHasPackageSessionConflict(
                    $teacher->id,
                    $interval['start'],
                    $interval['end'],
                    $bookingRequest->package_subject_id
                );
        });
    }

    private function requestIntervals(BookingRequest $bookingRequest): Collection
    {
        if ($bookingRequest->teacher_replacement_request_id) {
            $bookingRequest->loadMissing('teacherReplacement.sessions.packageSession');

            return $bookingRequest->teacherReplacement->sessions
                ->whereIn('status', ['matching', 'selected'])
                ->map(fn ($row) => [
                    'start' => $row->packageSession->scheduled_start_at->copy(),
                    'end' => $row->packageSession->scheduled_end_at->copy(),
                ])->values();
        }
        if ($bookingRequest->package_subject_id) {
            $bookingRequest->loadMissing('packageSubject.sessions');
            $sessions = $bookingRequest->packageSubject?->sessions ?? collect();
            if ($sessions->isNotEmpty()) {
                return $sessions->map(fn ($session) => [
                    'start' => $session->scheduled_start_at->copy(),
                    'end' => $session->scheduled_end_at->copy(),
                ]);
            }
        }

        return collect([[
            'start' => $this->startAt($bookingRequest),
            'end' => $this->endAt($bookingRequest),
        ]]);
    }

    private function teacherHasPendingOfferConflict(
        int $teacherId,
        Carbon $startAt,
        Carbon $endAt,
        ?int $ignoreRequestId = null
    ): bool {
        $offers = TeacherOffer::query()
            ->where('teacher_id', $teacherId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->when($ignoreRequestId, fn ($query) => $query
                ->where('booking_request_id', '<>', $ignoreRequestId))
            ->with('bookingRequest.packageSubject.sessions')
            ->get();

        return $offers->contains(function (TeacherOffer $offer) use ($startAt, $endAt) {
            $request = $offer->bookingRequest;
            if (! $request) {
                return false;
            }

            return $this->requestIntervals($request)->contains(
                fn (array $interval) => $interval['start']->lt($endAt)
                    && $interval['end']->gt($startAt)
            );
        });
    }

    private function teacherHasPackageSessionConflict(
        int $teacherId,
        Carbon $startAt,
        Carbon $endAt,
        ?int $ignoreSubjectId = null
    ): bool {
        return PackageSession::query()
            ->whereHas('subject', fn ($subjects) => $subjects
                ->where('assigned_teacher_id', $teacherId)
                ->when($ignoreSubjectId, fn ($query) => $query->whereKeyNot($ignoreSubjectId))
                ->whereHas('package', fn ($packages) => $packages->whereIn('status', [
                    'matching', 'teacher_pending', 'no_teacher', 'awaiting_payment',
                    'payment_rejected', 'payment_submitted', 'active',
                ])))
            // A refunded/cancelled slot must not keep blocking the tutor.
            ->whereNotIn('status', ['cancelled', 'payment_expired', 'completed', 'refund_pending', 'refunded'])
            ->where('scheduled_start_at', '<', $endAt)
            ->where('scheduled_end_at', '>', $startAt)
            ->exists();
    }

    private function rankCandidates(BookingRequest $bookingRequest, Collection $candidates): Collection
    {
        $bookingRequest->loadMissing(['packageSubject', 'teacherReplacement']);
        if ($bookingRequest->teacherReplacement) {
            $candidates = $candidates->where('id', '<>', $bookingRequest->teacherReplacement->old_teacher_id);
        }
        $preferredTeacherId = (int) ($bookingRequest->packageSubject?->preferred_teacher_id ?? 0);
        $continuityTeacherId = Booking::query()
            ->where('student_id', $bookingRequest->student_id)
            ->where('status', 'completed')
            ->where('learning_mode', $bookingRequest->learning_mode)
            ->where('class_type', 'private')
            ->whereHas('bookingRequest', function ($query) use ($bookingRequest) {
                $query->where('education_level', $bookingRequest->education_level)
                    ->where(function ($subjects) use ($bookingRequest) {
                        if ($bookingRequest->curriculum_subject_id) {
                            $subjects->where('curriculum_subject_id', $bookingRequest->curriculum_subject_id);
                        } else {
                            $subjects->where('subject_name', $bookingRequest->subject_name);
                        }
                    });
            })
            ->latest('completed_at')
            ->value('teacher_id');

        return $candidates
            ->filter(function (User $teacher) use ($bookingRequest) {
                if (! $this->teacherCanCoverRequestSchedule($teacher, $bookingRequest)) {
                    return false;
                }

                $distance = null;

                if ($bookingRequest->learning_mode === 'offline') {
                    $profile = $teacher->teacherProfile;
                    if (
                        $bookingRequest->latitude === null
                        || $bookingRequest->longitude === null
                        || $profile?->latitude === null
                        || $profile?->longitude === null
                    ) {
                        return false;
                    }

                    $distance = $this->distanceKm(
                        (float) $bookingRequest->latitude,
                        (float) $bookingRequest->longitude,
                        (float) $profile->latitude,
                        (float) $profile->longitude,
                    );

                    if ($distance > (int) $bookingRequest->search_radius_km) {
                        return false;
                    }

                    if ($profile->max_travel_km && $distance > $profile->max_travel_km) {
                        return false;
                    }
                }

                $teacher->match_distance_km = $distance;

                return true;
            })
            ->sort(function (User $a, User $b) use ($bookingRequest, $continuityTeacherId, $preferredTeacherId) {
                if ($preferredTeacherId > 0) {
                    $preferredA = $a->id === $preferredTeacherId ? 0 : 1;
                    $preferredB = $b->id === $preferredTeacherId ? 0 : 1;
                    if ($preferredA !== $preferredB) {
                        return $preferredA <=> $preferredB;
                    }
                }

                if ($bookingRequest->learning_mode === 'offline') {
                    $distanceBucketA = (int) floor(($a->match_distance_km ?? 9999) / 2);
                    $distanceBucketB = (int) floor(($b->match_distance_km ?? 9999) / 2);
                    if ($distanceBucketA !== $distanceBucketB) {
                        return $distanceBucketA <=> $distanceBucketB;
                    }
                }

                if ($continuityTeacherId) {

                    $continuityA = $a->id === (int) $continuityTeacherId ? 0 : 1;
                    $continuityB = $b->id === (int) $continuityTeacherId ? 0 : 1;
                    if ($continuityA !== $continuityB) {
                        return $continuityA <=> $continuityB;
                    }
                }

                $activeA = (int) ($a->matching_active_booking_count ?? 0);
                $activeB = (int) ($b->matching_active_booking_count ?? 0);
                if ($activeA !== $activeB) {
                    return $activeA <=> $activeB;
                }

                $lastOfferedA = $a->matching_last_offered_at
                    ? Carbon::parse($a->matching_last_offered_at)->timestamp
                    : 0;
                $lastOfferedB = $b->matching_last_offered_at
                    ? Carbon::parse($b->matching_last_offered_at)->timestamp
                    : 0;
                if ($lastOfferedA !== $lastOfferedB) {
                    return $lastOfferedA <=> $lastOfferedB;
                }

                $responseA = $this->responseReliabilityScore($a);
                $responseB = $this->responseReliabilityScore($b);
                if ($responseA !== $responseB) {
                    return $responseB <=> $responseA;
                }

                $ratingA = $this->ratingScore($a);
                $ratingB = $this->ratingScore($b);
                if ($ratingA !== $ratingB) {
                    return $ratingB <=> $ratingA;
                }

                return $a->id <=> $b->id;

            })
            ->values();
    }

    private function ratingScore(User $teacher): int
    {
        $count = (int) ($teacher->matching_rating_count ?? 0);
        $average = (float) ($teacher->matching_rating_average ?? 0);

        // Prior netral mencegah satu ulasan mengalahkan rekam jejak yang mapan
        // sekaligus memberi tutor baru kesempatan yang wajar.
        return (int) round((($average * $count) + (4.0 * 5)) / ($count + 5) * 1000);
    }

    private function responseReliabilityScore(User $teacher): int
    {
        $total = (int) ($teacher->matching_offer_total_count ?? 0);
        if ($total < 3) {
            return 500;
        }

        return (int) round(
            ((int) ($teacher->matching_offer_responded_count ?? 0) / max(1, $total)) * 1000
        );
    }

    private function applyNoResponseRestriction(TeacherOffer $offer): void
    {
        $alreadyCountedForRequest = TeacherOffer::query()
            ->where('booking_request_id', $offer->booking_request_id)
            ->where('teacher_id', $offer->teacher_id)
            ->whereKeyNot($offer->id)
            ->where('status', 'expired')
            ->where('no_response_penalty_applied', true)
            ->exists();
        if ($alreadyCountedForRequest) {
            return;
        }

        $result = DB::transaction(function () use ($offer) {
            $profile = TeacherProfile::query()
                ->where('user_id', $offer->teacher_id)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                return null;
            }

            $windowExpired = ! $profile->no_response_window_started_at
                || $profile->no_response_window_started_at->lt(now()->subDays(30));
            $streak = $windowExpired ? 1 : ((int) $profile->no_response_streak + 1);
            $hours = match (true) {
                $streak >= 5 => 24,
                $streak === 4 => 12,
                $streak === 3 => 6,
                $streak === 2 => 3,
                default => 0,
            };

            $profile->update([
                'no_response_streak' => $streak,
                'no_response_window_started_at' => $windowExpired
                    ? now()
                    : $profile->no_response_window_started_at,
                'suspended_until' => $hours > 0 ? now()->addHours($hours) : null,
            ]);

            return ['streak' => $streak, 'hours' => $hours];
        });

        if (! $result) {
            return;
        }

        if ($result['hours'] === 0) {
            return;
        }

        Notification::create([
            'user_id' => $offer->teacher_id,
            'title' => 'Penerimaan murid dibatasi',
            'message' => "Permintaan tidak dijawab. Penawaran baru dihentikan selama {$result['hours']} jam.",
            'type' => 'warning',
            'target_url' => '/guru/permintaan',
        ]);
    }

    private function matchingFailureContext(BookingRequest $bookingRequest, Collection $alreadyOffered): array
    {
        $modeColumn = $bookingRequest->learning_mode === 'offline' ? 'is_offline' : 'is_online';
        $classTypeColumn = 'is_private_active';

        $qualified = User::query()
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->whereHas('teacherProfile', function ($query) {
                $query->whereNotNull('verified_at')
                    ->where('is_accepting_requests', true)
                    ->where(fn ($cooldown) => $cooldown->whereNull('suspended_until')->orWhere('suspended_until', '<=', now()));
            })
            ->whereHas('teacherProfile.subjects', function ($query) use ($bookingRequest, $modeColumn, $classTypeColumn) {
                $query->where('name', $bookingRequest->subject_name)
                    ->where('is_active', true)
                    ->where($modeColumn, true)
                    ->where($classTypeColumn, true)
                    ->where(function ($levels) use ($bookingRequest) {
                        $levels->whereNull('levels')->orWhereJsonContains('levels', $bookingRequest->education_level);
                    });
            })
            ->with(['teacherProfile', 'availabilities'])
            ->limit(500)
            ->get();

        if ($qualified->isEmpty()) {
            return [
                'code' => 'no_qualified_tutor',
                'message' => "Belum ada tutor aktif yang sesuai untuk {$bookingRequest->subject_name}, jenjang {$bookingRequest->education_level}, dan mode belajar ini. Kamu dapat mengubah jadwal atau membatalkan pesanan.",
                'recommended_action' => 'change_schedule',
            ];
        }

        $withinScope = $qualified;
        if ($bookingRequest->learning_mode === 'offline') {
            $radius = max(1, min(12, (int) $bookingRequest->search_radius_km));
            $withinScope = $qualified->filter(function (User $teacher) use ($bookingRequest, $radius) {
                $profile = $teacher->teacherProfile;
                if ($bookingRequest->latitude === null || $bookingRequest->longitude === null || $profile?->latitude === null || $profile?->longitude === null) {
                    return false;
                }
                $distance = $this->distanceKm(
                    (float) $bookingRequest->latitude,
                    (float) $bookingRequest->longitude,
                    (float) $profile->latitude,
                    (float) $profile->longitude
                );

                return $distance <= $radius && (! $profile->max_travel_km || $distance <= (int) $profile->max_travel_km);
            })->values();

            if ($withinScope->isEmpty()) {
                return [
                    'code' => $radius < 12 ? 'radius_exhausted' : 'radius_max_exhausted',
                    'message' => $radius < 12
                        ? "Belum ada tutor yang memenuhi syarat dalam radius {$radius} km. Kamu masih dapat memperluas jangkauan pencarian."
                        : 'Radius maksimum 12 km sudah dicapai dan belum ada tutor yang memenuhi lokasi ini. Coba ubah jadwal atau batalkan pesanan jika tidak ingin menunggu.',
                    'recommended_action' => $radius < 12 ? 'expand_radius' : 'change_schedule',
                ];
            }
        }

        $scheduleReady = $withinScope->filter(fn (User $teacher) => $this->teacherCanCoverRequestSchedule($teacher, $bookingRequest))->values();
        if ($scheduleReady->isEmpty()) {
            return [
                'code' => 'schedule_unavailable',
                'message' => "Ada tutor yang sesuai untuk {$bookingRequest->subject_name}, tetapi belum ada yang tersedia untuk seluruh jadwal yang kamu pilih. Mengubah hari atau jam belajar dapat membuka kandidat baru.",
                'recommended_action' => 'change_schedule',
            ];
        }

        if ($scheduleReady->every(fn (User $teacher) => $alreadyOffered->contains($teacher->id))) {
            return [
                'code' => 'candidates_exhausted',
                'message' => 'Semua tutor yang cocok untuk jadwal ini sudah pernah mendapat penawaran dan belum ada yang menerima. Kamu dapat mengecek tutor baru sekali lagi, mengubah jadwal, atau membatalkan pesanan.',
                'recommended_action' => 'retry_once',
            ];
        }

        return [
            'code' => 'no_current_candidate',
            'message' => 'Belum ada tutor yang dapat menerima permintaan ini saat ini. Kamu dapat mencoba pencarian sekali lagi atau mengubah jadwal.',
            'recommended_action' => 'retry_once',
        ];
    }

    private function updateRequestGroup(
        BookingRequest $bookingRequest,
        array $attributes,
        bool $incrementAttempts = false
    ): void {
        $query = BookingRequest::query()
            ->whereKey($bookingRequest->id)
            ->whereIn('status', ['matching', 'teacher_pending', 'no_teacher']);

        if ($incrementAttempts) {
            (clone $query)->increment('matching_attempts');
        }

        $query->update($attributes);

        if (isset($attributes['status'])) {
            $this->syncPackageMatchingStatus($bookingRequest->refresh());
        }

        if ($bookingRequest->teacher_replacement_request_id && isset($attributes['status'])) {
            $replacementStatus = match ($attributes['status']) {
                'teacher_pending' => 'teacher_pending',
                'no_teacher' => 'no_teacher',
                default => 'matching',
            };
            TeacherReplacementRequest::query()
                ->whereKey($bookingRequest->teacher_replacement_request_id)
                ->whereIn('status', TeacherReplacementState::sourcesFor($replacementStatus))
                ->update([
                    'status' => $replacementStatus,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
        }
    }

    public function syncPackageMatchingStatus(BookingRequest $bookingRequest): void
    {
        if (! $bookingRequest->package_subject_id) {
            return;
        }
        if (! in_array($bookingRequest->status, ['matching', 'teacher_pending', 'no_teacher'], true)) {
            return;
        }

        $subject = PackageSubject::query()->with('package')->find($bookingRequest->package_subject_id);
        $package = $subject?->package;
        if (! $subject || ! $package) {
            return;
        }
        if (! in_array($package->status, ['matching', 'teacher_pending', 'no_teacher'], true)) {
            return;
        }

        $subject->update(['status' => $bookingRequest->status]);
        $this->syncPackageStatus($package);
    }

    public function syncPackageStatus(LearningPackage $package): string
    {
        $statuses = PackageSubject::query()
            ->where('learning_package_id', $package->id)
            ->pluck('status');

        $status = $statuses->contains('no_teacher')
            ? 'no_teacher'
            : ($statuses->contains('teacher_pending')
                ? 'teacher_pending'
                : ($statuses->contains('matching') ? 'matching' : $package->status));

        $package->update(['status' => $status]);

        return $status;
    }

    public function teacherResponseMinutes(?BookingRequest $bookingRequest = null): int
    {
        $isOffline = $bookingRequest?->learning_mode === 'offline';
        $settingKey = $isOffline
            ? 'teacher_response_offline_minutes'
            : 'teacher_response_online_minutes';
        $defaultMinutes = 60;

        return max(
            5,
            min(240, (int) (Setting::where('key', $settingKey)->value('value') ?? $defaultMinutes))
        );
    }

    public function maximumSearchHours(): int
    {
        // Existing callers still set search_expires_at in whole hours. A
        // strict 60-minute window therefore rounds to exactly one hour and no
        // longer follows the legacy 12/24-hour setting.
        return max(1, (int) ceil($this->searchWindowMinutes() / 60));
    }

    public function matchingCutoffHours(): int
    {
        return min(12, max(1, (int) (Setting::where('key', 'matching_cutoff_hours')->value('value') ?? 2)));
    }

    public function offerWaveSize(?BookingRequest $bookingRequest = null): int
    {
        // Single-session bookings stay one-at-a-time. Package purchases are
        // broadcast to a small pool so the 60-minute window is not spent
        // waiting on one tutor. acceptPackageOffer() locks the request and
        // cancels the remaining offers, so only one tutor can still win.
        if (! $bookingRequest?->package_subject_id) {
            return 1;
        }

        $configured = (int) (Setting::where('key', 'matching_offer_wave_size')->value('value') ?? 3);

        return max(1, min(5, $configured));
    }

    private function offerRetryCooldownMinutes(): int
    {
        return min(720, max(30, (int) (Setting::where('key', 'teacher_offer_retry_cooldown_minutes')->value('value') ?? 120)));
    }

    private function waitingRetryMinutes(): int
    {
        return min(360, max(5, (int) (Setting::where('key', 'matching_wait_retry_minutes')->value('value') ?? 30)));
    }

    private function indonesianDayName(int $isoDay): string
    {
        return [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'][$isoDay];
    }

    private function activeRequestIds(BookingRequest $bookingRequest): Collection
    {
        return collect([$bookingRequest->id]);
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
}
