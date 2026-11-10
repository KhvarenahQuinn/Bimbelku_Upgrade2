<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingDispute;
use App\Models\BookingRequest;
use App\Models\ClassroomMessage;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\LearningPackage;
use App\Models\LearningTimeSlot;
use App\Models\MatchingOperationLog;
use App\Models\Notification;
use App\Models\PackageChapter;
use App\Models\PackagePlan;
use App\Models\PackageRenewal;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\TeacherOffer;
use App\Models\TeacherReplacementRequest;
use App\Models\User;
use App\Services\HourlyRateService;
use App\Services\LearningProgramSelection;
use App\Services\PackageCheckoutService;
use App\Services\PackageEarningsAllocation;
use App\Services\PrivateParticipantPricing;
use App\Services\RefundService;
use App\Services\RefundSettlementService;
use App\Services\ScheduleReleaseService;
use App\Services\TeacherMatchingService;
use App\Support\EducationCatalog;
use App\Support\LearningFocus;
use App\Support\PackageChapterProgress;
use App\Support\PublicMedia;
use App\Support\RefundStatusPresenter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentPackageController extends Controller
{
    public function plans(Request $request)
    {
        $plans = Cache::remember('package_plans.active.v2', now()->addMinute(), fn () => PackagePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('session_count')
            ->get()
        );

        return $this->publicCachedResponse($request, $plans, 60);
    }

    public function timeSlots(Request $request)
    {
        $timeSlots = Cache::remember('learning_time_slots.full_hour.v2', now()->addMinute(), fn () => LearningTimeSlot::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get()
            ->filter(fn (LearningTimeSlot $slot) => substr((string) $slot->start_time, 3, 2) === '00'
                && substr((string) $slot->start_time, 0, 5) < '23:00')
            ->values()
        );

        return $this->publicCachedResponse($request, $timeSlots, 60);
    }

    public function bookingRules(Request $request, TeacherMatchingService $matchingService)
    {
        $keys = [
            'booking_lead_hours',
            'renewal_booking_lead_hours',
            'maximum_search_hours',
            'matching_cutoff_hours',
            'teacher_response_minutes',
        ];

        $values = Cache::remember('package_booking_rules.v1', now()->addMinute(), fn () => Setting::query()->whereIn('key', $keys)->pluck('value', 'key'));

        return response()->json([
            'booking_lead_hours' => (int) ($values['booking_lead_hours'] ?? 24),
            'renewal_booking_lead_hours' => (int) ($values['renewal_booking_lead_hours'] ?? 12),
            'maximum_search_hours' => $matchingService->maximumSearchHours(),
            'maximum_search_minutes' => $matchingService->searchWindowMinutes(),
            'matching_cutoff_hours' => (int) ($values['matching_cutoff_hours'] ?? 2),
            'teacher_response_minutes' => (int) ($values['teacher_response_minutes'] ?? 30),
        ]);
    }

    public function index(Request $request)
    {
        $scope = $request->query('scope');
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        $packages = LearningPackage::query()
            ->where('student_id', $request->user()->id)
            ->when($scope === 'history', function ($query) {
                $query->where(function ($history) {
                    $history->whereIn('status', ['completed', 'cancelled', 'payment_expired'])
                        ->orWhere(function ($refunded) {
                            $refunded->where('status', 'refund_pending')
                                ->whereHas('orders', fn ($orders) => $orders->where('status', 'refunded'));
                        });
                });
            })
            ->when($scope === 'active', function ($query) {
                $query->whereNotIn('status', ['completed', 'cancelled', 'payment_expired'])
                    ->where(function ($active) {
                        $active->where('status', '!=', 'refund_pending')
                            ->orWhereDoesntHave('orders', fn ($orders) => $orders->where('status', 'refunded'));
                    });
            })
            ->with([
                'plan',
                'promotion',
                'subjects.assignedTeacher.teacherProfile',
                'subjects.chapters',
                'subjects.sessions.booking.teacher.teacherProfile',
                'subjects.latestTeacherReplacement.sessions.packageSession',
                'subjects.bookingRequest.offers',
                'subjects.bookingRequest.matchingOperationLogs',
                'subjects.bookingRequest.latestMatchingExhaustion',
                'orders' => fn ($query) => $query->latest(),
            ])
            ->latest()
            ->paginate($perPage);

        $packages->getCollection()->transform(
            fn (LearningPackage $package) => $this->formatPackage($package)
        );

        return response()->json($packages);
    }

    public function show(Request $request, LearningPackage $learningPackage)
    {
        abort_unless($learningPackage->student_id === $request->user()->id, 403);
        $learningPackage->load([
            'plan',
            'promotion',
            'subjects.assignedTeacher.teacherProfile',
            'subjects.chapters',
            'subjects.sessions.booking.teacher.teacherProfile',
            'subjects.latestTeacherReplacement.sessions.packageSession',
            'subjects.bookingRequest.offers',
            'subjects.bookingRequest.matchingOperationLogs',
            'subjects.bookingRequest.latestMatchingExhaustion',
            'orders' => fn ($query) => $query->latest(),
        ]);

        return response()->json($this->formatPackage($learningPackage));
    }

    public function dashboard(Request $request)
    {
        $studentId = $request->user()->id;
        $packages = LearningPackage::query()
            ->where('student_id', $studentId)
            ->whereNotIn('status', ['cancelled', 'payment_expired', 'completed'])
            ->with([
                'plan',
                'promotion',
                'orders' => fn ($query) => $query->latest(),
                'subjects.assignedTeacher.teacherProfile',
                'subjects.chapters',
                'subjects.sessions.booking.teacher.teacherProfile',
                'subjects.latestTeacherReplacement.sessions.packageSession',
                'subjects.bookingRequest.offers',
                'subjects.bookingRequest.matchingOperationLogs',
                'subjects.bookingRequest.latestMatchingExhaustion',
            ])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (LearningPackage $package) => $this->formatPackage($package));

        $nextBooking = Booking::query()
            ->where('student_id', $studentId)
            ->whereIn('status', ['confirmed', 'in_progress', 'awaiting_student_approval'])
            ->where('end_at', '>=', now())
            ->with(['teacher:id,name', 'bookingRequest:id,subject_name,chapter'])
            ->orderBy('start_at')
            ->first();

        $voucherCount = PromotionClaim::query()
            ->where('user_id', $studentId)
            ->where('status', 'available')
            ->whereHas('promotion', fn ($query) => $query
                ->where('is_active', true)
                ->where(fn ($dates) => $dates->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($dates) => $dates->whereNull('ends_at')->orWhere('ends_at', '>=', now())))
            ->count();
        $notifications = Notification::query()
            ->where('user_id', $studentId)
            ->latest()
            ->limit(5)
            ->get(['id', 'title', 'message', 'type', 'is_read', 'created_at']);
        $activeDisputes = BookingDispute::query()
            ->where('student_id', $studentId)
            ->whereNotIn('status', ['resolved', 'rejected', 'cancelled'])
            ->count();
        $unreadMessages = ClassroomMessage::query()
            ->where('message_type', '!=', 'system')->where('sender_id', '!=', $studentId)
            ->whereHas('booking', function ($bookings) use ($studentId) {
                $bookings
                    ->whereIn('status', [
                        'confirmed', 'in_progress', 'awaiting_student_approval', 'disputed',
                        'absence_review', 'admin_review_required', 'completed',
                    ])
                    ->whereHas('participants', fn ($participants) => $participants
                        ->where('student_id', $studentId)
                        ->whereHas('order', fn ($orders) => $orders->where('status', 'paid')));
            })
            ->whereDoesntHave('reads', fn ($reads) => $reads->where('user_id', $studentId))
            ->count();

        return response()->json([
            'packages' => $packages,
            'next_session' => $nextBooking ? [
                'id' => $nextBooking->id,
                'subject' => $nextBooking->bookingRequest?->subject_name,
                'chapter' => $nextBooking->bookingRequest?->chapter,
                'teacher_name' => $nextBooking->teacher?->name,
                'learning_mode' => $nextBooking->learning_mode,
                'status' => $nextBooking->status,
                'start_at' => $nextBooking->start_at,
                'end_at' => $nextBooking->end_at,
            ] : null,
            'voucher_count' => $voucherCount,
            'unread_messages_count' => $unreadMessages,
            'active_disputes_count' => $activeDisputes,
            'recent_notifications' => $notifications,
        ]);
    }

    public function tutorialStatus(Request $request)
    {
        $hasMultiSubjectPackage = LearningPackage::query()
            ->where('student_id', $request->user()->id)
            ->whereHas('subjects', null, '>=', 2)
            ->exists();

        return response()->json([
            'has_multi_subject_package' => $hasMultiSubjectPackage,
        ]);
    }

    public function store(
        Request $request,
        HourlyRateService $rateService,
        PackageCheckoutService $checkoutService,
        PrivateParticipantPricing $participantPricing
    ) {
        $maximumParticipants = $participantPricing->maximumParticipants();
        // Draf lama dapat membawa hari, bab, atau subbab yang sama lebih dari sekali.
        // Duplikasi dinormalisasi sebelum validasi tanpa mengubah jadwal pengguna.
        if (is_array($request->input('subjects'))) {
            $normalizedInputSubjects = collect($request->input('subjects'))->map(function ($item) {
                if (! is_array($item)) {
                    return $item;
                }

                foreach (['curriculum_chapter_ids', 'weekdays'] as $field) {
                    if (! is_array($item[$field] ?? null)) {
                        continue;
                    }

                    $item[$field] = collect($item[$field])
                        ->map(fn ($value) => is_numeric($value) ? (int) $value : $value)
                        ->uniqueStrict()
                        ->values()
                        ->all();
                }

                return $item;
            })->all();

            $request->merge(['subjects' => $normalizedInputSubjects]);
        }

        $validated = $request->validate([
            'package_plan_id' => ['required', 'integer', 'exists:package_plans,id'],
            'learning_program_id' => ['nullable', 'integer', 'exists:learning_programs,id'],
            'education_level' => ['required', Rule::in(EducationCatalog::LEVELS)],
            'grade' => ['required', 'string', 'max:50'],
            'learning_mode' => ['required', Rule::in(['online', 'offline'])],
            'duration_hours' => ['nullable', 'integer', Rule::in([1, 2])],
            'participant_count' => ['nullable', 'integer', 'between:1,'.$maximumParticipants],
            'purchaser_participates' => ['nullable', 'boolean'],
            'participant_details' => ['nullable', 'array', 'max:'.$maximumParticipants],
            'participant_details.*.full_name' => ['required', 'string', 'min:2', 'max:100'],
            'participant_details.*.nickname' => ['required', 'string', 'min:1', 'max:60'],
            'participant_details.*.birth_date' => ['required', 'date', 'before_or_equal:today'],
            'participant_details.*.gender' => ['required', Rule::in(['male', 'female'])],
            'promotion_code' => ['nullable', 'string', 'max:60'],
            'promotion_claim_id' => ['nullable', 'integer', 'exists:promotion_claims,id'],
            'renewal_of_id' => ['nullable', 'integer', 'exists:learning_packages,id'],
            'participant_names' => ['nullable', 'array', 'max:'.$maximumParticipants],
            'participant_names.*' => ['required', 'string', 'min:2', 'max:100'],
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*.curriculum_subject_id' => ['required', 'integer', 'distinct', 'exists:curriculum_subjects,id'],
            'subjects.*.curriculum_chapter_ids' => ['nullable', 'array', 'max:8'],
            'subjects.*.curriculum_chapter_ids.*' => ['integer', 'distinct', 'exists:curriculum_chapters,id'],
            'subjects.*.learning_goal' => ['nullable', 'string', 'max:1500'],
            'subjects.*.preferred_teacher_id' => ['nullable', 'integer', 'exists:users,id'],
            'subjects.*.weekdays' => ['required', 'array', 'min:1', 'max:4'],
            'subjects.*.weekdays.*' => ['required', 'integer', 'between:1,7'],
            'subjects.*.schedules' => ['required', 'array', 'min:1'],
            'subjects.*.schedules.*' => ['required', 'date'],
        ], [
            'subjects.*.curriculum_subject_id.distinct' => 'Satu mata pelajaran tidak boleh dipilih lebih dari sekali.',
            'subjects.*.curriculum_chapter_ids.*.distinct' => 'Bab yang sama tidak boleh dipilih lebih dari sekali.',
            'subjects.min' => 'Pilih minimal satu mata pelajaran.',
            'subjects.*.weekdays.min' => 'Pilih minimal satu hari belajar untuk setiap mapel.',
            'subjects.*.schedules.min' => 'Atur minimal satu jadwal belajar untuk setiap mapel.',
            'subjects.*.weekdays.max' => 'Setiap mata pelajaran hanya boleh memiliki maksimal 4 hari belajar.',
            'subjects.*.learning_goal.string' => 'Target atau materi belajar harus berupa teks.',
            'subjects.*.learning_goal.max' => 'Target atau materi belajar maksimal 1500 karakter per mapel.',
        ]);
        $validated['subjects'] = LearningFocus::normalizeSubjects($validated['subjects'], ! empty($validated['learning_program_id']), $validated['education_level'], $validated['grade']);
        abort_if(
            filled($validated['promotion_code'] ?? null) && filled($validated['promotion_claim_id'] ?? null),
            422,
            'Gunakan satu voucher atau satu kode promo.'
        );
        abort_unless(
            EducationCatalog::supports($validated['education_level'], $validated['grade']),
            422,
            'Kelas atau tingkat tidak sesuai jenjang.'
        );

        $student = $request->user();
        $participantCount = (int) ($validated['participant_count'] ?? 1);
        $participantPricing->forCount($participantCount);
        $purchaserParticipates = (bool) ($validated['purchaser_participates'] ?? true);
        $participantDetails = array_values($validated['participant_details'] ?? []);
        $expectedParticipantDetails = $participantCount - ($purchaserParticipates ? 1 : 0);
        abort_if(count($participantDetails) !== $expectedParticipantDetails, 422, 'Lengkapi identitas setiap peserta di luar pemesan.');
        $participantNames = collect($participantDetails)->pluck('full_name')->values();
        if ($purchaserParticipates) {
            $participantNames->prepend($student->name);
        }
        $participantNames = $participantNames->all();
        abort_if(! empty($validated['renewal_of_id']) && $participantCount > 1, 422, 'Perpanjangan multi-peserta belum tersedia; buat pesanan baru agar semua peserta dapat diperiksa.');
        $durationHours = (int) ($validated['duration_hours'] ?? 1);
        if ($validated['learning_mode'] === 'offline') {
            abort_if(
                blank($student->address) || $student->latitude === null || $student->longitude === null,
                422,
                'Alamat dan titik lokasi profil wajib dilengkapi untuk paket offline.'
            );
        }

        $plan = PackagePlan::query()->where('is_active', true)->findOrFail($validated['package_plan_id']);
        if (! empty($validated['learning_program_id'])) {
            app(LearningProgramSelection::class)->validate(
                (int) $validated['learning_program_id'], (int) $plan->id,
                $validated['education_level'], $validated['grade'], $validated['subjects']
            );
        }
        abort_if(count($validated['subjects']) > $plan->maximum_subjects, 422, "Paket ini maksimal {$plan->maximum_subjects} mata pelajaran.");
        $maximumWeekdays = match ((int) $plan->session_count) {
            1 => 1, 4 => 2, default => 4
        };
        $selectedWeekdays = collect($validated['subjects'])
            ->flatMap(fn (array $subjectPayload) => $subjectPayload['weekdays'])
            ->unique();
        abort_if($selectedWeekdays->count() > $maximumWeekdays, 422, 'Jumlah hari belajar unik pada seluruh paket melebihi batas paket.');

        foreach ($validated['subjects'] as $subjectPayload) {
            abort_if(count($subjectPayload['weekdays']) > $maximumWeekdays, 422, 'Jumlah hari belajar melebihi batas paket.');
        }
        $activeSlotTimes = LearningTimeSlot::query()
            ->where('is_active', true)
            ->pluck('start_time')
            ->map(fn ($time) => substr((string) $time, 0, 5))
            ->filter(fn (string $time) => substr($time, 3, 2) === '00' && $time < '23:00')
            ->values()
            ->all();
        abort_if(empty($activeSlotTimes), 422, 'Admin belum mengaktifkan slot jadwal belajar.');

        $subjectsById = CurriculumSubject::query()
            ->where('is_active', true)
            ->whereIn('id', collect($validated['subjects'])->pluck('curriculum_subject_id'))
            ->get()
            ->keyBy('id');
        abort_if($subjectsById->count() !== count($validated['subjects']), 422, 'Salah satu mata pelajaran tidak aktif.');

        // Nilai lead time tersimpan di settings agar backend memakai aturan
        // yang sama dengan frontend dan rekomendasi jadwal.
        $renewalOf = null;
        $bookingLeadHours = min(72, max(1, (int) (
            Setting::query()->where('key', 'booking_lead_hours')->value('value') ?? 24
        )));
        if (! empty($validated['renewal_of_id'])) {
            $renewalOf = LearningPackage::query()
                ->where('student_id', $student->id)
                ->with('subjects.chapters')
                ->findOrFail($validated['renewal_of_id']);
            abort_if(
                $this->hasBlockingRenewal($renewalOf),
                422,
                'Paket ini sudah memiliki paket lanjutan. Gunakan paket lanjutan terbaru jika ingin memperpanjang lagi.'
            );
            abort_unless($this->canRenew($renewalOf), 422, 'Paket selesai dapat langsung diperpanjang. Paket aktif baru dapat diperpanjang tujuh hari sebelum masa berlaku berakhir.');
            foreach ($validated['subjects'] as $item) {
                if (empty($item['preferred_teacher_id'])) {
                    continue;
                }
                $oldSubject = $renewalOf->subjects->firstWhere('curriculum_subject_id', (int) $item['curriculum_subject_id']);
                abort_unless(
                    $oldSubject && (int) $oldSubject->assigned_teacher_id === (int) $item['preferred_teacher_id'],
                    422,
                    'Tutor prioritas harus berasal dari mapel yang sama pada paket lama.'
                );
            }
            if ($this->renewalUsesSameTutors($renewalOf, $validated['subjects'])) {
                $bookingLeadHours = min(72, max(1, (int) (
                    Setting::query()->where('key', 'renewal_booking_lead_hours')->value('value') ?? 12
                )));
            }
        }

        $totalSchedules = collect($validated['subjects'])->sum(fn (array $item) => count($item['schedules']));
        abort_unless($totalSchedules === $plan->session_count, 422, "Jumlah jadwal harus tepat {$plan->session_count} sesi.");

        $normalizedSubjects = [];
        $allStarts = collect();
        foreach ($validated['subjects'] as $item) {
            $subject = $subjectsById[$item['curriculum_subject_id']];
            abort_if(
                $subject->education_levels
                    && ! in_array($validated['education_level'], $subject->education_levels, true),
                422,
                "{$subject->name} tidak tersedia pada jenjang ini."
            );
            abort_if(
                $subject->grades
                    && ! in_array($validated['grade'], $subject->grades, true),
                422,
                "{$subject->name} tidak tersedia pada kelas atau tingkat ini."
            );

            $starts = collect($item['schedules'])->map(function (string $value) use (
                $student,
                $activeSlotTimes,
                $durationHours,
                $bookingLeadHours
            ) {
                $start = Carbon::parse($value, config('app.timezone', 'Asia/Jakarta'))->seconds(0);
                abort_if(
                    $start->lt(now()->addHours($bookingLeadHours)),
                    422,
                    $bookingLeadHours === 12
                        ? 'Perpanjangan dengan tutor yang sama paling cepat dimulai 12 jam dari sekarang.'
                        : 'Jadwal paket paling cepat dimulai '.$bookingLeadHours.' jam dari sekarang.'
                );
                abort_unless($start->format('i') === '00', 422, 'Semua jadwal hanya boleh memakai menit 00.');
                abort_unless(
                    in_array($start->format('H:i'), $activeSlotTimes, true),
                    422,
                    'Jam yang dipilih tidak termasuk slot jam penuh yang aktif.'
                );
                abort_if(
                    ((int) $start->format('H')) + $durationHours > 23,
                    422,
                    'Jam mulai terlalu malam untuk durasi pertemuan yang dipilih.'
                );
                $this->assertStudentHasNoConflict($student->id, $start, $start->copy()->addHours($durationHours));

                return $start;
            })->sortBy(fn (Carbon $date) => $date->timestamp)->values();
            abort_if($starts->unique(fn (Carbon $date) => $date->timestamp)->count() !== $starts->count(), 422, 'Jadwal dalam satu mapel tidak boleh sama.');
            $chapterIds = collect($item['curriculum_chapter_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
            $chapters = CurriculumChapter::query()
                ->where('curriculum_subject_id', $subject->id)
                ->where('education_level', $validated['education_level'])
                ->where('grade', $validated['grade'])
                ->where('is_active', true)
                ->whereIn('id', $chapterIds)
                ->orderBy('sort_order')
                ->get();
            abort_unless($chapters->count() === $chapterIds->count(), 422, "Salah satu bab {$subject->name} tidak sesuai kelas atau sudah tidak aktif.");
            $allStarts = $allStarts->merge($starts);

            $unitPrice = $rateService->resolve(
                $subject->name,
                $validated['education_level'],
                'private',
                $validated['learning_mode']
            );
            $normalSubtotal = $unitPrice * $participantCount * $durationHours * $starts->count();
            $groupTotal = $participantPricing->customerHourlyTotal($unitPrice, $participantCount)
                * $durationHours * $starts->count();
            $normalizedSubjects[] = [
                ...$item,
                'subject' => $subject,
                'starts' => $starts,
                'chapters' => $chapters,
                'unit_price' => $unitPrice,
                'tutor_hourly_gross' => $participantPricing->tutorHourlyGross($unitPrice, $participantCount),
                'subtotal' => $normalSubtotal,
                'group_discount' => $normalSubtotal - $groupTotal,
            ];
        }
        $sortedStarts = $allStarts->sortBy(fn (Carbon $date) => $date->timestamp)->values();
        for ($index = 0; $index < $sortedStarts->count() - 1; $index++) {
            abort_if(
                $sortedStarts[$index]->copy()->addHours($durationHours)->gt($sortedStarts[$index + 1]),
                422,
                'Dua jadwal dalam paket tidak boleh bertumpang tindih.'
            );
        }
        abort_if(
            $allStarts->max()?->diffInDays($allStarts->min()) > $plan->validity_days,
            422,
            "Rentang jadwal melebihi masa penggunaan {$plan->validity_days} hari."
        );

        $subtotal = collect($normalizedSubjects)->sum('subtotal');
        $groupDiscount = collect($normalizedSubjects)->sum('group_discount');
        [$promotion, $claim] = $this->resolvePromotion($student->id, $validated);
        $promotionDiscount = $promotion
            ? $checkoutService->calculateDiscount(
                $promotion,
                $subtotal - $groupDiscount,
                $plan->id,
                $validated['education_level'],
                collect($normalizedSubjects)->pluck('subject.name')->all(),
                $validated['learning_mode'],
                $student,
                $claim
            )
            : 0;
        $discount = $groupDiscount + $promotionDiscount;
        $earningsAllocation = app(PackageEarningsAllocation::class);

        [$package, $order] = DB::transaction(function () use (
            $student,
            $plan,
            $validated,
            $normalizedSubjects,
            $subtotal,
            $discount,
            $promotion,
            $claim,
            $checkoutService,
            $renewalOf,
            $durationHours,
            $participantCount,
            $purchaserParticipates,
            $participantNames,
            $participantDetails,
            $earningsAllocation
        ) {
            User::query()->lockForUpdate()->findOrFail($student->id);
            if ($renewalOf) {
                $lockedRenewalSource = LearningPackage::query()->lockForUpdate()->findOrFail($renewalOf->id);
                abort_if(
                    $this->hasBlockingRenewal($lockedRenewalSource),
                    422,
                    'Paket ini sudah memiliki paket lanjutan. Gunakan paket lanjutan terbaru jika ingin memperpanjang lagi.'
                );
                abort_unless(
                    $this->canRenewByStatusAndTime($lockedRenewalSource),
                    422,
                    'Paket selesai dapat langsung diperpanjang. Paket aktif baru dapat diperpanjang tujuh hari sebelum masa berlaku berakhir.'
                );
            }
            foreach ($normalizedSubjects as $item) {
                foreach ($item['starts'] as $start) {
                    $this->assertStudentHasNoConflict(
                        $student->id,
                        $start,
                        $start->copy()->addHours($durationHours)
                    );
                }
            }
            $package = LearningPackage::create([
                'student_id' => $student->id,
                'package_plan_id' => $plan->id,
                'learning_program_id' => $validated['learning_program_id'] ?? null,
                'promotion_id' => $promotion?->id,
                'renewal_of_id' => $renewalOf?->id,
                'package_code' => 'BKU-'.now()->format('ymdHis').'-'.$student->id.'-'.random_int(10, 99),
                'participant_count' => $participantCount,
                'purchaser_participates' => $purchaserParticipates,
                'participant_names' => $participantNames,
                'participant_details' => $participantDetails,
                'education_level' => $validated['education_level'],
                'grade' => $validated['grade'],
                'learning_mode' => $validated['learning_mode'],
                'duration_hours' => $durationHours,
                'address' => $validated['learning_mode'] === 'offline' ? $student->address : null,
                'maps_link' => $validated['learning_mode'] === 'offline' ? $student->maps_link : null,
                'status' => 'awaiting_payment',
                'total_sessions' => $plan->session_count,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $subtotal - $discount,
            ]);

            foreach ($normalizedSubjects as $item) {
                $chapterLabel = $this->compactLabel($item['chapters']->pluck('title'));
                $packageSubject = PackageSubject::create([
                    'learning_package_id' => $package->id,
                    'curriculum_subject_id' => $item['subject']->id,
                    'curriculum_chapter_id' => $item['chapters']->first()?->id,
                    'curriculum_chapter_ids' => $item['chapters']->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'preferred_teacher_id' => $item['preferred_teacher_id'] ?? null,
                    'subject_name' => $item['subject']->name,
                    'chapter' => $chapterLabel,
                    'learning_goal' => $item['learning_goal'] ?? null,
                    'allocated_sessions' => $item['starts']->count(),
                    'unit_price' => $item['unit_price'],
                    'tutor_hourly_gross' => $item['tutor_hourly_gross'],
                    'subtotal_amount' => $item['subtotal'],
                    'status' => 'awaiting_payment',
                ]);

                $previousSubject = $renewalOf?->subjects->firstWhere('subject_name', $item['subject']->name);
                $previousChapters = PackageChapterProgress::chapters($previousSubject?->chapters ?? collect())
                    ->keyBy('chapter');
                foreach ($item['chapters']->values() as $index => $chapter) {
                    $wasCompletedBefore = $renewalOf
                        && (($previousChapters->get($chapter->title)['status'] ?? null) === 'completed');
                    PackageChapter::create([
                        'package_subject_id' => $packageSubject->id,
                        'curriculum_chapter_id' => $chapter->id,
                        'title' => $chapter->title,
                        // Paket baru selalu memulai siklus Bab dari awal. Jika Bab yang sama
                        // pernah selesai, tandai sebagai penguatan tanpa mengubah histori lama.
                        'status' => 'not_started',
                        'needs_review' => $wasCompletedBefore,
                        'sort_order' => $index + 1,
                        'started_at' => null,
                        'completed_at' => null,
                    ]);
                }

                foreach ($item['starts']->values() as $index => $start) {
                    PackageSession::create([
                        'package_subject_id' => $packageSubject->id,
                        'sequence' => $index + 1,
                        'scheduled_start_at' => $start,
                        'scheduled_end_at' => $start->copy()->addHours($durationHours),
                        'status' => 'awaiting_payment',
                    ]);
                }

                $firstStart = $item['starts']->first();
                BookingRequest::create([
                    'student_id' => $student->id,
                    'package_subject_id' => $packageSubject->id,
                    'subject_name' => $item['subject']->name,
                    'curriculum_subject_id' => $item['subject']->id,
                    'education_level' => $validated['education_level'],
                    'grade' => $validated['grade'],
                    'chapter' => $chapterLabel,
                    'learning_goal' => $item['learning_goal'] ?? null,
                    'learning_mode' => $validated['learning_mode'],
                    'class_type' => 'private',
                    'scheduled_date' => $firstStart->toDateString(),
                    'start_time' => $firstStart->format('H:i:s'),
                    'end_time' => $firstStart->copy()->addHours($durationHours)->format('H:i:s'),
                    'duration_hours' => $durationHours,
                    'address' => $package->address,
                    'maps_link' => $package->maps_link,
                    'latitude' => $validated['learning_mode'] === 'offline' ? $student->latitude : null,
                    'longitude' => $validated['learning_mode'] === 'offline' ? $student->longitude : null,
                    'status' => 'awaiting_payment',
                    'search_radius_km' => $validated['learning_mode'] === 'offline' ? 3 : null,
                    'search_started_at' => null,
                    'search_expires_at' => null,
                    'hourly_rate' => $item['tutor_hourly_gross'],
                    'total_amount' => $participantCount > 1 ? $item['tutor_hourly_gross'] * $durationHours : $item['subtotal'],
                ]);

                if ($renewalOf) {
                    $oldSubject = $renewalOf->subjects->firstWhere('subject_name', $item['subject']->name);
                    if ($oldSubject) {
                        PackageRenewal::create([
                            'old_package_id' => $renewalOf->id,
                            'new_package_id' => $package->id,
                            'old_package_subject_id' => $oldSubject->id,
                            'old_teacher_id' => $oldSubject->assigned_teacher_id,
                            'status' => 'requested',
                        ]);
                    }
                }
            }

            // The teacher offer must use the same after-discount amount as the
            // invoice, apportioned to this subject rather than the whole program.
            foreach ($earningsAllocation->forPackage($package->load('subjects.sessions')) as $subjectId => $amounts) {
                $subject = $package->subjects->firstWhere('id', $subjectId);
                $hours = max(1, $durationHours * (int) $subject->allocated_sessions);
                BookingRequest::query()
                    ->where('package_subject_id', $subjectId)
                    ->where('status', 'awaiting_payment')
                    ->update([
                        'hourly_rate' => $amounts['gross_amount'] / $hours,
                        'total_amount' => $amounts['gross_amount'],
                    ]);
            }

            if ($promotion) {
                $checkoutService->reservePromotion($promotion, $student, $package, $claim);
            }

            return [$package, $checkoutService->createInvoiceBeforeMatching($package)];
        }, 3);

        $snapshot = $order->class_details_snapshot ?? [];

        return response()->json([
            'message' => 'Paket dibuat. Selesaikan pembayaran agar pencarian tutor dapat dimulai.',
            'data' => $this->formatPackage($package->fresh([
                'plan', 'promotion', 'subjects.assignedTeacher', 'subjects.chapters', 'subjects.sessions.booking.teacher.teacherProfile', 'subjects.latestTeacherReplacement.sessions.packageSession', 'orders',
            ])),
            'order' => [
                'order_id' => $order->id,
                'order_number' => $order->order_id,
                'amount' => (float) $order->amount,
                'status' => $order->status,
                'payment_due_at' => $package->payment_due_at,
                'subject' => $snapshot['subject'] ?? 'Paket belajar',
                'type' => ucfirst((string) ($snapshot['method'] ?? 'online')).' · Paket Belajar',
                'tutor_name' => $snapshot['teacher_name'] ?? 'Dicari setelah pembayaran',
                'scheduled_at' => $snapshot['start_at'] ?? null,
                'subtotal_amount' => (float) $order->subtotal_amount,
                'discount_amount' => (float) $order->discount_amount,
                'package_name' => $snapshot['package_name'] ?? $plan->name,
                'duration_hours' => $durationHours,
                'total_learning_hours' => $plan->session_count * $durationHours,
                'order_kind' => 'package',
            ],
        ], 201);
    }

    public function claim(Request $request, int $promotion)
    {
        $userId = $request->user()->id;
        $selectedPromotion = Promotion::query()->find($promotion);
        abort_if($selectedPromotion === null, 404, 'Penawaran tidak ditemukan atau sudah dihapus.');

        [$claimed, $alreadyClaimed] = DB::transaction(function () use ($selectedPromotion, $userId) {
            $lockedPromotion = Promotion::query()->lockForUpdate()->find($selectedPromotion->id);
            abort_if($lockedPromotion === null, 404, 'Penawaran tidak ditemukan atau sudah dihapus.');
            abort_unless($lockedPromotion->isAvailable(), 422, 'Penawaran sudah berakhir atau dinonaktifkan.');
            $existing = PromotionClaim::query()
                ->where('promotion_id', $lockedPromotion->id)
                ->where('user_id', $userId)
                ->where('status', 'available')
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return [$existing, true];
            }

            $usedQuota = $lockedPromotion->claims()
                ->whereIn('status', ['available', 'reserved', 'used'])
                ->lockForUpdate()
                ->count();
            abort_if(
                $lockedPromotion->total_quota !== null && $usedQuota >= $lockedPromotion->total_quota,
                422,
                'Kuota penawaran sudah habis.'
            );
            $userUsage = $lockedPromotion->claims()
                ->where('user_id', $userId)
                ->whereIn('status', ['available', 'reserved', 'used'])
                ->count();
            abort_if($userUsage >= $lockedPromotion->per_user_limit, 422, 'Batas klaim akun telah tercapai.');

            return [
                PromotionClaim::create([
                    'promotion_id' => $lockedPromotion->id,
                    'user_id' => $userId,
                    'status' => 'available',
                    'claimed_at' => now(),
                ]),
                false,
            ];
        }, 3);

        if ($alreadyClaimed) {
            return response()->json(['message' => 'Penawaran sudah berada di Voucher Saya.', 'data' => $claimed]);
        }

        return response()->json(['message' => 'Penawaran masuk ke Voucher Saya.', 'data' => $claimed], 201);
    }

    public function vouchers(Request $request)
    {
        $query = PromotionClaim::query()
            ->where('user_id', $request->user()->id)
            ->latest('claimed_at');

        if ($request->boolean('compact')) {
            $claims = $query
                ->where('status', 'available')
                ->select(['id', 'promotion_id', 'status', 'claimed_at'])
                ->with(['promotion' => fn ($promotions) => $promotions->select([
                    'id', 'title', 'discount_type', 'discount_value', 'ends_at',
                ])])
                ->limit(30)
                ->get();

            return response()->json(['data' => $claims]);
        }

        return response()->json(
            $query->with('promotion')->paginate(30)
        );
    }

    public function retryMatching(
        Request $request,
        LearningPackage $learningPackage,
        PackageCheckoutService $checkoutService,
        TeacherMatchingService $matchingService
    ) {
        abort_unless($learningPackage->student_id === $request->user()->id, 403);
        $subjectIds = DB::transaction(function () use ($learningPackage, $request, $matchingService) {
            $package = LearningPackage::query()
                ->with('subjects.bookingRequest.matchingOperationLogs')
                ->lockForUpdate()
                ->findOrFail($learningPackage->id);
            abort_unless(
                in_array($package->status, ['matching', 'teacher_pending', 'no_teacher'], true),
                422,
                'Pencarian paket ini sudah tidak dapat diulang.'
            );

            $subjects = $package->subjects->filter(function (PackageSubject $subject) {
                $requestStatus = $subject->bookingRequest?->status;

                return $subject->status === 'no_teacher' || $requestStatus === 'no_teacher';
            });
            abort_if($subjects->isEmpty(), 422, 'Tidak ada mata pelajaran yang perlu dicari ulang.');

            $retryable = collect();
            foreach ($subjects as $subject) {
                $bookingRequest = $subject->bookingRequest;
                if (! $bookingRequest) {
                    continue;
                }
                $currentRadius = (int) ($bookingRequest->search_radius_km ?? 3);
                $nextRadius = $bookingRequest->learning_mode === 'offline'
                    ? $matchingService->nextRadius($currentRadius)
                    : null;
                $manualRestarts = $bookingRequest->matchingOperationLogs
                    ->where('action', 'search_restarted')
                    ->count();

                if ($nextRadius === null && $manualRestarts >= 1) {
                    continue;
                }

                $nextScope = $nextRadius ?? $currentRadius;
                $action = $nextRadius ? 'radius_expanded' : 'search_restarted';
                $reason = $nextRadius
                    ? "Murid mengulang pencarian paket dengan memperluas radius {$currentRadius} km menjadi {$nextScope} km."
                    : 'Murid meminta satu kali pengecekan tutor baru pada jangkauan maksimum yang sama.';

                $bookingRequest->update([
                    'status' => 'matching',
                    'matched_teacher_id' => null,
                    'teacher_response_deadline' => null,
                    'next_matching_at' => null,
                    'search_radius_km' => $nextScope,
                    'search_started_at' => now(),
                    'search_expires_at' => now()->addHours($matchingService->maximumSearchHours()),
                ]);
                $subject->update(['status' => 'matching']);
                MatchingOperationLog::create([
                    'booking_request_id' => $bookingRequest->id,
                    'actor_id' => $request->user()->id,
                    'action' => $action,
                    'reason' => $reason,
                    'before_state' => ['status' => 'no_teacher', 'search_radius_km' => $currentRadius],
                    'after_state' => ['status' => 'matching', 'search_radius_km' => $nextScope],
                    'metadata' => [
                        'source' => 'student_package',
                        'package_id' => $package->id,
                        'package_subject_id' => $subject->id,
                        'maximum_search_hours' => $matchingService->maximumSearchHours(),
                    ],
                ]);
                $retryable->push($subject->id);
            }

            abort_if(
                $retryable->isEmpty(),
                422,
                'Pencarian pada jangkauan maksimum sudah pernah diulang. Ubah jadwal agar sistem mendapat kandidat baru, atau batalkan paket untuk mengajukan refund.'
            );
            $package->update(['status' => 'matching']);

            return $retryable->all();
        }, 3);

        $noTeacher = false;
        PackageSubject::query()
            ->whereIn('id', $subjectIds)
            ->with('bookingRequest')
            ->get()
            ->each(function (PackageSubject $subject) use ($checkoutService, &$noTeacher) {
                if (! $checkoutService->dispatchSubject($subject)) {
                    $subject->update(['status' => 'no_teacher']);
                    $noTeacher = true;
                }
            });
        $packageStatus = $matchingService->syncPackageStatus($learningPackage);

        return response()->json([
            'message' => $noTeacher || $packageStatus === 'no_teacher'
                ? 'Pencarian baru sudah dijalankan, tetapi tutor masih belum tersedia. Periksa alasan di tiap mapel atau ubah jadwal.'
                : 'Radar memulai pencarian baru dengan batas waktu yang diperbarui.',
        ]);
    }

    public function reschedule(
        Request $request,
        LearningPackage $learningPackage,
        PackageCheckoutService $checkoutService,
        TeacherMatchingService $matchingService
    ) {
        abort_unless($learningPackage->student_id === $request->user()->id, 403);
        $validated = $request->validate([
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*.package_subject_id' => ['required', 'integer', 'distinct', 'exists:package_subjects,id'],
            'subjects.*.schedules' => ['required', 'array', 'min:1'],
            'subjects.*.schedules.*' => ['required', 'date'],
        ]);

        $subjectIds = DB::transaction(function () use ($request, $learningPackage, $validated, $matchingService) {
            $package = LearningPackage::query()
                ->with(['plan', 'subjects.sessions', 'subjects.bookingRequest.offers', 'orders'])
                ->lockForUpdate()
                ->findOrFail($learningPackage->id);
            abort_unless($package->student_id === $request->user()->id, 403);
            abort_unless(in_array($package->status, ['no_teacher', 'matching', 'teacher_pending'], true), 422, 'Jadwal hanya dapat diubah saat tutor belum ditemukan.');
            abort_unless($package->orders->contains('status', 'paid'), 422, 'Pembayaran paket belum terverifikasi.');

            $durationHours = (int) ($package->duration_hours ?? 1);
            $bookingLeadHours = min(72, max(1, (int) (
                Setting::query()->where('key', 'booking_lead_hours')->value('value') ?? 24
            )));
            $activeSlotTimes = LearningTimeSlot::query()->where('is_active', true)->pluck('start_time')
                ->map(fn ($time) => substr((string) $time, 0, 5))
                ->filter(fn (string $time) => substr($time, 3, 2) === '00' && $time < '23:00')
                ->values()->all();
            $requestedIds = collect($validated['subjects'])->pluck('package_subject_id')->map(fn ($id) => (int) $id);
            $targets = $package->subjects->whereIn('id', $requestedIds)->keyBy('id');
            abort_unless($targets->count() === $requestedIds->count(), 422, 'Mata pelajaran paket tidak valid.');

            $newIntervals = collect();
            foreach ($validated['subjects'] as $payload) {
                $subject = $targets[(int) $payload['package_subject_id']];
                $bookingRequest = $subject->bookingRequest;
                abort_unless($bookingRequest && in_array($bookingRequest->status, ['no_teacher', 'expired', 'matching', 'teacher_pending'], true), 422, "Jadwal {$subject->subject_name} belum dapat diubah karena tutor sudah ditetapkan.");
                $starts = collect($payload['schedules'])->map(function (string $value) use ($activeSlotTimes, $durationHours, $bookingLeadHours) {
                    $start = Carbon::parse($value, config('app.timezone', 'Asia/Jakarta'))->seconds(0);
                    abort_if(
                        $start->lt(now()->addHours($bookingLeadHours)),
                        422,
                        'Jadwal baru paling cepat dimulai '.$bookingLeadHours.' jam dari sekarang.'
                    );
                    abort_unless($start->format('i') === '00', 422, 'Semua jadwal hanya boleh memakai menit 00.');
                    abort_unless(in_array($start->format('H:i'), $activeSlotTimes, true), 422, 'Jam yang dipilih tidak termasuk slot aktif.');
                    abort_if(((int) $start->format('H')) + $durationHours > 23, 422, 'Jam mulai terlalu malam untuk durasi pertemuan.');

                    return $start;
                })->sortBy(fn (Carbon $date) => $date->timestamp)->values();
                abort_unless($starts->count() === (int) $subject->allocated_sessions, 422, "Jumlah jadwal {$subject->subject_name} harus {$subject->allocated_sessions} sesi.");
                abort_if($starts->unique(fn (Carbon $date) => $date->timestamp)->count() !== $starts->count(), 422, "Jadwal {$subject->subject_name} tidak boleh duplikat.");
                foreach ($starts as $start) {
                    $newIntervals->push(['subject_id' => $subject->id, 'start' => $start, 'end' => $start->copy()->addHours($durationHours)]);
                }
            }

            $sorted = $newIntervals->sortBy(fn ($item) => $item['start']->timestamp)->values();
            for ($i = 0; $i < $sorted->count() - 1; $i++) {
                abort_if($sorted[$i]['end']->gt($sorted[$i + 1]['start']), 422, 'Ada jadwal baru yang saling bertabrakan.');
            }
            $otherSessions = $package->subjects->whereNotIn('id', $requestedIds)->flatMap->sessions;
            foreach ($newIntervals as $interval) {
                abort_if($otherSessions->contains(fn ($session) => $session->scheduled_start_at->lt($interval['end']) && $session->scheduled_end_at->gt($interval['start'])), 422, 'Jadwal baru bertabrakan dengan sesi lain di paket yang sama.');
                $this->assertStudentHasNoConflict($package->student_id, $interval['start'], $interval['end'], $package->id);
            }
            $allStarts = $otherSessions->pluck('scheduled_start_at')->merge($newIntervals->pluck('start'))->sort();
            if ($allStarts->isNotEmpty() && $package->plan) {
                $rangeDays = $allStarts->first()->diffInDays($allStarts->last());
                abort_if($rangeDays > (int) $package->plan->validity_days, 422, "Rentang jadwal melebihi masa paket {$package->plan->validity_days} hari.");
            }

            foreach ($validated['subjects'] as $payload) {
                $subject = $targets[(int) $payload['package_subject_id']];
                $bookingRequest = $subject->bookingRequest;
                $starts = collect($payload['schedules'])->map(fn (string $value) => Carbon::parse($value, config('app.timezone', 'Asia/Jakarta'))->seconds(0))->sortBy(fn (Carbon $date) => $date->timestamp)->values();
                $beforeSchedules = $subject->sessions->sortBy('sequence')->pluck('scheduled_start_at')->map(fn ($date) => $date?->toIso8601String())->values()->all();
                foreach ($subject->sessions->sortBy('sequence')->values() as $index => $session) {
                    $start = $starts[$index];
                    $session->update(['scheduled_start_at' => $start, 'scheduled_end_at' => $start->copy()->addHours($durationHours), 'status' => 'planned', 'booking_id' => null]);
                }
                $bookingRequest->offers()->where('status', 'pending')->update(['status' => 'expired', 'responded_at' => now()]);
                $firstStart = $starts->first();
                $bookingRequest->update([
                    'scheduled_date' => $firstStart->toDateString(),
                    'start_time' => $firstStart->format('H:i:s'),
                    'end_time' => $firstStart->copy()->addHours($durationHours)->format('H:i:s'),
                    'status' => 'matching',
                    'matched_teacher_id' => null,
                    'teacher_response_deadline' => null,
                    'next_matching_at' => null,
                    'search_radius_km' => $package->learning_mode === 'offline' ? 3 : null,
                    'search_started_at' => now(),
                    'search_expires_at' => now()->addHours($matchingService->maximumSearchHours()),
                ]);
                $subject->update(['status' => 'matching', 'assigned_teacher_id' => null]);
                MatchingOperationLog::create([
                    'booking_request_id' => $bookingRequest->id,
                    'actor_id' => $request->user()->id,
                    'action' => 'schedule_changed',
                    'reason' => 'Murid mengubah jadwal setelah tutor belum ditemukan.',
                    'before_state' => ['schedules' => $beforeSchedules],
                    'after_state' => ['schedules' => $starts->map(fn (Carbon $date) => $date->toIso8601String())->all(), 'search_radius_km' => $package->learning_mode === 'offline' ? 3 : null],
                    'metadata' => ['source' => 'student_package', 'package_id' => $package->id, 'package_subject_id' => $subject->id],
                ]);
            }
            $package->update(['status' => 'matching']);

            return $requestedIds->all();
        }, 3);

        $noTeacher = false;
        PackageSubject::query()->whereIn('id', $subjectIds)->with('bookingRequest')->get()
            ->each(function (PackageSubject $subject) use ($checkoutService, &$noTeacher) {
                if (! $checkoutService->dispatchSubject($subject)) {
                    $subject->update(['status' => 'no_teacher']);
                    $noTeacher = true;
                }
            });
        $packageStatus = $matchingService->syncPackageStatus($learningPackage);

        return response()->json([
            'message' => $noTeacher || $packageStatus === 'no_teacher'
                ? 'Jadwal sudah diperbarui dan pencarian dimulai ulang, tetapi tutor belum tersedia. Periksa alasan terbaru di Kelas Saya.'
                : 'Jadwal berhasil diperbarui. Sistem mulai mencari tutor dengan jadwal baru.',
        ]);
    }

    public function cancel(
        Request $request,
        LearningPackage $learningPackage,
        RefundSettlementService $refunds,
        ScheduleReleaseService $scheduleRelease,
        RefundService $refundService
    ) {
        abort_unless($learningPackage->student_id === $request->user()->id, 403);

        $destination = $request->input('destination') ?? $request->input('destination_method');
        if ($destination && ($refundService->isWalletDestination($destination) || $refundService->isExternalDestination($destination))) {
            $result = $refundService->cancelPackage(
                $learningPackage,
                $destination,
                $request->only(['bank_name', 'account_name', 'account_number']),
                $request->user()->id
            );

            return response()->json([
                'message' => $result['message'],
                'package_status' => $result['status'],
                'refund_status' => $result['refund']?->status,
                'refund_id' => $result['refund']?->id,
                'needs_destination' => false,
            ]);
        }

        [$teacherIds, $refund, $automatic] = DB::transaction(function () use ($learningPackage, $scheduleRelease) {
            $package = LearningPackage::query()
                ->with(['subjects.sessions', 'subjects.bookingRequest.offers', 'promotionClaims', 'orders'])
                ->lockForUpdate()
                ->findOrFail($learningPackage->id);
            abort_unless(
                in_array($package->status, ['matching', 'teacher_pending', 'no_teacher'], true),
                422,
                'Paket ini tidak dapat dibatalkan dari tahap pencarian.'
            );

            $paidOrder = $package->orders->where('status', 'paid')->sortByDesc('id')->first();
            $refundPending = (bool) $paidOrder;
            // Pembatalan sebelum kelas terbentuk tidak membutuhkan keputusan admin:
            // dana kembali melalui jalur pembayaran asal dengan referensi yang sama.
            $automatic = in_array($package->status, ['no_teacher', 'matching', 'teacher_pending'], true);
            $nextStatus = $refundPending ? 'refund_pending' : 'cancelled';
            $teacherIds = collect();
            foreach ($package->subjects as $subject) {
                $subject->update(['status' => $nextStatus]);
                $bookingRequest = $subject->bookingRequest;
                if ($bookingRequest) {
                    $teacherIds = $teacherIds->merge(
                        $bookingRequest->offers
                            ->whereIn('status', ['pending', 'accepted'])
                            ->pluck('teacher_id')
                    );
                    $bookingRequest->offers()
                        ->whereIn('status', ['pending', 'accepted'])
                        ->update(['status' => 'cancelled', 'responded_at' => now()]);
                    $bookingRequest->update(['status' => $nextStatus]);
                }
            }
            // Slot jadwal harus langsung bebas agar murid dapat memesan jam yang
            // sama tanpa menunggu refund selesai diproses.
            $scheduleRelease->releasePackage($package, $nextStatus);
            $package->promotionClaims()
                ->where('status', 'reserved')
                ->update([
                    'status' => 'available',
                    'released_at' => now(),
                    'learning_package_id' => null,
                ]);
            PackageRenewal::query()
                ->where('new_package_id', $package->id)
                ->whereIn('status', ['requested', 'tutor_accepted'])
                ->update(['status' => 'cancelled']);
            $package->update(['status' => $nextStatus]);
            $refund = null;
            if ($paidOrder) {
                $paidOrder->update(['status' => 'refund_pending']);
                $refund = Refund::firstOrCreate(
                    ['order_id' => $paidOrder->id],
                    [
                        'user_id' => $package->student_id,
                        'booking_id' => $paidOrder->booking_id,
                        'amount' => $paidOrder->amount,
                        'reason' => $automatic ? 'Tutor tidak ditemukan' : 'Paket dibatalkan murid saat pencarian tutor',
                        'status' => 'pending',
                    ]
                );
            }

            return [$teacherIds->filter()->unique()->values(), $refund, $automatic];
        }, 3);

        $automaticStarted = $refund && $automatic && $refunds->startAutomatic($refund);

        $teacherIds->each(fn (int $teacherId) => Notification::updateOrCreate(
            ['unique_key' => "package-search-cancelled:{$learningPackage->id}:{$teacherId}"],
            [
                'user_id' => $teacherId,
                'title' => 'Permintaan paket dibatalkan',
                'message' => "Murid membatalkan pencarian paket {$learningPackage->package_code}. Penawaran tidak lagi aktif.",
                'type' => 'info',
                'target_url' => '/guru/permintaan',
                'is_read' => false,
            ]
        ));

        if ($refund && ! $automatic) {
            $refundNeedsReviewKey = "refund-needs-review:{$refund->id}";
            User::query()->where('role', 'admin')->pluck('id')->each(
                fn (int $adminId) => Notification::updateOrCreate(
                    ['unique_key' => "{$refundNeedsReviewKey}:{$adminId}"],
                    [
                        'user_id' => $adminId,
                        'title' => 'Refund paket menunggu proses',
                        'message' => "Paket {$learningPackage->package_code} dibatalkan saat pencarian tutor.",
                        'type' => 'warning',
                        'target_url' => '/admin/finance?tab=refunds',
                        'is_read' => false,
                    ]
                )
            );
        }

        $freshRefund = $refund?->fresh();

        return response()->json([
            'message' => $refund
                ? ($automaticStarted
                    ? 'Pencarian dihentikan. Refund diproses otomatis ke sumber pembayaran asal.'
                    : 'Pencarian dihentikan. Pilih dan konfirmasi tujuan refund di Riwayat Transaksi sebelum dana diproses.')
                : 'Pencarian paket dibatalkan dan voucher dikembalikan.',
            'refund_id' => $freshRefund?->id,
            'needs_destination' => (bool) ($freshRefund
                && in_array($freshRefund->status, ['pending', 'failed'], true)
                && ! $freshRefund->destination_selected_at),
        ]);
    }

    public function previewPromotion(
        Request $request,
        HourlyRateService $rateService,
        PackageCheckoutService $checkoutService,
        PrivateParticipantPricing $participantPricing
    ) {
        $maximumParticipants = $participantPricing->maximumParticipants();
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:60'],
            'promotion_claim_id' => ['nullable', 'integer'],
            'package_plan_id' => ['required', 'integer', 'exists:package_plans,id'],
            'education_level' => ['required', Rule::in(EducationCatalog::LEVELS)],
            'learning_mode' => ['required', Rule::in(['online', 'offline'])],
            'duration_hours' => ['nullable', 'integer', Rule::in([1, 2])],
            'participant_count' => ['nullable', 'integer', 'between:1,'.$maximumParticipants],
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*.curriculum_subject_id' => ['required', 'integer', 'exists:curriculum_subjects,id'],
            'subjects.*.session_count' => ['required', 'integer', 'min:1'],
        ]);
        abort_if(filled($validated['code'] ?? null) && filled($validated['promotion_claim_id'] ?? null), 422);

        if (! $request->user() && (filled($validated['code'] ?? null) || filled($validated['promotion_claim_id'] ?? null))) {
            abort(422, 'Kode promo dan voucher hanya dapat diperiksa setelah masuk.');
        }
        [$promotion, $claim] = $request->user()
            ? $this->resolvePromotion($request->user()->id, [
                'promotion_code' => $validated['code'] ?? null,
                'promotion_claim_id' => $validated['promotion_claim_id'] ?? null,
            ])
            : [null, null];
        $durationHours = (int) ($validated['duration_hours'] ?? 1);
        $participantCount = (int) ($validated['participant_count'] ?? 1);
        $participantPricing->forCount($participantCount);
        $subjects = CurriculumSubject::query()
            ->whereIn('id', collect($validated['subjects'])->pluck('curriculum_subject_id'))
            ->get()
            ->keyBy('id');
        $lines = collect($validated['subjects'])->map(function (array $item) use ($subjects, $validated, $rateService, $durationHours, $participantCount, $participantPricing) {
            $subject = $subjects[$item['curriculum_subject_id']] ?? null;
            abort_unless($subject, 422, 'Mata pelajaran tidak ditemukan.');

            $unit = $rateService->resolve(
                $subject->name,
                $validated['education_level'],
                'private',
                $validated['learning_mode']
            );

            return [
                'curriculum_subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'session_count' => $item['session_count'],
                'duration_hours' => $durationHours,
                'unit_price' => $unit,
                'meeting_price' => $unit * $participantCount * $durationHours,
                'subtotal_amount' => $unit * $participantCount * $durationHours * $item['session_count'],
                'group_discount_amount' => ($unit * $participantCount - $participantPricing->customerHourlyTotal($unit, $participantCount))
                    * $durationHours * $item['session_count'],
            ];
        })->values();

        $subtotal = (int) $lines->sum('subtotal_amount');
        $groupDiscount = (int) $lines->sum('group_discount_amount');
        $promotionDiscount = $promotion
            ? $checkoutService->calculateDiscount(
                $promotion,
                $subtotal - $groupDiscount,
                $validated['package_plan_id'],
                $validated['education_level'],
                $subjects->pluck('name')->all(),
                $validated['learning_mode'],
                $request->user(),
                $claim
            )
            : 0;
        $discount = $groupDiscount + $promotionDiscount;

        return response()->json([
            'promotion' => $promotion,
            'participant_count' => $participantCount,
            'group_discount_amount' => $groupDiscount,
            'duration_hours' => $durationHours,
            'total_learning_hours' => $durationHours * collect($validated['subjects'])->sum('session_count'),
            'lines' => $lines,
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'total_amount' => $subtotal - $discount,
        ]);
    }

    private function resolvePromotion(int $userId, array $validated): array
    {
        if (! empty($validated['promotion_claim_id'])) {
            $claim = PromotionClaim::query()
                ->where('user_id', $userId)
                ->where('status', 'available')
                ->with('promotion')
                ->find($validated['promotion_claim_id']);
            abort_unless($claim && $claim->promotion, 422, 'Voucher tidak ditemukan atau sudah tidak dapat digunakan.');
            abort_unless($claim->promotion->isAvailable(), 422, 'Voucher sudah berakhir atau dinonaktifkan.');

            return [$claim->promotion, $claim];
        }
        if (! empty($validated['promotion_code'])) {
            $promotion = Promotion::query()
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($validated['promotion_code']))])
                ->first();
            abort_unless($promotion, 422, 'Kode promo tidak ditemukan.');
            abort_unless($promotion->isAvailable(), 422, 'Kode promo sudah berakhir atau dinonaktifkan.');
            $availableClaim = PromotionClaim::query()
                ->where('user_id', $userId)
                ->where('promotion_id', $promotion->id)
                ->where('status', 'available')
                ->oldest('claimed_at')
                ->first();
            abort_if(
                $promotion->claim_required && ! $availableClaim,
                422,
                'Promo ini harus diklaim lebih dahulu dari halaman penawaran.'
            );

            return [$promotion, $availableClaim];
        }

        return [null, null];
    }

    private function assertStudentHasNoConflict(int $studentId, Carbon $start, Carbon $end, ?int $ignorePackageId = null): void
    {
        $bookingConflict = Booking::query()
            ->where('student_id', $studentId)
            ->whereIn('status', [
                'awaiting_payment', 'payment_submitted', 'confirmed', 'in_progress',
                'awaiting_student_approval', 'disputed', 'admin_review_required',
            ])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            // Legacy rows may still look active after a refund. The order,
            // booking request, and package status are authoritative for a
            // released slot. Order `refund_pending` alone is not enough: a
            // single-session refund must not free the package's other slots.
            ->whereDoesntHave('order', fn ($order) => $order
                ->whereIn('status', ['refunded', 'cancelled', 'expired']))
            ->whereDoesntHave('bookingRequest', fn ($bookingRequest) => $bookingRequest
                ->whereIn('status', ['refund_pending', 'refunded', 'cancelled', 'payment_expired', 'expired']))
            ->whereDoesntHave('bookingRequest.packageSubject.package', fn ($package) => $package
                ->whereIn('status', ['refund_pending', 'refunded', 'cancelled', 'payment_expired']))
            ->exists();
        $packageConflict = PackageSession::query()
            ->whereHas('subject.package', fn ($query) => $query
                ->where('student_id', $studentId)
                ->when($ignorePackageId, fn ($packages) => $packages->whereKeyNot($ignorePackageId))
                ->whereNotIn('status', ['cancelled', 'payment_expired', 'completed', 'refund_pending', 'refunded']))
            ->whereNotIn('status', ['cancelled', 'payment_expired', 'completed', 'refund_pending', 'refunded'])
            ->where('scheduled_start_at', '<', $end)
            ->where('scheduled_end_at', '>', $start)
            ->exists();
        if ($bookingConflict || $packageConflict) {
            $startLabel = $start->copy()->timezone(config('app.timezone', 'Asia/Jakarta'))->format('d/m/Y H.i');
            $endLabel = $end->copy()->timezone(config('app.timezone', 'Asia/Jakarta'))->format('H.i');
            abort(422, "Jadwal {$startLabel}–{$endLabel} bertabrakan dengan kelas atau paket lain yang masih aktif. Pilih hari atau jam lain.");
        }
    }

    private function renewalUsesSameTutors(LearningPackage $source, array $subjects): bool
    {
        if (empty($subjects)) {
            return false;
        }

        foreach ($subjects as $item) {
            $preferredTeacherId = (int) ($item['preferred_teacher_id'] ?? 0);
            $curriculumSubjectId = (int) ($item['curriculum_subject_id'] ?? 0);
            $oldSubject = $source->subjects->firstWhere('curriculum_subject_id', $curriculumSubjectId);
            if (! $oldSubject || $preferredTeacherId <= 0 || (int) $oldSubject->assigned_teacher_id !== $preferredTeacherId) {
                return false;
            }
        }

        return true;
    }

    private function canRenew(LearningPackage $package): bool
    {
        return ! $this->hasBlockingRenewal($package)
            && $this->canRenewByStatusAndTime($package);
    }

    private function canRenewByStatusAndTime(LearningPackage $package): bool
    {
        if ($package->status === 'completed') {
            return true;
        }

        return $package->status === 'active'
            && $package->expires_at
            && now()->gte($package->expires_at->copy()->subDays(7));
    }

    private function hasBlockingRenewal(LearningPackage $package): bool
    {
        return LearningPackage::query()
            ->where('renewal_of_id', $package->id)
            ->whereNotIn('status', ['cancelled', 'payment_expired', 'refunded'])
            ->exists();
    }

    private function formatPackage(LearningPackage $package): array
    {
        $completed = $package->subjects
            ->flatMap->sessions
            ->filter(fn (PackageSession $session) => $session->booking?->status === 'completed')
            ->count();
        if ((int) $package->used_sessions !== $completed) {
            $package->used_sessions = $completed;
        }
        $latestOrder = $package->orders->first();
        $orderDetails = $latestOrder
            ? (is_array($latestOrder->class_details_snapshot)
                ? $latestOrder->class_details_snapshot
                : json_decode((string) $latestOrder->class_details_snapshot, true))
            : [];
        $orderDetails = is_array($orderDetails) ? $orderDetails : [];

        return [
            'id' => $package->id,
            'package_code' => $package->package_code,
            'renewal_of_id' => $package->renewal_of_id ? (int) $package->renewal_of_id : null,
            'status' => $package->status,
            'plan' => $package->plan,
            'education_level' => $package->education_level,
            'grade' => $package->grade,
            'learning_mode' => $package->learning_mode,
            'duration_hours' => (int) ($package->duration_hours ?? 1),
            'total_learning_hours' => $package->total_sessions * (int) ($package->duration_hours ?? 1),
            'total_sessions' => $package->total_sessions,
            'participant_count' => (int) ($package->participant_count ?? 1),
            'purchaser_participates' => (bool) ($package->purchaser_participates ?? true),
            'participant_names' => $package->participant_names ?? [],
            'participant_details' => $package->participant_details ?? [],
            'used_sessions' => $completed,
            'remaining_sessions' => max(0, $package->total_sessions - $completed),
            'subtotal_amount' => (float) $package->subtotal_amount,
            'discount_amount' => (float) $package->discount_amount,
            'total_amount' => (float) $package->total_amount,
            'payment_due_at' => $package->payment_due_at,
            'starts_at' => $package->starts_at,
            'expires_at' => $package->expires_at,
            'promotion' => $package->promotion,
            'can_renew' => $this->canRenew($package),
            'subjects' => $package->subjects->map(fn (PackageSubject $subject) => [
                'id' => $subject->id,
                'curriculum_subject_id' => $subject->curriculum_subject_id,
                'name' => $subject->subject_name,
                'chapter' => $subject->chapter,
                'curriculum_chapter_ids' => $subject->curriculum_chapter_ids ?? [],
                'learning_chapters' => PackageChapterProgress::chapters($subject->chapters),
                'learning_goal' => $subject->learning_goal,
                'allocated_sessions' => $subject->allocated_sessions,
                'unit_price' => (float) $subject->unit_price,
                'status' => $subject->status,
                'matching' => $this->formatMatchingState($subject),
                'can_request_teacher_replacement' => (bool) config('features.teacher_replacement')
                    && $package->status === 'active'
                    && $subject->status === 'active'
                    && (bool) $subject->assigned_teacher_id
                    && ! ($subject->latestTeacherReplacement
                        && in_array($subject->latestTeacherReplacement->status, TeacherReplacementRequest::OPEN_STATUSES, true))
                    && $subject->sessions->contains(fn (PackageSession $session) => $session->booking?->status === 'confirmed'
                        && $session->scheduled_end_at?->isFuture()),
                'teacher_replacement' => config('features.teacher_replacement') && $subject->latestTeacherReplacement ? [
                    'id' => $subject->latestTeacherReplacement->id,
                    'replacement_code' => $subject->latestTeacherReplacement->replacement_code,
                    'status' => $subject->latestTeacherReplacement->status,
                    'reason_code' => $subject->latestTeacherReplacement->reason_code,
                    'reason_detail' => $subject->latestTeacherReplacement->reason_detail,
                    'review_notes' => $subject->latestTeacherReplacement->review_notes,
                    'created_at' => $subject->latestTeacherReplacement->created_at,
                    'remaining_sessions' => $subject->latestTeacherReplacement->sessions->count(),
                    'can_cancel' => $subject->latestTeacherReplacement->status === 'pending_review',
                    'can_retry' => $subject->latestTeacherReplacement->status === 'no_teacher',
                    'can_reschedule' => $subject->latestTeacherReplacement->status === 'no_teacher',
                    'can_request_refund' => $subject->latestTeacherReplacement->status === 'no_teacher',
                    'sessions' => $subject->latestTeacherReplacement->sessions->map(fn ($row) => [
                        'id' => $row->id,
                        'package_session_id' => $row->package_session_id,
                        'status' => $row->status,
                        'start_at' => $row->packageSession?->scheduled_start_at,
                        'end_at' => $row->packageSession?->scheduled_end_at,
                    ])->values(),
                ] : null,
                'teacher' => $subject->assignedTeacher ? [
                    'id' => $subject->assignedTeacher->id,
                    'name' => $subject->assignedTeacher->name,
                    'avatar_url' => PublicMedia::url($subject->assignedTeacher->teacherProfile?->photo),
                ] : null,
                'sessions' => $subject->sessions->map(fn (PackageSession $session) => [
                    'id' => $session->id,
                    'sequence' => $session->sequence,
                    'start_at' => $session->scheduled_start_at,
                    'end_at' => $session->scheduled_end_at,
                    'status' => $session->booking?->status ?? $session->status,
                    'booking_id' => $session->booking_id,
                    'teacher' => $session->booking?->teacher ? [
                        'id' => $session->booking->teacher->id,
                        'name' => $session->booking->teacher->name,
                        'avatar_url' => PublicMedia::url($session->booking->teacher->teacherProfile?->photo),
                    ] : null,
                ])->values(),
            ])->values(),
            'latest_order' => $latestOrder ? [
                'id' => $latestOrder->id,
                'order_id' => $latestOrder->order_id,
                'status' => $latestOrder->status,
                'amount' => (float) $latestOrder->amount,
                'subtotal_amount' => (float) $latestOrder->subtotal_amount,
                'discount_amount' => (float) $latestOrder->discount_amount,
                'payment_rejection_reason' => $latestOrder->payment_rejection_reason,
                'subject' => $orderDetails['subject'] ?? 'Paket belajar',
                'type' => ucfirst((string) ($orderDetails['method'] ?? $package->learning_mode)).' · Paket Belajar',
                'tutor_name' => $orderDetails['teacher_name'] ?? 'Dicari setelah pembayaran',
                'package_name' => $orderDetails['package_name'] ?? $package->plan?->name,
                'order_kind' => 'package',
                'payment_provider' => $latestOrder->payment_provider,
            ] : null,
            'latest_refund' => $latestOrder?->refund ? array_merge(
                $latestOrder->refund->toArray(),
                RefundStatusPresenter::present($latestOrder->refund),
                [
                    'wallet_funded_amount' => $latestOrder->refund->tenderBreakdown()['wallet_funded_amount'],
                    'external_funded_amount' => $latestOrder->refund->tenderBreakdown()['external_funded_amount'],
                ]
            ) : null,
        ];
    }

    private function formatMatchingState(PackageSubject $subject): array
    {
        $bookingRequest = $subject->bookingRequest;
        if (! $bookingRequest) {
            return ['can_retry' => false, 'can_change_schedule' => false];
        }
        $logs = $bookingRequest->relationLoaded('matchingOperationLogs')
            ? $bookingRequest->matchingOperationLogs
            : $bookingRequest->matchingOperationLogs()->get();
        $latestFailure = $bookingRequest->relationLoaded('latestMatchingExhaustion')
            ? $bookingRequest->latestMatchingExhaustion
            : $bookingRequest->latestMatchingExhaustion()->first();
        $manualRestarts = $logs->where('action', 'search_restarted')->count();
        $radius = $bookingRequest->learning_mode === 'offline' ? (int) ($bookingRequest->search_radius_km ?? 3) : null;
        $nextRadius = $bookingRequest->learning_mode === 'offline'
            ? match ($radius) {
                3 => 5, 5 => 8, 8 => 12, default => null
            }
        : null;
        $isNoTeacher = $bookingRequest->status === 'no_teacher';
        $canChangeSchedule = in_array($bookingRequest->status, ['no_teacher', 'matching', 'teacher_pending'], true);
        $canRetry = $isNoTeacher && ($nextRadius !== null || $manualRestarts < 1);
        $metadata = $latestFailure?->metadata ?? [];
        $matchingService = app(TeacherMatchingService::class);
        $startedAt = $matchingService->searchStartedAt($bookingRequest);
        $searchDeadline = $matchingService->searchDeadline($bookingRequest);
        $searchAgeSeconds = $matchingService->searchAgeSeconds($bookingRequest);

        $activeOffers = $bookingRequest->relationLoaded('offers')
            ? $bookingRequest->offers->filter(fn (TeacherOffer $offer) => $offer->status === 'pending'
                && $offer->expires_at?->isFuture())
            : $bookingRequest->offers()->where('status', 'pending')->where('expires_at', '>', now())->get();
        $activeOfferCount = $activeOffers->count();
        $nearestDeadline = $activeOffers->min('expires_at');
        $searchState = match (true) {
            $isNoTeacher && $bookingRequest->next_matching_at?->isFuture() => 'retry_scheduled',
            $isNoTeacher => 'action_required',
            $activeOfferCount > 0 => 'offers_pending',
            default => 'matching',
        };

        return [
            'status' => $bookingRequest->status,
            'reason_code' => $metadata['code'] ?? null,
            'message' => $latestFailure?->reason,
            'recommended_action' => $metadata['recommended_action'] ?? null,
            'search_radius_km' => $radius,
            'next_radius_km' => $nextRadius,
            'search_started_at' => $startedAt->toIso8601String(),
            'search_expires_at' => $searchDeadline->toIso8601String(),
            'search_window_minutes' => $matchingService->searchWindowMinutes(),
            'search_age_minutes' => round($searchAgeSeconds / 60, 2),
            'search_age_seconds' => $searchAgeSeconds,
            'active_offer_count' => $activeOfferCount,
            'search_state' => $searchState,
            'teacher_response_deadline' => $nearestDeadline?->toIso8601String(),
            'next_matching_at' => $bookingRequest->next_matching_at?->toIso8601String(),
            'can_retry' => $canRetry,
            'retry_label' => $canRetry ? ($nextRadius ? "Perluas ke {$nextRadius} km" : 'Cek tutor baru') : null,
            'can_change_schedule' => $canChangeSchedule,
            'manual_restart_used' => $manualRestarts >= 1,
        ];
    }

    private function publicCachedResponse(Request $request, mixed $payload, int $maxAgeSeconds)
    {
        $response = response()->json($payload);
        $response->setEtag(sha1((string) json_encode($payload)));
        $response->setPublic();
        $response->setMaxAge($maxAgeSeconds);
        $response->headers->addCacheControlDirective('stale-while-revalidate', '30');
        $response->isNotModified($request);

        return $response;
    }

    private function compactLabel($values): ?string
    {
        $items = collect($values)->filter()->map(fn ($value) => trim((string) $value))->unique()->values();
        if ($items->isEmpty()) {
            return null;
        }
        $first = (string) $items->first();

        return $items->count() > 1 ? $first.' +'.($items->count() - 1) : $first;
    }
}
