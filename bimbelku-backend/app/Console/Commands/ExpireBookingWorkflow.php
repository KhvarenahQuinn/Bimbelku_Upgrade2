<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PackageRenewal;
use App\Models\Setting;
use App\Models\TeacherOffer;
use App\Services\CheapClassService;
use App\Services\PaymentReconciliationService;
use App\Services\TeacherMatchingService;
use App\Support\CheapClassSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireBookingWorkflow extends Command
{
    protected $signature = 'bookings:expire';

    protected $description = 'Menjaga tenggat pencarian tutor, pembayaran paket, sesi, dan keberatan BimbelKu';

    public function handle(
        TeacherMatchingService $matchingService,
        CheapClassService $cheapClassService,
        PaymentReconciliationService $paymentReconciliation
    ): int {
        $stats = [
            'cheap_classes' => CheapClassSchema::status()['ready']
                ? collect($cheapClassService->maintain())->sum()
                : 0,
            'offer_reminders' => $this->sendOfferReminders(),
            'offers' => $this->expireOffers($matchingService),
            'matching' => $this->resumeMatching($matchingService),
            'matching_history' => $this->archivePastMatching(),
            'package_payments' => $this->expirePackagePayments($paymentReconciliation),
            'started' => $this->startDueBookings(),
            'reviews' => $this->queueCompletionReviews(),
        ];

        Setting::query()->updateOrCreate(
            ['key' => 'booking_workflow_last_heartbeat_at'],
            ['value' => now()->toIso8601String()]
        );
        $this->info(collect($stats)->map(fn ($value, $key) => "{$key}: {$value}")->implode('; '));

        return self::SUCCESS;
    }

    private function sendOfferReminders(): int
    {
        $reminderMinutes = min(55, max(5, (int) (
            Setting::query()->where('key', 'teacher_offer_reminder_minutes')->value('value') ?? 30
        )));
        $count = 0;

        TeacherOffer::query()
            ->where('status', 'pending')
            ->whereNull('reminder_sent_at')
            ->where('offered_at', '<=', now()->subMinutes($reminderMinutes))
            ->where('expires_at', '>', now())
            ->with('bookingRequest')
            ->chunkById(100, function ($offers) use (&$count) {
                foreach ($offers as $offer) {
                    DB::transaction(function () use ($offer, &$count) {
                        $locked = TeacherOffer::query()->lockForUpdate()->find($offer->id);
                        if (! $locked || $locked->status !== 'pending' || $locked->reminder_sent_at || $locked->expires_at->isPast()) {
                            return;
                        }
                        $locked->update(['reminder_sent_at' => now()]);
                        Notification::updateOrCreate(
                            ['unique_key' => "offer-reminder:{$locked->id}:{$locked->teacher_id}"],
                            [
                                'user_id' => $locked->teacher_id,
                                'title' => 'Pengingat permintaan bimbel',
                                'message' => 'Penawaran masih menunggu jawabanmu. Pilih Terima atau Tolak sebelum batas waktu agar murid mendapat kepastian.',
                                'type' => 'warning',
                                'target_url' => '/guru/permintaan',
                                'is_read' => false,
                            ]
                        );
                        $count++;
                    }, 3);
                }
            });

        return $count;
    }

    private function expireOffers(TeacherMatchingService $matchingService): int
    {
        $count = 0;
        TeacherOffer::query()
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->with('bookingRequest')
            ->chunkById(100, function ($offers) use ($matchingService, &$count) {
                foreach ($offers as $offer) {
                    $matchingService->expireOfferAndContinue($offer);
                    $this->syncPackageMatchingStatus($offer->bookingRequest?->fresh());
                    $count++;
                }
            });

        return $count;
    }

    private function resumeMatching(TeacherMatchingService $matchingService): int
    {
        $count = 0;
        BookingRequest::query()
            ->where(function ($statuses) {
                $statuses->whereIn('status', ['matching', 'teacher_pending'])
                    ->orWhere(function ($waiting) {
                        $waiting->where('status', 'no_teacher')
                            ->whereNotNull('next_matching_at')
                            ->where('next_matching_at', '<=', now());
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($requests) use ($matchingService, &$count) {
                foreach ($requests as $bookingRequest) {
                    $offer = $matchingService->dispatchNextOffer($bookingRequest);
                    $this->syncPackageMatchingStatus($bookingRequest->fresh());
                    if ($offer?->wasRecentlyCreated) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function expirePackagePayments(PaymentReconciliationService $paymentReconciliation): int
    {
        $count = 0;
        Order::query()
            ->whereNotNull('learning_package_id')
            ->whereIn('status', ['pending', 'rejected', 'partially_paid'])
            ->whereHas('learningPackage', fn ($query) => $query
                ->whereNotNull('payment_due_at')
                ->where('payment_due_at', '<=', now()))
            ->with(['learningPackage.subjects.bookingRequest.offers', 'learningPackage.subjects.sessions.booking.bookingRequest', 'learningPackage.promotionClaims'])
            ->chunkById(100, function ($orders) use (&$count, $paymentReconciliation) {
                foreach ($orders as $order) {
                    DB::transaction(function () use ($order, &$count, $paymentReconciliation) {
                        $lockedOrder = Order::query()->lockForUpdate()->find($order->id);
                        if (! $lockedOrder || ! in_array($lockedOrder->status, ['pending', 'rejected', 'partially_paid'], true)) {
                            return;
                        }
                        $package = $lockedOrder->learningPackage()
                            ->with(['subjects.bookingRequest.offers', 'subjects.sessions.booking.bookingRequest', 'promotionClaims'])
                            ->lockForUpdate()
                            ->first();
                        if (! $package || ! $package->payment_due_at?->lte(now())) {
                            return;
                        }

                        if ($lockedOrder->status === 'partially_paid') {
                            $paymentReconciliation->returnExpiredPartialPayment($lockedOrder);
                        }
                        $lockedOrder->update(['status' => 'expired']);
                        $package->update(['status' => 'payment_expired', 'payment_due_at' => null]);
                        foreach ($package->subjects as $subject) {
                            $subject->update(['status' => 'payment_expired']);
                            $subjectBookingRequest = $subject->bookingRequest;
                            $subjectBookingRequest?->update([
                                'status' => 'payment_expired',
                                'matched_teacher_id' => null,
                                'teacher_response_deadline' => null,
                                'search_started_at' => null,
                                'search_expires_at' => null,
                            ]);
                            $subjectBookingRequest?->offers()
                                ->whereIn('status', ['pending', 'accepted'])
                                ->update(['status' => 'cancelled', 'responded_at' => now()]);
                            foreach ($subject->sessions as $session) {
                                $session->update(['status' => 'payment_expired']);
                                $booking = $session->booking;
                                $booking?->update(['status' => 'payment_expired', 'payout_status' => 'cancelled']);
                                $booking?->participants()->update(['status' => 'payment_expired']);
                                $bookingRequest = $booking?->bookingRequest;
                                $bookingRequest?->update(['status' => 'payment_expired']);
                                if ($bookingRequest) {
                                    $bookingRequest->offers()
                                        ->whereIn('status', ['pending', 'accepted'])
                                        ->update(['status' => 'cancelled', 'responded_at' => now()]);
                                }
                            }
                        }
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

                        Notification::updateOrCreate(
                            ['unique_key' => "package-payment-expired:{$lockedOrder->id}:{$lockedOrder->user_id}"],
                            [
                                'user_id' => $lockedOrder->user_id,
                                'title' => 'Batas pembayaran paket berakhir',
                                'message' => 'Tagihan paket kedaluwarsa karena pembayaran belum diselesaikan. Pencarian tutor belum dimulai.',
                                'type' => 'warning',
                                'target_url' => '/student/history',
                                'is_read' => false,
                            ]
                        );
                        $count++;
                    }, 3);
                }
            });

        return $count;
    }

    private function archivePastMatching(): int
    {
        $count = 0;
        BookingRequest::query()
            ->where('status', 'no_teacher')
            // Tetap beri admin/murid waktu bertindak pada hari jadwal. Data baru
            // dipindahkan ke riwayat setelah tanggal sesi benar-benar berlalu.
            ->whereDate('scheduled_date', '<', today())
            ->chunkById(100, function ($requests) use (&$count) {
                foreach ($requests as $bookingRequest) {
                    $bookingRequest->update(['status' => 'expired']);
                    $count++;
                }
            });

        return $count;
    }

    private function syncPackageMatchingStatus(?BookingRequest $bookingRequest): void
    {
        if (! $bookingRequest?->package_subject_id) {
            return;
        }
        $subject = $bookingRequest->packageSubject()
            ->with('package.subjects')
            ->first();
        if (! $subject || ! in_array($subject->package?->status, ['matching', 'teacher_pending', 'no_teacher'], true)) {
            return;
        }

        if (in_array($bookingRequest->status, ['matching', 'teacher_pending', 'no_teacher'], true)) {
            $subject->update(['status' => $bookingRequest->status]);
        }
        $subjects = $subject->package->subjects()->get();
        $packageStatus = $subjects->contains('status', 'no_teacher')
            ? 'no_teacher'
            : ($subjects->contains('status', 'accepted') || $subjects->contains('status', 'teacher_pending')
                ? 'teacher_pending'
                : 'matching');
        $subject->package->update(['status' => $packageStatus]);
    }

    private function startDueBookings(): int
    {
        // Session Flow V2 tidak pernah dimulai oleh scheduler. Status in_progress
        // hanya boleh terjadi setelah tutor siap dan murid menekan "Saya Sudah Hadir".
        $graceMinutes = max(
            30,
            (int) (Setting::where('key', 'session_checkin_after_minutes')->value('value') ?? 60)
        );
        $cutoff = now()->subMinutes($graceMinutes);
        $count = 0;

        Booking::query()
            ->where('session_flow_version', 'presence_confirmation_v2')
            ->where('status', 'confirmed')
            ->whereNull('student_confirmed_at')
            ->where('end_at', '<=', $cutoff)
            ->with(['participants.bookingRequest'])
            ->chunkById(100, function ($bookings) use (&$count) {
                foreach ($bookings as $booking) {
                    if ($this->markForAdminReview($booking, ['confirmed'])) {
                        $count++;
                    }
                }
            });

        Booking::query()
            ->where('session_flow_version', 'presence_confirmation_v2')
            ->where('status', 'in_progress')
            ->whereNull('session_ended_at')
            ->where('end_at', '<=', $cutoff)
            ->with(['participants.bookingRequest'])
            ->chunkById(100, function ($bookings) use (&$count) {
                foreach ($bookings as $booking) {
                    if ($this->markForAdminReview($booking, ['in_progress'])) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function queueCompletionReviews(): int
    {
        $count = 0;
        Booking::query()
            ->where('status', 'awaiting_student_approval')
            ->whereNotNull('objection_deadline')
            ->where('objection_deadline', '<=', now())
            ->with(['participants.bookingRequest'])
            ->chunkById(100, function ($bookings) use (&$count) {
                foreach ($bookings as $booking) {
                    if ($this->markForAdminReview($booking, ['awaiting_student_approval'])) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function markForAdminReview(Booking $booking, array $allowedStatuses): bool
    {
        return DB::transaction(function () use ($booking, $allowedStatuses) {
            $locked = Booking::query()
                ->with(['participants.bookingRequest'])
                ->lockForUpdate()
                ->find($booking->id);

            if (! $locked || ! in_array($locked->status, $allowedStatuses, true)) {
                return false;
            }

            $locked->update([
                'status' => 'admin_review_required',
                'admin_review_required_at' => now(),
            ]);

            $locked->participants
                ->whereIn('status', ['paid', 'awaiting_student_approval'])
                ->each(function ($participant) {
                    $participant->update(['status' => 'admin_review_required']);
                    $participant->bookingRequest?->update(['status' => 'admin_review_required']);
                });

            return true;
        });
    }
}
