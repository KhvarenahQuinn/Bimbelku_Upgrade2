<?php

namespace App\Services;

use App\Events\AutoWalletRefundProcessed;
use App\Models\CustomerWalletTransaction;
use App\Models\LearningPackage;
use App\Models\MatchingOperationLog;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PackageRenewal;
use App\Models\PackageSession;
use App\Models\PromotionClaim;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RefundService
{
    public function __construct(
        private readonly CustomerWalletService $wallets,
        private readonly RefundSettlementService $settlement,
        private readonly ScheduleReleaseService $scheduleRelease,
        private readonly FinancialLedgerService $ledger,
    ) {}

    public function isWalletDestination(?string $destination): bool
    {
        if (! $destination) {
            return false;
        }

        return in_array(mb_strtolower(trim($destination)), [
            'wallet',
            'saldo',
            'bimbelku_balance',
            'saldo_bimbelku',
            'saldo bimbelku',
        ], true);
    }

    public function isExternalDestination(?string $destination): bool
    {
        if (! $destination) {
            return false;
        }

        return in_array(mb_strtolower(trim($destination)), [
            'original_payment',
            'original',
            'bank',
            'bank_transfer',
            'xendit_original',
            'metode_asal',
        ], true);
    }

    public function normalizeDestinationMethod(?string $destination): string
    {
        if ($this->isWalletDestination($destination)) {
            return 'bimbelku_balance';
        }

        $lower = mb_strtolower(trim((string) $destination));
        if (in_array($lower, ['bank', 'bank_transfer'], true)) {
            return 'bank_transfer';
        }

        return 'xendit_original';
    }

    /**
     * Instantly and automatically process refund to BimbelKu Saldo (internal wallet).
     * Atomic inside DB::transaction, bypassing refund_pending state.
     *
     * @return array{refund: Refund, wallet_transaction: CustomerWalletTransaction, status: string}
     */
    public function refundToWallet(Refund $refund, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($refund, $actorId) {
            $locked = Refund::query()
                ->with(['order.learningPackage.subjects.bookingRequest', 'order.participant.bookingRequest', 'booking'])
                ->lockForUpdate()
                ->findOrFail($refund->id);

            abort_unless(in_array($locked->status, ['pending', 'failed'], true), 422, 'Refund ini sudah diproses.');

            // Credit wallet balance atomically
            $walletTransaction = $this->wallets->creditRefund($locked, $actorId);

            $locked->update([
                'status' => 'paid',
                'destination_method' => 'bimbelku_balance',
                'destination_selected_at' => $locked->destination_selected_at ?: now(),
                'processed_by' => $actorId,
                'processed_at' => now(),
                'notes' => $locked->notes ?: 'Refund otomatis ke Saldo BimbelKu.',
            ]);

            $order = $locked->order;
            if ($order) {
                $order->update(['status' => 'refunded']);
                $order->participant?->update(['status' => 'refunded']);
                $order->participant?->bookingRequest?->update(['status' => 'refunded']);
                $order->cheapClassEnrollment?->update(['status' => 'refunded']);

                $package = $order->learningPackage;
                if ($package) {
                    $package->update(['status' => 'refunded']);
                    $package->subjects()->update(['status' => 'refunded']);
                    $this->scheduleRelease->releasePackage($package, 'refunded');

                    $package->promotionClaims()
                        ->whereIn('status', ['reserved', 'used'])
                        ->update([
                            'status' => 'available',
                            'released_at' => now(),
                            'learning_package_id' => null,
                        ]);
                }
            }

            if ($locked->booking) {
                $this->scheduleRelease->releaseBooking($locked->booking, 'refunded');
                PackageSession::query()
                    ->where('booking_id', $locked->booking->id)
                    ->update([
                        'booking_id' => null,
                        'status' => 'refunded',
                    ]);
            }

            // Record financial ledger
            try {
                $this->ledger->recordRefundCreditedToWallet($locked);
            } catch (\Throwable $e) {
                report($e);
            }

            // Dispatch audit log event
            event('auto_wallet_refund_processed', [$locked->fresh(), $walletTransaction]);
            event(new AutoWalletRefundProcessed($locked->fresh(), $walletTransaction));

            // Record in MatchingOperationLog if a booking request is linked
            $bookingRequest = $order?->learningPackage?->subjects()->first()?->bookingRequest
                ?? $locked->booking?->bookingRequest
                ?? $order?->participant?->bookingRequest;

            if ($bookingRequest) {
                MatchingOperationLog::create([
                    'booking_request_id' => $bookingRequest->id,
                    'actor_id' => $actorId,
                    'action' => 'auto_wallet_refund_processed',
                    'reason' => 'Refund otomatis ke Saldo BimbelKu berhasil diproses.',
                    'before_state' => ['status' => 'refund_pending'],
                    'after_state' => [
                        'status' => 'refunded',
                        'wallet_balance' => $walletTransaction->balance_after,
                        'refund_id' => $locked->id,
                    ],
                    'metadata' => [
                        'refund_id' => $locked->id,
                        'amount' => $locked->amount,
                        'destination' => 'bimbelku_balance',
                    ],
                ]);
            }

            Notification::create([
                'user_id' => $locked->user_id,
                'title' => 'Refund masuk ke Saldo BimbelKu',
                'message' => 'Refund dana sebesar Rp '.number_format((float) $locked->amount, 0, ',', '.').' berhasil ditambahkan langsung ke Saldo BimbelKu.',
                'type' => 'success',
                'target_url' => '/student/wallet',
                'unique_key' => "refund-auto-wallet:{$locked->id}",
            ]);

            return [
                'refund' => $locked->fresh(),
                'wallet_transaction' => $walletTransaction,
                'status' => 'refunded',
            ];
        }, 3);
    }

    /**
     * Place refund into refund_pending status awaiting manual Admin verification and approval.
     */
    public function queueExternalRefund(
        Refund $refund,
        string $destination = 'original_payment',
        array $bankDetails = []
    ): Refund {
        return DB::transaction(function () use ($refund, $destination, $bankDetails) {
            $locked = Refund::query()
                ->with(['order.learningPackage'])
                ->lockForUpdate()
                ->findOrFail($refund->id);

            abort_unless(in_array($locked->status, ['pending', 'failed'], true), 422, 'Refund ini sudah diproses.');

            $method = $this->normalizeDestinationMethod($destination);

            $locked->update([
                'destination_method' => $method,
                'destination_bank_name' => $method === 'bank_transfer' ? ($bankDetails['bank_name'] ?? null) : null,
                'destination_account_name' => $method === 'bank_transfer' ? ($bankDetails['account_name'] ?? null) : null,
                'destination_account_number' => $method === 'bank_transfer' ? ($bankDetails['account_number'] ?? null) : null,
                'destination_selected_at' => now(),
                'destination_selection_version' => ((int) $locked->destination_selection_version) + 1,
                'status' => 'pending',
            ]);

            $order = $locked->order;
            if ($order && $order->status !== 'refunded') {
                $order->update(['status' => 'refund_pending']);
                $package = $order->learningPackage;
                if ($package && $package->status !== 'refunded') {
                    $package->update(['status' => 'refund_pending']);
                    $this->scheduleRelease->releasePackage($package, 'refund_pending');
                }
            }

            // Notify Admin
            User::query()
                ->where('role', 'admin')
                ->where('status', 'active')
                ->pluck('id')
                ->each(fn (int $adminId) => Notification::updateOrCreate(
                    ['unique_key' => "admin-refund-pending:{$locked->id}:{$adminId}"],
                    [
                        'user_id' => $adminId,
                        'title' => 'Refund menunggu verifikasi manual',
                        'message' => "Pengajuan refund #{$locked->refund_code} ke {$method} menunggu persetujuan admin.",
                        'type' => 'warning',
                        'target_url' => '/admin/finance?tab=refunds',
                    ]
                ));

            return $locked->fresh();
        }, 3);
    }

    /**
     * Cancel a learning package during matching / no_teacher, optionally targeting a refund destination.
     *
     * @return array{package: LearningPackage, refund: ?Refund, status: string, message: string}
     */
    public function cancelPackage(
        LearningPackage $learningPackage,
        ?string $destination = null,
        array $destinationDetails = [],
        ?int $actorId = null
    ): array {
        return DB::transaction(function () use ($learningPackage, $destination, $destinationDetails, $actorId) {
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
            $isWallet = $this->isWalletDestination($destination);
            $isExternal = $this->isExternalDestination($destination);

            $targetStatus = match (true) {
                $paidOrder && $isWallet => 'refunded',
                $paidOrder && $isExternal => 'refund_pending',
                $paidOrder => 'refund_pending',
                default => 'cancelled',
            };

            // Cancel teacher offers
            $teacherIds = collect();
            foreach ($package->subjects as $subject) {
                $subject->update(['status' => $targetStatus]);
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
                    $bookingRequest->update(['status' => $targetStatus]);
                }
            }

            // Release schedule immediately
            $this->scheduleRelease->releasePackage($package, $targetStatus);

            // Restore unused promotion
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

            $package->update(['status' => $targetStatus]);

            $refund = null;
            if ($paidOrder) {
                $refund = Refund::firstOrCreate(
                    ['order_id' => $paidOrder->id],
                    [
                        'user_id' => $package->student_id,
                        'booking_id' => $paidOrder->booking_id,
                        'amount' => $paidOrder->amount,
                        'reason' => 'Paket dibatalkan murid saat pencarian tutor',
                        'status' => 'pending',
                    ]
                );

                if ($isWallet) {
                    $this->refundToWallet($refund, $actorId);
                    $refund = $refund->fresh();
                } elseif ($isExternal) {
                    $this->queueExternalRefund($refund, $destination, $destinationDetails);
                    $refund = $refund->fresh();
                } else {
                    $paidOrder->update(['status' => 'refund_pending']);
                }
            }

            // Notify cancelled tutors
            $teacherIds->filter()->unique()->each(fn (int $teacherId) => Notification::create([
                'user_id' => $teacherId,
                'title' => 'Permintaan paket dibatalkan',
                'message' => "Murid membatalkan pencarian paket {$package->package_code}. Penawaran tidak lagi aktif.",
                'type' => 'info',
                'target_url' => '/guru/permintaan',
            ]));

            $message = match (true) {
                $isWallet => 'Pencarian dihentikan. Refund dana berhasil ditambahkan langsung ke Saldo BimbelKu.',
                $isExternal => 'Pencarian dihentikan. Pengembalian dana menunggu verifikasi manual admin.',
                $refund !== null => 'Pencarian dihentikan. Pilih dan konfirmasi tujuan refund di Riwayat Transaksi sebelum dana diproses.',
                default => 'Pencarian paket dibatalkan dan voucher dikembalikan.',
            };

            return [
                'package' => $package->fresh(),
                'refund' => $refund,
                'status' => $targetStatus,
                'message' => $message,
            ];
        }, 3);
    }
}
