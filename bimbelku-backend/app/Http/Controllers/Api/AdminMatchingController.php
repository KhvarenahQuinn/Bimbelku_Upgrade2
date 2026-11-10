<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Models\Setting;
use App\Models\TeacherOffer;
use App\Models\User;
use App\Services\TeacherAssignmentService;
use App\Services\TeacherMatchingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminMatchingController extends Controller
{
    private const ACTIVE_STATUSES = ['matching', 'teacher_pending', 'no_teacher'];

    private const HISTORY_STATUSES = ['expired'];

    private const MONITORED_STATUSES = [...self::ACTIVE_STATUSES, ...self::HISTORY_STATUSES];

    public function index(Request $request, TeacherMatchingService $matchingService)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_merge(['all', 'attention'], self::MONITORED_STATUSES))],
            'scope' => ['nullable', Rule::in(['active', 'history'])],
            'learning_mode' => ['nullable', Rule::in(['all', 'online', 'offline'])],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
        ]);

        $status = $validated['status'] ?? 'all';
        $scope = $validated['scope'] ?? 'active';
        $mode = $validated['learning_mode'] ?? 'all';
        $search = trim((string) ($validated['q'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = BookingRequest::query()
            ->whereNotNull('package_subject_id')
            ->when($scope === 'history', fn ($history) => $history->whereIn('status', self::HISTORY_STATUSES))
            ->when($scope === 'active', fn ($active) => $active->where(function (Builder $visible) {
                $visible->where(function (Builder $upcoming) {
                    $upcoming->whereIn('status', self::ACTIVE_STATUSES)
                        ->whereDate('scheduled_date', '>=', today());
                })->orWhere(fn (Builder $attention) => $attention->matchingNeedsAttention());
            }))
            ->matchingAnchors()
            ->with([
                'student:id,name,email',
                'matchedTeacher:id,name,email',
                'offers' => fn ($offers) => $offers
                    ->with('teacher:id,name,email')
                    ->latest('offered_at'),
                'packageSubject.package:id,student_id,status',
            ]);

        if ($status === 'attention') {
            $this->applyAttentionFilter($query);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($mode !== 'all') {
            $query->where('learning_mode', $mode);
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('subject_name', 'like', "%{$search}%")
                    ->orWhere('education_level', 'like', "%{$search}%")
                    ->orWhere('grade', 'like', "%{$search}%")
                    ->orWhereHas('student', function (Builder $students) use ($search) {
                        $students
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('matchedTeacher', function (Builder $teachers) use ($search) {
                        $teachers
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $paginator = $query
            ->orderByRaw("CASE status WHEN 'no_teacher' THEN 0 WHEN 'teacher_pending' THEN 1 WHEN 'matching' THEN 2 ELSE 3 END")
            ->orderByRaw('CASE WHEN teacher_response_deadline IS NOT NULL AND teacher_response_deadline <= ? THEN 0 ELSE 1 END', [now()])
            ->orderBy('search_started_at')
            ->orderBy('id')
            ->paginate($perPage);

        $summary = $this->summaryCounts();
        $items = collect($paginator->items())
            ->map(fn (BookingRequest $bookingRequest) => $this->formatSummary($bookingRequest, $matchingService))
            ->values();

        return response()->json([
            'data' => $items,
            'summary' => $summary,
            'system_health' => $this->systemHealth(),
            'scope' => $scope,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(
        BookingRequest $bookingRequest,
        TeacherMatchingService $matchingService,
        TeacherAssignmentService $assignmentService
    ) {
        abort_unless(in_array($bookingRequest->status, self::MONITORED_STATUSES, true), 404);

        $bookingRequest->load([
            'student:id,name,email',
            'matchedTeacher:id,name,email',
            'offers' => fn ($offers) => $offers
                ->with(['teacher:id,name,email', 'teacher.teacherProfile:user_id,max_travel_km', 'teacher.ratings:id,teacher_id,rating'])
                ->latest('offered_at'),
            'packageSubject.package:id,student_id,status,total_sessions,used_sessions',
            'matchingOperationLogs' => fn ($logs) => $logs
                ->with('actor:id,name,email')
                ->latest('created_at'),
        ]);

        return response()->json([
            'data' => array_merge(
                $this->formatSummary($bookingRequest, $matchingService),
                [
                    'available_candidate_count' => $matchingService->availableCandidateCount($bookingRequest),
                    // "Kandidat otomatis" must include tutors who already
                    // received an offer. Relying on available candidates alone
                    // made a dispatched wave look like zero candidates.
                    'automatic_candidate_count' => $this->automaticCandidateCount($bookingRequest, $matchingService),
                    'manual_candidate_count' => $assignmentService
                        ->candidateSummaries($bookingRequest)
                        ->count(),
                    'operation_history' => $bookingRequest->matchingOperationLogs
                        ->map(fn ($log) => [
                            'id' => $log->id,
                            'action' => $log->action,
                            'action_label' => $this->operationLabel($log->action),
                            'reason' => $log->reason,
                            'before_state' => $log->before_state,
                            'after_state' => $log->after_state,
                            'metadata' => $log->metadata,
                            'actor' => $log->actor ? [
                                'id' => $log->actor->id,
                                'name' => $log->actor->name,
                                'email' => $log->actor->email,
                            ] : null,
                            'created_at' => $log->created_at?->toIso8601String(),
                        ])
                        ->values(),
                    'schedule' => [
                        'date' => $bookingRequest->scheduled_date?->toDateString(),
                        'start_time' => $bookingRequest->start_time,
                        'end_time' => $bookingRequest->end_time,
                        'duration_hours' => (int) $bookingRequest->duration_hours,
                    ],
                    'request_details' => [
                        'chapter' => $bookingRequest->chapter,
                        'learning_goal' => $bookingRequest->learning_goal,
                        'address_available' => filled($bookingRequest->address),
                        'coordinates_available' => $bookingRequest->latitude !== null
                            && $bookingRequest->longitude !== null,
                    ],
                    'offer_history' => $bookingRequest->offers
                        ->map(fn (TeacherOffer $offer) => [
                            'id' => $offer->id,
                            'teacher' => [
                                'id' => $offer->teacher?->id,
                                'name' => $offer->teacher?->name ?? 'Tutor tidak tersedia',
                                'email' => $offer->teacher?->email,
                                'rating_average' => round((float) ($offer->teacher?->ratings?->avg('rating') ?? 0), 1),
                                'rating_count' => (int) ($offer->teacher?->ratings?->count() ?? 0),
                                'max_travel_km' => $offer->teacher?->teacherProfile?->max_travel_km,
                            ],
                            'status' => $offer->status,
                            'status_label' => $this->offerStatusLabel($offer->status),
                            'distance_km' => $offer->distance_km,
                            'offered_at' => $offer->offered_at?->toIso8601String(),
                            'expires_at' => $offer->expires_at?->toIso8601String(),
                            'responded_at' => $offer->responded_at?->toIso8601String(),
                            'rejection_reason' => $offer->rejection_reason,
                            'is_overdue' => $offer->status === 'pending'
                                && $offer->expires_at?->isPast(),
                        ])
                        ->values(),
                ]
            ),
        ]);
    }

    public function candidates(
        Request $request,
        BookingRequest $bookingRequest,
        TeacherAssignmentService $assignmentService
    ) {
        abort_unless(in_array($bookingRequest->status, self::ACTIVE_STATUSES, true), 404);
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $candidates = $assignmentService->candidateSummaries(
            $bookingRequest,
            (string) ($validated['q'] ?? '')
        );

        return response()->json([
            'data' => $candidates,
            'count' => $candidates->count(),
        ]);
    }

    public function expandRadius(
        Request $request,
        BookingRequest $bookingRequest,
        TeacherMatchingService $matchingService
    ) {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $nextRadius = $matchingService->expandRadius(
            $bookingRequest,
            $request->user(),
            $validated['reason'],
            'admin'
        );
        $matchingService->dispatchNextOffer($bookingRequest->fresh());

        return response()->json([
            'message' => "Radius pencarian diperluas menjadi {$nextRadius} km dan mesin pencocokan dijalankan kembali.",
            'radius' => $nextRadius,
        ]);
    }

    public function assignTeacher(
        Request $request,
        BookingRequest $bookingRequest,
        TeacherAssignmentService $assignmentService
    ) {
        $validated = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirmed_teacher_consent' => ['accepted'],
        ]);

        $teacher = User::query()->findOrFail($validated['teacher_id']);
        $result = $assignmentService->assignManually(
            $bookingRequest,
            $teacher,
            $request->user(),
            $validated['reason']
        );

        $assignmentType = $result['assignment']['type'] ?? null;
        $isPackage = $assignmentType === 'package';
        $isReplacement = $assignmentType === 'teacher_replacement';

        return response()->json([
            'message' => $isReplacement
                ? 'Tutor pengganti ditetapkan. Hanya sesi paket yang tersisa yang dialihkan.'
                : ($isPackage
                ? 'Tutor ditetapkan untuk mata pelajaran paket. Status paket diperbarui sesuai tutor yang sudah ditemukan.'
                : 'Tutor ditetapkan. Tagihan murid sudah dibuka dan penawaran lain dibatalkan.'),
            'data' => [
                'booking_request_id' => $result['booking_request']->id,
                'teacher' => [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                ],
                'assignment_type' => $result['assignment']['type'] ?? 'single',
            ],
        ]);
    }

    public function synchronize(
        BookingRequest $bookingRequest,
        TeacherMatchingService $matchingService
    ) {
        abort_unless(in_array($bookingRequest->status, ['matching', 'teacher_pending'], true), 422, 'Pencarian ini tidak dapat disinkronkan pada status sekarang.');

        $freshRequest = $bookingRequest->fresh();
        $activeOffer = $freshRequest->offers()
            ->where('status', 'pending')
            ->latest('offered_at')
            ->first();

        if ($activeOffer && $activeOffer->expires_at->isFuture()) {
            abort(422, 'Tutor saat ini masih memiliki waktu untuk menjawab penawaran.');
        }

        if ($activeOffer) {
            $matchingService->expireOfferAndContinue($activeOffer);
            $message = 'Penawaran yang melewati batas waktu ditutup dan pencarian dilanjutkan pada radius yang sama.';
        } else {
            $matchingService->dispatchNextOffer($freshRequest);
            $message = 'Status pencarian diperiksa dan mesin pencocokan dijalankan kembali pada radius yang sama.';
        }

        $freshRequest = $bookingRequest->fresh();

        return response()->json([
            'message' => $message,
            'data' => $this->formatSummary($freshRequest->fresh([
                'student:id,name,email',
                'matchedTeacher:id,name,email',
                'offers' => fn ($offers) => $offers
                    ->with('teacher:id,name,email')
                    ->latest('offered_at'),
                'packageSubject.package:id,student_id,status',
            ]), $matchingService),
        ]);
    }

    private function summaryCounts(): array
    {
        $base = BookingRequest::query()
            ->whereNotNull('package_subject_id')
            ->whereIn('status', self::MONITORED_STATUSES)
            ->matchingAnchors();

        return [
            'all' => (clone $base)->count(),
            'matching' => (clone $base)->where('status', 'matching')->count(),
            'teacher_pending' => (clone $base)->where('status', 'teacher_pending')->count(),
            'no_teacher' => (clone $base)->where('status', 'no_teacher')->count(),
            'expired' => (clone $base)->where('status', 'expired')->count(),
            'attention' => tap(clone $base, fn (Builder $query) => $this->applyAttentionFilter($query))->count(),
        ];
    }

    private function applyAttentionFilter(Builder $query): void
    {
        $query->matchingNeedsAttention();
    }

    private function formatSummary(BookingRequest $bookingRequest, TeacherMatchingService $matchingService): array
    {
        $activeOffers = $bookingRequest->offers
            ->filter(fn (TeacherOffer $offer) => $offer->status === 'pending' && $offer->expires_at?->isFuture())
            ->values();
        $activeOffer = $bookingRequest->offers
            ->first(fn (TeacherOffer $offer) => $offer->status === 'pending');
        $startedAt = $matchingService->searchStartedAt($bookingRequest);
        $searchDeadline = $matchingService->searchDeadline($bookingRequest);
        $searchAgeSeconds = $matchingService->searchAgeSeconds($bookingRequest);
        $attention = $this->attentionReason($bookingRequest, $activeOffer, $searchDeadline);

        return [
            'id' => $bookingRequest->id,
            'student' => [
                'id' => $bookingRequest->student?->id,
                'name' => $bookingRequest->student?->name ?? 'Murid tidak tersedia',
                'email' => $bookingRequest->student?->email,
            ],
            'subject_name' => $bookingRequest->subject_name,
            'education_level' => $bookingRequest->education_level,
            'grade' => $bookingRequest->grade,
            'learning_mode' => $bookingRequest->learning_mode,
            'class_type' => 'private',
            'scheduled_at' => $this->scheduledAt($bookingRequest)?->toIso8601String(),
            'status' => $bookingRequest->status,
            'status_label' => $this->statusLabel($bookingRequest->status),
            'status_description' => $this->statusDescription($bookingRequest),
            'search_radius_km' => $bookingRequest->learning_mode === 'offline' ? (int) $bookingRequest->search_radius_km : null,
            'matching_attempts' => (int) $bookingRequest->matching_attempts,
            'search_started_at' => $startedAt->toIso8601String(),
            'search_expires_at' => $searchDeadline->toIso8601String(),
            'search_window_minutes' => $matchingService->searchWindowMinutes(),
            'teacher_response_deadline' => $bookingRequest->teacher_response_deadline?->toIso8601String(),
            'next_matching_at' => $bookingRequest->next_matching_at?->toIso8601String(),
            'active_offer_count' => $activeOffers->count(),
            // Active-search duration is capped by the same one-hour window the
            // engine enforces, so old multi-day rows cannot render "2 hari".
            'search_age_minutes' => round($searchAgeSeconds / 60, 2),
            'search_age_seconds' => $searchAgeSeconds,
            'search_remaining_seconds' => $searchDeadline->isFuture()
                ? max(0, (int) now()->diffInSeconds($searchDeadline))
                : 0,
            'needs_attention' => $attention !== null,
            'attention_reason' => $attention,
            'can_synchronize' => $bookingRequest->status === 'matching'
                || (
                    $bookingRequest->status === 'teacher_pending'
                    && $bookingRequest->teacher_response_deadline?->isPast()
                ),
            'next_radius_km' => $bookingRequest->learning_mode === 'offline'
                ? $this->nextRadiusValue((int) $bookingRequest->search_radius_km)
                : null,
            'can_expand_radius' => $bookingRequest->learning_mode === 'offline'
                && $bookingRequest->status === 'no_teacher'
                && $this->nextRadiusValue((int) $bookingRequest->search_radius_km) !== null
                && ($this->scheduledAt($bookingRequest)?->isFuture() ?? false),
            'can_assign_manually' => in_array($bookingRequest->status, self::ACTIVE_STATUSES, true)
                && ($this->scheduledAt($bookingRequest)?->isFuture() ?? false),
            'matched_teacher' => $bookingRequest->matchedTeacher ? [
                'id' => $bookingRequest->matchedTeacher->id,
                'name' => $bookingRequest->matchedTeacher->name,
                'email' => $bookingRequest->matchedTeacher->email,
            ] : null,
            'active_offer' => $activeOffer ? [
                'id' => $activeOffer->id,
                'teacher_id' => $activeOffer->teacher_id,
                'teacher_name' => $activeOffer->teacher?->name ?? 'Tutor',
                'distance_km' => $activeOffer->distance_km,
                'offered_at' => $activeOffer->offered_at?->toIso8601String(),
                'expires_at' => $activeOffer->expires_at?->toIso8601String(),
                'is_overdue' => $activeOffer->expires_at?->isPast() ?? false,
            ] : null,
            'active_offers' => $activeOffers->map(fn (TeacherOffer $offer) => [
                'id' => $offer->id,
                'teacher_id' => $offer->teacher_id,
                'teacher_name' => $offer->teacher?->name ?? 'Tutor',
                'distance_km' => $offer->distance_km,
                'offered_at' => $offer->offered_at?->toIso8601String(),
                'expires_at' => $offer->expires_at?->toIso8601String(),
                'is_overdue' => false,
            ])->values(),
            'offer_totals' => [
                'total' => $bookingRequest->offers->count(),
                'rejected' => $bookingRequest->offers->where('status', 'rejected')->count(),
                'expired' => $bookingRequest->offers->where('status', 'expired')->count(),
                'accepted' => $bookingRequest->offers->where('status', 'accepted')->count(),
            ],
            'source' => [
                'type' => 'package',
                'label' => 'Paket Belajar',
                'is_legacy' => false,
                'package_id' => $bookingRequest->packageSubject?->learning_package_id,
                'package_status' => $bookingRequest->packageSubject?->package?->status,
            ],
        ];
    }

    private function automaticCandidateCount(
        BookingRequest $bookingRequest,
        TeacherMatchingService $matchingService
    ): int {
        $offeredTeacherCount = $bookingRequest->relationLoaded('offers')
            ? $bookingRequest->offers->pluck('teacher_id')->filter()->unique()->count()
            : $bookingRequest->offers()->distinct()->count('teacher_id');

        // availableCandidateCount() excludes every tutor who has ever received
        // an offer, so adding the two values counts each automatic candidate
        // once while still reflecting the dispatched wave.
        return $offeredTeacherCount + $matchingService->availableCandidateCount($bookingRequest);
    }

    private function systemHealth(): array
    {
        $value = Setting::query()
            ->where('key', 'booking_workflow_last_heartbeat_at')
            ->value('value');
        try {
            $heartbeat = $value ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            $heartbeat = null;
        }

        return [
            'scheduler_healthy' => $heartbeat?->gte(now()->subMinutes(3)) ?? false,
            'last_heartbeat_at' => $heartbeat?->toIso8601String(),
        ];
    }

    private function attentionReason(
        BookingRequest $bookingRequest,
        ?TeacherOffer $activeOffer,
        ?Carbon $searchDeadline = null
    ): ?string {
        if ($bookingRequest->status === 'no_teacher') {
            return $bookingRequest->learning_mode === 'offline'
                ? "Belum ada tutor yang cocok pada radius {$bookingRequest->search_radius_km} km."
                : 'Belum ada tutor online yang cocok pada jadwal dan kebutuhan ini.';
        }

        if ($bookingRequest->status === 'expired') {
            return 'Batas pencarian atau waktu mulai kelas telah terlewati.';
        }

        $searching = in_array($bookingRequest->status, ['matching', 'teacher_pending'], true);
        $windowExpired = $searchDeadline?->isPast() ?? false;

        // The 60-minute window owns the search. While it is still running the
        // engine may dispatch another wave, so an expired offer on its own is
        // not an admin problem yet.
        if ($searching && ! $windowExpired) {
            return null;
        }

        if (
            ($bookingRequest->status === 'teacher_pending' && $bookingRequest->teacher_response_deadline?->lte(now()))
            || ($activeOffer?->status === 'pending' && $activeOffer->expires_at?->lte(now()))
        ) {
            return 'Batas jawaban tutor telah terlewati dan perlu disinkronkan.';
        }

        if ($searching) {
            return 'Batas pencarian sudah terlewati dan perlu disinkronkan.';
        }

        return null;
    }

    private function statusDescription(BookingRequest $bookingRequest): string
    {
        return match ($bookingRequest->status) {
            'matching' => 'Mesin sedang mencari kandidat pada radius saat ini.',
            'teacher_pending' => 'Penawaran sudah dikirim dan sedang menunggu jawaban tutor.',
            'no_teacher' => 'Semua kandidat yang memenuhi syarat pada radius ini sudah habis atau tidak tersedia.',
            'expired' => 'Pencarian berhenti karena batas waktu pencarian atau jadwal kelas terlewati.',
            default => 'Status pencarian tidak dikenali.',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'matching' => 'Mencari tutor',
            'teacher_pending' => 'Menunggu tutor',
            'no_teacher' => 'Tutor belum ditemukan',
            'expired' => 'Pencarian berakhir',
            default => $status,
        };
    }

    private function offerStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Menunggu jawaban',
            'accepted' => 'Diterima',
            'rejected' => 'Ditolak',
            'expired' => 'Tidak dijawab',
            'cancelled' => 'Dibatalkan sistem',
            default => $status,
        };
    }

    private function nextRadiusValue(int $currentRadius): ?int
    {
        return match ($currentRadius) {
            3 => 5,
            5 => 8,
            8 => 12,
            default => null,
        };
    }

    private function operationLabel(string $action): string
    {
        return match ($action) {
            'radius_expanded' => 'Radius diperluas',
            'teacher_assigned_manually' => 'Tutor ditetapkan manual',
            'matching_started' => 'Pencarian dimulai',
            'offers_dispatched' => 'Penawaran tutor dikirim',
            'offer_expired' => 'Penawaran tutor kedaluwarsa',
            'waiting_for_availability' => 'Menunggu ketersediaan tutor',
            'matching_exhausted' => 'Batas pencarian berakhir',
            'schedule_changed' => 'Jadwal diubah',
            'search_restarted' => 'Pencarian diulang',
            default => $action,
        };
    }

    private function scheduledAt(BookingRequest $bookingRequest): ?Carbon
    {
        if (! $bookingRequest->scheduled_date || ! $bookingRequest->start_time) {
            return null;
        }

        return Carbon::parse(
            $bookingRequest->scheduled_date->format('Y-m-d').' '.$bookingRequest->start_time,
            config('app.timezone', 'Asia/Jakarta')
        );
    }
}
