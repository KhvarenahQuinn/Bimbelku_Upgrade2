<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\LearningPackage;
use App\Models\MatchingOperationLog;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PackageRenewal;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\PaymentSetting;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\Refund;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\PaymentWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PackageCheckoutService
{
    public function __construct(
        private readonly TeacherMatchingService $matchingService,
        private readonly PackageEarningsAllocation $earningsAllocation,
        private readonly ScheduleReleaseService $scheduleRelease,
    ) {}

    public function calculateDiscount(
        Promotion $promotion,
        int $subtotal,
        int $planId,
        string $level,
        array $subjects,
        string $mode,
        User $student,
        ?PromotionClaim $claim = null
    ): int {
        abort_unless($promotion->isAvailable(), 422, 'Promo belum aktif atau sudah berakhir.');
        abort_if($subtotal < (int) $promotion->minimum_purchase, 422, 'Minimal pembelian promo belum terpenuhi.');

        $this->assertTarget($promotion->target_plan_ids, $planId, 'Promo tidak berlaku untuk paket ini.');
        $this->assertTarget($promotion->target_levels, $level, 'Promo tidak berlaku untuk jenjang ini.');
        $this->assertTarget($promotion->target_modes, $mode, 'Promo tidak berlaku untuk metode belajar ini.');

        $targetSubjects = $promotion->target_subjects ?? [];
        if ($targetSubjects && ! collect($subjects)->contains(
            fn (string $subject) => in_array($subject, $targetSubjects, true)
        )) {
            abort(422, 'Promo tidak berlaku untuk mata pelajaran yang dipilih.');
        }

        if ($promotion->new_students_only) {
            $hasPaidOrder = Order::query()
                ->where('user_id', $student->id)
                ->where('status', 'paid')
                ->exists();
            abort_if($hasPaidOrder, 422, 'Promo hanya berlaku bagi murid baru.');
        }

        $occupiedQuota = $promotion->claims()
            ->whereIn('status', ['available', 'reserved', 'used'])
            ->count();
        abort_if(
            $promotion->total_quota !== null
                && ($claim ? $occupiedQuota > $promotion->total_quota : $occupiedQuota >= $promotion->total_quota),
            422,
            'Kuota promo sudah habis.'
        );

        $discount = $promotion->discount_type === 'percentage'
            ? (int) floor($subtotal * min(100, (float) $promotion->discount_value) / 100)
            : (int) round((float) $promotion->discount_value);

        if ($promotion->maximum_discount !== null) {
            $discount = min($discount, (int) round((float) $promotion->maximum_discount));
        }

        return max(0, min($subtotal, $discount));
    }

    public function reservePromotion(
        Promotion $promotion,
        User $student,
        LearningPackage $package,
        ?PromotionClaim $claim = null
    ): PromotionClaim {
        return DB::transaction(function () use ($promotion, $student, $package, $claim) {
            $lockedPromotion = Promotion::query()->lockForUpdate()->find($promotion->id);
            abort_unless($lockedPromotion, 422, 'Promo tidak ditemukan atau sudah dihapus.');
            abort_unless($lockedPromotion->isAvailable(), 422, 'Promo sudah berakhir atau dinonaktifkan.');

            $reservedQuota = $lockedPromotion->claims()
                ->whereIn('status', ['available', 'reserved', 'used'])
                ->lockForUpdate()
                ->count();
            abort_if(
                $lockedPromotion->total_quota !== null
                    && ($claim ? $reservedQuota > $lockedPromotion->total_quota : $reservedQuota >= $lockedPromotion->total_quota),
                422,
                'Kuota promo baru saja habis.'
            );

            $userUsage = $lockedPromotion->claims()
                ->where('user_id', $student->id)
                ->whereIn('status', ['reserved', 'used'])
                ->count();
            abort_if($userUsage >= $lockedPromotion->per_user_limit, 422, 'Batas penggunaan promo pada akun ini sudah tercapai.');

            if ($claim) {
                $lockedClaim = PromotionClaim::query()
                    ->where('user_id', $student->id)
                    ->where('promotion_id', $lockedPromotion->id)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->findOrFail($claim->id);
                $lockedClaim->update([
                    'learning_package_id' => $package->id,
                    'status' => 'reserved',
                    'reserved_at' => now(),
                ]);

                return $lockedClaim;
            }

            return PromotionClaim::create([
                'promotion_id' => $lockedPromotion->id,
                'user_id' => $student->id,
                'learning_package_id' => $package->id,
                'status' => 'reserved',
                'claimed_at' => now(),
                'reserved_at' => now(),
            ]);
        }, 3);
    }

    public function dispatchSubject(PackageSubject $subject): ?TeacherOffer
    {
        $subject->loadMissing(['package.student', 'sessions', 'preferredTeacher.teacherProfile.subjects']);
        $request = $subject->bookingRequest;
        if (! $request) {
            return null;
        }

        $preferred = $subject->preferredTeacher;
        $offer = $preferred && $this->preferredTeacherCanReceive($preferred, $request, $subject)
            ? DB::transaction(function () use ($preferred, $request) {
                $lockedRequest = BookingRequest::query()->lockForUpdate()->findOrFail($request->id);
                if (! in_array($lockedRequest->status, ['matching', 'no_teacher'], true)) {
                    return $lockedRequest->offers()
                        ->where('status', 'pending')
                        ->latest('offered_at')
                        ->first();
                }

                $expiresAt = $this->matchingService->offerResponseDeadline($lockedRequest);
                if ($expiresAt->lte(now())) {
                    return null;
                }

                $offer = TeacherOffer::updateOrCreate(
                    [
                        'booking_request_id' => $lockedRequest->id,
                        'teacher_id' => $preferred->id,
                    ],
                    [
                        'status' => 'pending',
                        'distance_km' => null,
                        'offered_at' => now(),
                        'expires_at' => $expiresAt,
                        'reminder_sent_at' => null,
                        'responded_at' => null,
                        'rejection_reason' => null,
                    ]
                );
                $beforeStatus = $lockedRequest->status;
                $lockedRequest->update([
                    'matched_teacher_id' => $preferred->id,
                    'status' => 'teacher_pending',
                    'teacher_response_deadline' => $expiresAt,
                    'next_matching_at' => null,
                    // The row is already protected by lockForUpdate(), so use a real integer here.
                    // Passing DB::raw() through an Eloquent model update leaves an Expression
                    // object on the model and clashes with BookingRequest's integer cast.
                    'matching_attempts' => (int) $lockedRequest->matching_attempts + 1,
                ]);
                $updatedRequest = $lockedRequest->refresh();
                $this->matchingService->syncPackageMatchingStatus($updatedRequest);

                MatchingOperationLog::create([
                    'booking_request_id' => $updatedRequest->id,
                    'actor_id' => null,
                    'action' => 'offers_dispatched',
                    'reason' => 'Penawaran dikirim ke tutor pilihan pada paket perpanjangan.',
                    'before_state' => [
                        'status' => $beforeStatus,
                        'search_radius_km' => (int) $updatedRequest->search_radius_km,
                    ],
                    'after_state' => [
                        'status' => $updatedRequest->status,
                        'matched_teacher_id' => $preferred->id,
                        'teacher_response_deadline' => $expiresAt->toIso8601String(),
                        'search_expires_at' => $this->matchingService->searchDeadline($updatedRequest)->toIso8601String(),
                        'package_status' => $updatedRequest->packageSubject?->package?->status,
                    ],
                    'metadata' => [
                        'source' => 'preferred_teacher',
                        'offer_id' => $offer->id,
                        'teacher_id' => $preferred->id,
                        'offer_count' => 1,
                        'package_subject_id' => $updatedRequest->package_subject_id,
                    ],
                ]);
                Notification::create([
                    'user_id' => $preferred->id,
                    'title' => 'Permintaan perpanjangan tutor',
                    'message' => "Murid lama ingin melanjutkan {$lockedRequest->subject_name}. Periksa seluruh jadwal paket.",
                    'type' => 'info',
                    'target_url' => '/guru/permintaan',
                ]);

                return $offer;
            }, 3)
            : $this->matchingService->dispatchNextOffer($request);

        // dispatchNextOffer() already syncs package requests, but the preferred
        // tutor branch above updates the request directly. Sync once here so
        // both paths leave subject and package on teacher_pending/no_teacher.
        $this->matchingService->syncPackageMatchingStatus($request->fresh());

        return $offer;
    }

    public function acceptPackageOffer(TeacherOffer $teacherOffer, User $teacher): array
    {
        return DB::transaction(function () use ($teacherOffer, $teacher) {
            $request = BookingRequest::query()
                ->with('packageSubject.sessions')
                ->lockForUpdate()
                ->findOrFail($teacherOffer->booking_request_id);
            $offer = TeacherOffer::query()->lockForUpdate()->findOrFail($teacherOffer->id);
            abort_unless($offer->teacher_id === $teacher->id, 403);
            abort_unless($offer->status === 'pending', 422, 'Penawaran ini sudah diproses.');
            abort_if($offer->expires_at->isPast(), 422, 'Batas waktu jawaban sudah berakhir.');
            $subject = PackageSubject::query()
                ->with(['sessions', 'package.subjects'])
                ->lockForUpdate()
                ->findOrFail($request->package_subject_id);
            $package = LearningPackage::query()->lockForUpdate()->findOrFail($subject->learning_package_id);
            abort_if($package->learning_program_id && $package->subjects()
                ->whereKeyNot($subject->id)->where('assigned_teacher_id', $teacher->id)->exists(), 422,
                'Tutor ini sudah mengajar mapel lain dalam program yang sama. Tiap mapel memakai tutor berbeda.');

            abort_unless(
                $request->status === 'teacher_pending',
                422,
                'Permintaan sudah diterima tutor lain, dialihkan, atau dibatalkan.'
            );
            abort_unless(in_array($package->status, ['matching', 'teacher_pending', 'no_teacher'], true), 422, 'Paket tidak lagi menunggu tutor.');

            $profile = TeacherProfile::query()
                ->where('user_id', $teacher->id)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $teacher->status === 'active'
                    && $profile?->verified_at
                    && $profile?->is_accepting_requests,
                422,
                'Profil tutor sedang tidak dapat menerima permintaan.'
            );
            $profile->setRelation('user', $teacher);
            abort_if(
                $error = $this->matchingService->compatibilityError($profile, $request),
                422,
                $error
            );

            foreach ($subject->sessions as $session) {
                abort_if(
                    $this->matchingService->teacherHasConflict(
                        $teacher->id,
                        $session->scheduled_start_at,
                        $session->scheduled_end_at
                    ) || $this->teacherHasPackageConflict(
                        $teacher->id,
                        $session->scheduled_start_at,
                        $session->scheduled_end_at,
                        $subject->id
                    ),
                    422,
                    'Salah satu jadwal paket bertabrakan dengan kelas lain.'
                );
                abort_unless(
                    $this->teacherAvailableAt($teacher, $session->scheduled_start_at, $session->scheduled_end_at),
                    422,
                    'Salah satu jadwal paket tidak termasuk jam tersedia tutor.'
                );
            }

            $offer->update(['status' => 'accepted', 'responded_at' => now()]);
            $closedTeacherIds = $request->offers()
                ->whereKeyNot($offer->id)
                ->where('status', 'pending')
                ->pluck('teacher_id');
            $request->offers()->whereKeyNot($offer->id)->where('status', 'pending')->update([
                'status' => 'cancelled',
                'responded_at' => now(),
            ]);
            $closedTeacherIds->each(fn (int $teacherId) => Notification::create([
                'user_id' => $teacherId,
                'title' => 'Permintaan sudah diambil tutor lain',
                'message' => "Permintaan {$request->subject_name} sudah diterima tutor lain. Tidak ada tindakan yang perlu dilakukan.",
                'type' => 'info',
                'target_url' => '/guru/permintaan?scope=history',
            ]));
            $request->update([
                'status' => 'teacher_selected',
                'matched_teacher_id' => $teacher->id,
                'teacher_response_deadline' => null,
                'next_matching_at' => null,
                'teacher_decision_deadline' => null,
            ]);
            $subject->update([
                'assigned_teacher_id' => $teacher->id,
                'status' => 'accepted',
            ]);
            PackageRenewal::query()
                ->where('new_package_id', $package->id)
                ->where('old_teacher_id', $teacher->id)
                ->where('status', 'requested')
                ->update(['status' => 'tutor_accepted']);
            $profile->update([
                'no_response_streak' => 0,
                'no_response_window_started_at' => null,
            ]);

            $package->refresh()->load('subjects');
            $allAccepted = $package->subjects->every(fn (PackageSubject $item) => $item->status === 'accepted');
            if (! $allAccepted) {
                $package->update(['status' => 'teacher_pending']);
                Notification::create([
                    'user_id' => $package->student_id,
                    'title' => 'Tutor paket ditemukan',
                    'message' => "{$teacher->name} menerima {$subject->subject_name}. Pencarian mapel lain masih berjalan.",
                    'type' => 'success',
                    'target_url' => '/student/packages',
                ]);

                return ['package' => $package->fresh('subjects.assignedTeacher'), 'package_activated' => false];
            }

            $this->activateMatchedPackage($package);

            return [
                'package' => $package->fresh(['subjects.assignedTeacher', 'orders']),
                'order' => $package->orders()->latest()->first(),
                'package_activated' => true,
            ];
        }, 3);
    }

    public function activatePaidPackage(Order $order, ?User $admin): string
    {
        $subjectIds = DB::transaction(function () use ($order, $admin) {
            $lockedOrder = Order::query()
                ->with('learningPackage.subjects.sessions')
                ->lockForUpdate()
                ->findOrFail($order->id);
            $package = LearningPackage::query()->lockForUpdate()->findOrFail($lockedOrder->learning_package_id);
            abort_unless($lockedOrder->status === 'submitted', 422, 'Pembayaran ini sudah diproses.');

            $package->load('subjects.sessions');
            $firstSessionAt = $package->subjects
                ->flatMap->sessions
                ->sortBy('scheduled_start_at')
                ->first()
                ?->scheduled_start_at;
            if ($firstSessionAt?->lte(now())) {
                $lockedOrder->update([
                    'status' => 'refund_pending',
                    'verified_at' => now(),
                    'verified_by' => $admin?->id,
                    'payment_rejection_reason' => null,
                ]);
                $package->update(['status' => 'refund_pending', 'payment_due_at' => null]);
                foreach ($package->subjects as $subject) {
                    $subject->update(['status' => 'refund_pending']);
                    $subject->bookingRequest?->update(['status' => 'refund_pending']);
                    foreach ($subject->sessions as $session) {
                        $session->update(['status' => 'refund_pending']);
                    }
                }
                // Slot jadwal dilepas segera; murid tidak boleh tetap terblokir
                // oleh paket yang sudah masuk antrean refund.
                $this->scheduleRelease->releasePackage($package, 'refund_pending');
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
                Refund::firstOrCreate(
                    ['order_id' => $lockedOrder->id],
                    [
                        'user_id' => $lockedOrder->user_id,
                        'booking_id' => $lockedOrder->booking_id,
                        'amount' => $lockedOrder->amount,
                        'reason' => 'Pembayaran paket diverifikasi setelah sesi pertama dimulai',
                        'status' => 'pending',
                    ]
                );
                Notification::create([
                    'user_id' => $package->student_id,
                    'title' => 'Pembayaran paket masuk antrean refund',
                    'message' => 'Bukti diterima setelah sesi pertama dimulai. Dana dikembalikan penuh dan voucher tersedia kembali.',
                    'type' => 'warning',
                    'target_url' => '/student/history',
                ]);

                return [];
            }

            $lockedOrder->update([
                'status' => 'paid',
                'verified_at' => now(),
                'verified_by' => $admin?->id,
                'payment_rejection_reason' => null,
            ]);
            $package->update([
                'status' => 'matching',
                'starts_at' => null,
                'expires_at' => null,
                'payment_due_at' => null,
            ]);
            foreach ($package->subjects as $subject) {
                $subject->update(['status' => 'matching', 'assigned_teacher_id' => null]);
                $bookingRequest = $subject->bookingRequest;
                $beforeStatus = $bookingRequest?->status;
                $bookingRequest?->update([
                    'status' => 'matching',
                    'matched_teacher_id' => null,
                    'teacher_response_deadline' => null,
                    'next_matching_at' => null,
                    'payment_due_at' => null,
                    'search_radius_km' => $package->learning_mode === 'offline' ? 3 : null,
                    'search_started_at' => now(),
                    'search_expires_at' => now()->addHours($this->matchingService->maximumSearchHours()),
                ]);

                if ($bookingRequest) {
                    $updatedRequest = $bookingRequest->refresh();
                    MatchingOperationLog::create([
                        'booking_request_id' => $updatedRequest->id,
                        'actor_id' => $admin?->id,
                        'action' => 'matching_started',
                        'reason' => 'Pembayaran paket diverifikasi dan mesin pencocokan dijalankan.',
                        'before_state' => [
                            'status' => $beforeStatus,
                            'search_radius_km' => (int) $updatedRequest->search_radius_km,
                        ],
                        'after_state' => [
                            'status' => $updatedRequest->status,
                            'search_radius_km' => (int) $updatedRequest->search_radius_km,
                            'search_started_at' => $updatedRequest->search_started_at?->toIso8601String(),
                            'search_expires_at' => $this->matchingService->searchDeadline($updatedRequest)->toIso8601String(),
                            'package_status' => $package->status,
                        ],
                        'metadata' => [
                            'source' => 'package_payment_verified',
                            'package_id' => $package->id,
                            'package_subject_id' => $subject->id,
                            'search_window_minutes' => $this->matchingService->searchWindowMinutes(),
                        ],
                    ]);
                }

                foreach ($subject->sessions as $session) {
                    $session->update(['status' => 'planned', 'booking_id' => null]);
                }
            }
            $package->promotionClaims()
                ->where('status', 'reserved')
                ->update(['status' => 'used', 'used_at' => now(), 'order_id' => $lockedOrder->id]);

            Notification::create([
                'user_id' => $package->student_id,
                'title' => 'Pembayaran diterima',
                'message' => "Pembayaran {$package->package_code} diterima. Sistem mulai mencari tutor untuk setiap mata pelajaran.",
                'type' => 'success',
                'target_url' => '/student/packages',
            ]);

            return $package->subjects->pluck('id')->all();
        }, 3);

        if ($subjectIds === []) {
            return 'refund_pending';
        }

        PackageSubject::query()
            ->whereIn('id', $subjectIds)
            ->with('bookingRequest')
            ->get()
            ->each(function (PackageSubject $subject) {
                if (! $this->dispatchSubject($subject)) {
                    $subject->update(['status' => 'no_teacher']);
                }
            });

        $package = LearningPackage::query()->findOrFail($order->learning_package_id);

        return $this->matchingService->syncPackageStatus($package);
    }

    public function rejectPackagePayment(Order $order, string $reason, ?User $admin = null): void
    {
        DB::transaction(function () use ($order, $reason, $admin) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($lockedOrder->status === 'submitted', 422, 'Pembayaran ini sudah diproses.');
            $lockedOrder->update([
                'status' => 'rejected',
                'payment_rejection_reason' => $reason,
                'verified_at' => now(),
                'verified_by' => $admin?->id,
            ]);
            $lockedOrder->learningPackage?->update(['status' => 'payment_rejected']);
            $lockedOrder->learningPackage?->subjects()->update(['status' => 'payment_rejected']);
            $lockedOrder->learningPackage?->subjects()->with(['sessions', 'bookingRequest'])->get()
                ->each(function (PackageSubject $subject) {
                    $subject->sessions()->update(['status' => 'payment_rejected']);
                    $subject->bookingRequest?->update(['status' => 'payment_rejected']);
                });
            Notification::create([
                'user_id' => $lockedOrder->user_id,
                'title' => 'Pembayaran paket ditolak',
                'message' => 'Bukti pembayaran ditolak: '.$reason,
                'type' => 'warning',
                'target_url' => '/student/packages',
            ]);
        }, 3);
    }

    public function createInvoiceBeforeMatching(LearningPackage $package): Order
    {
        $xenditReady = (bool) config('xendit.enabled')
            && filled(config('xendit.secret_key'))
            && filled(config('xendit.webhook_token'));
        $paymentSettings = PaymentSetting::query()->lockForUpdate()->first();
        $legacyTransferReady = $paymentSettings
            && filled($paymentSettings->bank_name)
            && filled($paymentSettings->account_number)
            && filled($paymentSettings->account_name);
        abort_unless($xenditReady || $legacyTransferReady, 503, 'Pembayaran otomatis belum dikonfigurasi pada server.');

        $package->load(['plan', 'promotion', 'subjects.sessions', 'promotionClaims']);
        $firstSessionAt = $package->subjects
            ->flatMap->sessions
            ->sortBy('scheduled_start_at')
            ->first()
            ?->scheduled_start_at;
        $paymentDueAt = now()->addMinutes(PaymentWindow::MINUTES);
        if ($firstSessionAt) {
            $usesPreferredTutors = $package->renewal_of_id
                && $package->subjects->isNotEmpty()
                && $package->subjects->every(fn (PackageSubject $subject) => $subject->preferred_teacher_id);
            $reservedMatchingHours = $usesPreferredTutors
                ? $this->matchingService->matchingCutoffHours()
                    + max(1, (int) ceil($this->matchingService->teacherResponseMinutes() / 60))
                : $this->matchingService->maximumSearchHours()
                    + $this->matchingService->matchingCutoffHours();
            $paymentDueAt = $paymentDueAt->min(
                Carbon::parse($firstSessionAt)->subHours($reservedMatchingHours)
            );
        }
        abort_if($paymentDueAt->lte(now()), 422, 'Jadwal terlalu dekat untuk membuka tagihan paket.');

        $snapshot = [
            'flow_version' => 6,
            'learning_package_id' => $package->id,
            'package_code' => $package->package_code,
            'package_name' => $package->plan->name,
            'subject' => $package->subjects->pluck('subject_name')->join(', '),
            'teacher_name' => 'Dicari setelah pembayaran',
            'type' => 'Paket Privat',
            'method' => $package->learning_mode,
            'session_count' => $package->total_sessions,
            'duration_hours' => (int) ($package->duration_hours ?? 1),
            'total_learning_hours' => $package->total_sessions * (int) ($package->duration_hours ?? 1),
            'subtotal_amount' => (float) $package->subtotal_amount,
            'discount_amount' => (float) $package->discount_amount,
            'promotion_title' => $package->promotion?->title,
            'promotion_code' => $package->promotion?->code,
            'start_at' => $firstSessionAt?->toIso8601String(),
        ];

        $order = Order::create([
            'user_id' => $package->student_id,
            'classroom_id' => null,
            'booking_id' => null,
            'learning_package_id' => $package->id,
            'promotion_id' => $package->promotion_id,
            'subtotal_amount' => $package->subtotal_amount,
            'discount_amount' => $package->discount_amount,
            'amount' => $package->total_amount,
            'status' => 'pending',
            'class_details_snapshot' => $snapshot,
            'order_id' => 'PKG-'.now()->format('YmdHis').'-'.$package->id.'-'.random_int(10, 99),
        ]);

        foreach ($package->subjects as $subject) {
            $subject->update(['status' => 'awaiting_payment', 'assigned_teacher_id' => null]);
            $subject->sessions()->update(['status' => 'awaiting_payment', 'booking_id' => null]);
            $subject->bookingRequest?->update([
                'matched_teacher_id' => null,
                'status' => 'awaiting_payment',
                'payment_due_at' => $paymentDueAt,
                'search_started_at' => null,
                'search_expires_at' => null,
            ]);
        }

        $package->update(['status' => 'awaiting_payment', 'payment_due_at' => $paymentDueAt]);

        Notification::create([
            'user_id' => $package->student_id,
            'title' => 'Tagihan paket tersedia',
            'message' => "Periksa dan bayar paket {$package->package_code}. Pencarian tutor dimulai setelah pembayaran diterima.",
            'type' => 'success',
            'target_url' => '/student/packages',
        ]);

        return $order->fresh(['booking', 'learningPackage']);
    }

    private function activateMatchedPackage(LearningPackage $package): void
    {
        $package->load([
            'student', 'plan', 'subjects.sessions', 'subjects.bookingRequest',
            'subjects.assignedTeacher.teacherProfile', 'orders',
        ]);
        $order = $package->orders()->where('status', 'paid')->lockForUpdate()->latest()->first();
        abort_unless($order, 422, 'Pembayaran paket belum diterima.');

        $commissionPercent = 20.0;
        $durationHours = (int) ($package->duration_hours ?? 1);
        $earnings = $this->earningsAllocation->forPackage($package);
        $firstBooking = null;
        $firstSessionAt = $package->subjects->flatMap->sessions->sortBy('scheduled_start_at')->first()?->scheduled_start_at;

        foreach ($package->subjects as $subject) {
            abort_unless($subject->assigned_teacher_id, 422, 'Semua tutor harus menerima paket terlebih dahulu.');
            $subjectEarnings = $earnings[$subject->id] ?? null;
            foreach ($subject->sessions as $session) {
                $sessionEarnings = $subjectEarnings['sessions'][$session->id] ?? null;
                abort_unless($sessionEarnings, 422, 'Rincian pendapatan sesi tidak lengkap. Hubungi admin sebelum kelas diaktifkan.');
                $gross = $sessionEarnings['gross_amount'];
                $teacherNet = $sessionEarnings['teacher_net_amount'];
                $hourlyGross = $gross / $durationHours;
                $anchor = $subject->bookingRequest;
                $bookingRequest = $session->sequence === 1 && $anchor
                    ? $anchor
                    : BookingRequest::create([
                        'student_id' => $package->student_id,
                        'matched_teacher_id' => $subject->assigned_teacher_id,
                        'package_subject_id' => $subject->id,
                        'subject_name' => $subject->subject_name,
                        'curriculum_subject_id' => $subject->curriculum_subject_id,
                        'education_level' => $package->education_level,
                        'grade' => $package->grade,
                        'chapter' => $subject->chapter,
                        'learning_goal' => $subject->learning_goal,
                        'learning_mode' => $package->learning_mode,
                        'class_type' => 'private',
                        'scheduled_date' => $session->scheduled_start_at->toDateString(),
                        'start_time' => $session->scheduled_start_at->format('H:i:s'),
                        'end_time' => $session->scheduled_end_at->format('H:i:s'),
                        'duration_hours' => $durationHours,
                        'address' => $package->learning_mode === 'offline' ? $package->address : null,
                        'maps_link' => $package->learning_mode === 'offline' ? $package->maps_link : null,
                        'latitude' => $package->learning_mode === 'offline' ? $package->student->latitude : null,
                        'longitude' => $package->learning_mode === 'offline' ? $package->student->longitude : null,
                        'status' => 'confirmed',
                        'hourly_rate' => $hourlyGross,
                        'total_amount' => $gross,
                    ]);

                $bookingRequest->update([
                    'matched_teacher_id' => $subject->assigned_teacher_id,
                    'status' => 'confirmed',
                    'hourly_rate' => $hourlyGross,
                    'total_amount' => $gross,
                    'payment_due_at' => null,
                ]);
                $booking = Booking::updateOrCreate(
                    ['booking_request_id' => $bookingRequest->id],
                    [
                        'student_id' => $package->student_id,
                        'teacher_id' => $subject->assigned_teacher_id,
                        'order_id' => $order->id,
                        'start_at' => $session->scheduled_start_at,
                        'end_at' => $session->scheduled_end_at,
                        'duration_hours' => $durationHours,
                        'learning_mode' => $package->learning_mode,
                        'class_type' => 'private',
                        'hourly_rate' => $hourlyGross,
                        'total_amount' => $gross,
                        'gross_amount' => $gross,
                        'teacher_net_amount' => $teacherNet,
                        'commission_percent' => $commissionPercent,
                        'status' => 'confirmed',
                        'session_flow_version' => 'presence_confirmation_v2',
                        'payment_due_at' => null,
                        'address' => $package->learning_mode === 'offline' ? $package->address : null,
                        'maps_link' => $package->learning_mode === 'offline' ? $package->maps_link : null,
                        'payout_status' => 'locked',
                    ]
                );
                $bookingRequest->update(['booking_id' => $booking->id]);
                BookingParticipant::updateOrCreate(
                    ['booking_id' => $booking->id, 'student_id' => $package->student_id],
                    [
                        'booking_request_id' => $bookingRequest->id,
                        'order_id' => $order->id,
                        'amount' => $gross,
                        // Paket sudah dibayar, tetapi sesi belum disetujui. Jangan tandai
                        // approved pada saat aktivasi karena persetujuan baru diberikan
                        // murid setelah tutor menyelesaikan sesi.
                        'status' => 'paid',
                        'approved_at' => null,
                    ]
                );
                $session->update(['booking_id' => $booking->id, 'status' => 'scheduled']);
                $firstBooking ??= $booking;
            }
            $subject->update(['status' => 'active']);
            $subject->assignedTeacher?->teacherProfile?->increment('assignment_count');
        }

        $startsAt = $firstSessionAt ?? now();
        $package->update([
            'status' => 'active',
            'starts_at' => $startsAt,
            'expires_at' => $startsAt->copy()->addDays($package->plan->validity_days),
            'payment_due_at' => null,
        ]);
        $order->update(['booking_id' => $firstBooking?->id]);
        PackageRenewal::query()
            ->where('new_package_id', $package->id)
            ->whereIn('status', ['requested', 'tutor_accepted'])
            ->update(['status' => 'completed']);

        Notification::create([
            'user_id' => $package->student_id,
            'title' => 'Semua tutor ditemukan',
            'message' => "Paket {$package->package_code} aktif. Seluruh sesi sudah masuk ke Kelas Saya.",
            'type' => 'success',
            'target_url' => '/student/my-classes',
        ]);

        $package->subjects
            ->groupBy('assigned_teacher_id')
            ->each(function ($subjects, $teacherId) use ($package) {
                $subjectNames = $subjects->pluck('subject_name')->filter()->unique()->join(', ');
                Notification::create([
                    'user_id' => (int) $teacherId,
                    'title' => 'Kelas baru aktif',
                    'message' => "Pembayaran paket {$package->package_code} sudah diterima. Jadwal {$subjectNames} kini tersedia di Kelas Saya.",
                    'type' => 'success',
                    'target_url' => '/guru/kelas',
                ]);
            });
    }

    private function assertTarget(?array $targets, string|int $value, string $message): void
    {
        if ($targets && ! in_array($value, $targets, false)) {
            abort(422, $message);
        }
    }

    private function preferredTeacherCanReceive(User $teacher, BookingRequest $request, PackageSubject $subject): bool
    {
        if ($teacher->role !== 'teacher' || $teacher->status !== 'active') {
            return false;
        }
        $profile = $teacher->teacherProfile;
        if (! $profile?->verified_at || ! $profile->is_accepting_requests) {
            return false;
        }
        $profile->setRelation('user', $teacher);
        if ($this->matchingService->compatibilityError($profile, $request)) {
            return false;
        }

        return $subject->sessions->every(fn (PackageSession $session) => ! $this->matchingService->teacherHasConflict(
            $teacher->id,
            $session->scheduled_start_at,
            $session->scheduled_end_at
        )
            && ! $this->teacherHasPackageConflict(
                $teacher->id,
                $session->scheduled_start_at,
                $session->scheduled_end_at,
                $subject->id
            )
            && $this->teacherAvailableAt(
                $teacher,
                $session->scheduled_start_at,
                $session->scheduled_end_at
            )
        );
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
            ->whereNotIn('status', ['cancelled', 'payment_expired', 'completed', 'refund_pending', 'refunded'])
            ->where('scheduled_start_at', '<', $end)
            ->where('scheduled_end_at', '>', $start)
            ->exists();
    }

    private function teacherAvailableAt(User $teacher, Carbon $start, Carbon $end): bool
    {
        return $this->matchingService->teacherAvailableAt($teacher, $start, $end);
    }
}
