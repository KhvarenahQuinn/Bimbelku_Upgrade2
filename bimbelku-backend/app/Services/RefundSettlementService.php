<?php

namespace App\Services;

use App\Events\AutoWalletRefundProcessed;
use App\Models\CustomerWalletTransaction;
use App\Models\MatchingOperationLog;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PackageSession;
use App\Models\PromotionClaim;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RefundSettlementService
{
    public function __construct(
        private readonly CustomerWalletService $wallets,
        private readonly PartialPackageRefundService $partialPackageRefunds,
        private readonly XenditMoneyMovementService $gateway,
        private readonly ScheduleReleaseService $scheduleRelease,
    ) {}

    public function isAutomatic(Refund $refund): bool
    {
        return $refund->isAutomatic();
    }

    public function startAutomatic(Refund $refund): bool
    {
        $refund->refresh()->loadMissing('order');
        if (! $this->isAutomatic($refund)) {
            return false;
        }
        if ($refund->status === 'paid') {
            return true;
        }
        if (! in_array($refund->status, ['pending', 'failed'], true)) {
            return false;
        }
        if (! $refund->destination_selected_at) {
            return false;
        }

        if ($refund->destination_method === 'bimbelku_balance') {
            $this->settle($refund, 'bimbelku_balance');

            return true;
        }
        if ($refund->destination_method !== 'xendit_original') {
            return false;
        }

        $breakdown = $refund->tenderBreakdown();
        if ($breakdown['external_funded_amount'] <= 0.009) {
            $this->settle($refund, 'bimbelku_balance');

            return true;
        }
        if ($refund->order?->payment_provider !== 'xendit') {
            $this->notifyManualReview($refund);

            return false;
        }
        if ($refund->status === 'failed') {
            // Admin retry and any explicit resubmission reopen the refund so a
            // successful provider response can move it back to processing.
            $refund->forceFill(['status' => 'pending'])->save();
            $refund->refresh();
        }

        try {
            if ($refund->source_type === 'late_gateway_payment' && blank($refund->order->gateway_payment_request_id)) {
                // Some completion callbacks omit the original payment request.
                // Recover it server-to-server, including on scheduled retries.
                $session = app(XenditPaymentService::class)->fetchSession($refund->order);
                $refund->order->forceFill([
                    'gateway_payment_request_id' => $session['payment_request_id'] ?? data_get($session, 'payment_request.id'),
                    'xendit_payment_request_id' => $session['payment_request_id'] ?? data_get($session, 'payment_request.id'),
                    'gateway_payment_id' => $session['payment_id'] ?? data_get($session, 'payment.id') ?? $refund->order->gateway_payment_id,
                ])->save();
            }
            $this->gateway->createRefund($refund);
        } catch (\Throwable $exception) {
            report($exception);
            $refund->refresh();
            if ($refund->gatewayFailed()) {
                $this->markGatewayFailure($refund);
            } else {
                // Transient failures stay retryable, but must still show when
                // the last attempt happened.
                $refund->forceFill(['last_reconciled_at' => now()])->save();
                $this->notifyManualReview($refund);
            }

            return false;
        }
        $refund->refresh();
        if ($refund->gatewayFailed()) {
            $this->markGatewayFailure($refund);

            return false;
        }
        if (blank($refund->gateway_refund_id)) {
            $refund->forceFill([
                'status' => 'failed',
                'last_reconciled_at' => now(),
                'gateway_failure_message' => $refund->gateway_failure_message
                    ?: 'Penyedia pembayaran tidak mengembalikan referensi refund.',
            ])->save();
            $this->notifyManualReview($refund->fresh());

            return false;
        }
        if ($refund->gateway_status === 'SUCCEEDED') {
            $this->settle($refund, 'xendit_original');
        }

        return true;
    }

    /**
     * Persist a terminal provider failure on the refund row instead of
     * leaving it silently pending forever.
     */
    public function markGatewayFailure(Refund $refund, ?string $code = null, ?string $message = null): void
    {
        $refund->refresh();
        if ($refund->status === 'paid') {
            return;
        }

        $refund->forceFill([
            'status' => 'failed',
            'gateway_status' => $refund->gatewayFailed() ? $refund->gateway_status : 'FAILED',
            'gateway_failure_code' => $code ?: $refund->gateway_failure_code,
            'gateway_failure_message' => $message ?: $refund->gateway_failure_message,
            'last_reconciled_at' => now(),
        ])->save();

        $this->notifyManualReview($refund->fresh());
    }

    public function notifyManualReview(Refund $refund): void
    {
        $reason = trim((string) $refund->gateway_failure_message);
        $adminMessage = "Refund {$refund->refund_code} memerlukan penanganan manual."
            .($reason !== '' ? " Alasan: {$reason}" : '');

        Notification::firstOrCreate(['unique_key' => "refund-manual:{$refund->id}"], [
            'user_id' => $refund->user_id,
            'title' => 'Refund perlu bantuan',
            'message' => 'Pengembalian dana perlu ditangani tim BimbelKu.',
            'type' => 'warning',
            'target_url' => '/student/history',
        ]);
        if ($refund->gatewayFailed() && $refund->status !== 'paid') {
            Notification::updateOrCreate(['unique_key' => "refund-failed:{$refund->id}"], [
                'user_id' => $refund->user_id,
                'title' => 'Pengembalian dana gagal',
                'message' => 'Pengembalian ke metode pembayaran asal gagal. Buka riwayat transaksi untuk memilih Saldo BimbelKu atau meminta bantuan.',
                'type' => 'warning',
                'target_url' => '/student/history',
                'is_read' => false,
            ]);
        }
        User::query()->where('role', 'admin')->pluck('id')->each(fn (int $adminId) => Notification::updateOrCreate(['unique_key' => "refund-review:{$refund->id}:{$adminId}"], [
            'user_id' => $adminId,
            'title' => 'Refund perlu ditangani',
            'message' => $adminMessage,
            'type' => 'warning',
            'target_url' => '/admin/finance?tab=refunds',
            'is_read' => false,
        ])
        );
    }

    public function reconcile(Refund $refund): bool
    {
        $refund->refresh()->loadMissing('order');
        if ($refund->status === 'paid') {
            return true;
        }
        if (! in_array($refund->status, ['pending', 'failed'], true) || $refund->destination_method !== 'xendit_original') {
            return false;
        }

        try {
            $this->gateway->retrieveRefund($refund);
        } catch (\Throwable $exception) {
            report($exception);
            $refund->refresh();
            if ($refund->gatewayFailed()) {
                $this->markGatewayFailure($refund);
            } else {
                $isStale = ($refund->gateway_submitted_at ?: $refund->created_at)?->lt(now()->subDay());
                $refund->forceFill(['last_reconciled_at' => now()])->save();
                if ($isStale) {
                    $this->notifyManualReview($refund);
                }
            }

            return false;
        }

        $refund->refresh();
        if ($refund->gateway_status === 'SUCCEEDED') {
            $this->settle($refund, 'xendit_original');

            return true;
        }
        if ($refund->gatewayFailed()) {
            $this->markGatewayFailure($refund);

            return false;
        }
        $refund->forceFill(['last_reconciled_at' => now()])->save();
        if (($refund->gateway_submitted_at ?: $refund->created_at)?->lt(now()->subDay())) {
            $this->notifyManualReview($refund);
        }

        return true;
    }

    /**
     * Poll automatic refunds that are due for a status check.
     *
     * Webhooks and the scheduler normally settle these, but a read path can
     * call this so a missed callback never leaves a finished refund pending
     * until an admin presses "Periksa status" by hand. Refunds whose first
     * submission failed are retried here too, because a transient gateway
     * error would otherwise leave them stuck without a gateway reference.
     *
     * The age guard only prevents duplicate polls from concurrent requests;
     * it is measured in seconds so an open admin/student page still settles a
     * refund on its very next refresh.
     */
    public function reconcileDue(?int $userId = null, int $limit = 20, int $minAgeSeconds = 5): int
    {
        $refunds = Refund::query()
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->where('status', 'pending')
            ->where('destination_method', 'xendit_original')
            ->whereNotNull('destination_selected_at')
            ->where(function ($query) use ($minAgeSeconds) {
                // A refund that never got a gateway reference has nothing to
                // poll, so retry its submission on the next read/run instead
                // of making it wait for the poll interval.
                $query->whereNull('gateway_refund_id')
                    ->orWhereNull('last_reconciled_at')
                    ->orWhere('last_reconciled_at', '<=', now()->subSeconds($minAgeSeconds));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($refunds as $refund) {
            if (! $this->isAutomatic($refund)) {
                continue;
            }

            // Claim the row first so another tab polling at the same moment
            // does not submit or query the same refund twice.
            $claimed = Refund::query()
                ->whereKey($refund->id)
                ->where('status', 'pending')
                ->where(function ($query) use ($minAgeSeconds) {
                    $query->whereNull('gateway_refund_id')
                        ->orWhereNull('last_reconciled_at')
                        ->orWhere('last_reconciled_at', '<=', now()->subSeconds($minAgeSeconds));
                })
                ->update(['last_reconciled_at' => now()]);
            if ($claimed === 0) {
                continue;
            }

            $this->reconcile($refund);
            $processed++;
        }

        return $processed;
    }

    /** @return array{refund:Refund,wallet_transaction:?CustomerWalletTransaction,breakdown:array} */
    public function settle(
        Refund $refund,
        string $destinationMethod,
        ?int $actorId = null,
        ?string $proof = null,
        ?string $notes = null
    ): array {
        $walletTransaction = null;
        $breakdown = [];

        DB::transaction(function () use ($refund, $destinationMethod, $actorId, $proof, $notes, &$walletTransaction, &$breakdown) {
            $locked = Refund::query()->with([
                'order.participant.bookingRequest', 'order.cheapClassEnrollment', 'booking',
            ])->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status === 'paid') {
                return;
            }
            abort_unless(in_array($locked->status, ['pending', 'failed'], true), 422, 'Refund ini sudah tidak dapat diproses.');
            abort_unless(in_array($destinationMethod, ['xendit_original', 'bank_transfer', 'bimbelku_balance'], true), 422, 'Tujuan refund tidak valid.');

            $breakdown = $locked->tenderBreakdown();
            if ($destinationMethod === 'xendit_original') {
                abort_if($breakdown['external_funded_amount'] <= 0.009, 422, 'Refund ini tidak memiliki dana eksternal.');
                abort_unless($locked->gateway_status === 'SUCCEEDED', 409, 'Refund otomatis belum berhasil.');
                if ($breakdown['wallet_funded_amount'] > 0.009) {
                    $walletTransaction = $this->wallets->creditRefund($locked, $actorId, $breakdown['wallet_funded_amount']);
                }
            } elseif ($destinationMethod === 'bimbelku_balance') {
                $walletTransaction = $this->wallets->creditRefund($locked, $actorId);
            } else {
                abort_if($breakdown['external_funded_amount'] <= 0.009 || ! $proof, 422, 'Bukti transfer refund wajib tersedia.');
                if ($breakdown['wallet_funded_amount'] > 0.009) {
                    $walletTransaction = $this->wallets->creditRefund($locked, $actorId, $breakdown['wallet_funded_amount']);
                }
            }

            $locked->update([
                'status' => 'paid',
                'destination_method' => $destinationMethod,
                'destination_selected_at' => $locked->destination_selected_at ?: now(),
                'proof' => $proof,
                'processed_by' => $actorId,
                'processed_at' => now(),
                'notes' => $notes,
                'gateway_processed_at' => $destinationMethod === 'xendit_original' ? now() : $locked->gateway_processed_at,
            ]);

            if ($locked->source_type !== 'teacher_replacement') {
                $locked->order->update(['status' => 'refunded']);
                $this->restorePromotionClaim($locked->order, (string) $locked->reason);
                $locked->order->participant?->update(['status' => 'refunded']);
                $locked->order->participant?->bookingRequest?->update(['status' => 'refunded']);
                $locked->order->cheapClassEnrollment?->update(['status' => 'refunded']);

                $package = $locked->order->learningPackage;
                if ($package && in_array($package->status, [
                    'refund_pending', 'no_teacher', 'matching', 'teacher_pending', 'cancelled', 'payment_expired', 'refunded',
                ], true)) {
                    $package->update(['status' => 'refunded']);
                    $package->subjects()->update(['status' => 'refunded']);
                    $this->scheduleRelease->releasePackage($package, 'refunded');
                } elseif ($locked->booking) {
                    // A single session refund must only free that session's slot.
                    $this->scheduleRelease->releaseBooking($locked->booking, 'refunded');
                    PackageSession::query()
                        ->where('booking_id', $locked->booking->id)
                        ->update([
                            'booking_id' => null,
                            'status' => 'refunded',
                        ]);
                }
            } else {
                $this->partialPackageRefunds->completeTeacherReplacementRefund($locked->fresh());
            }

            $remaining = $locked->booking?->refunds()->where('status', 'pending')->count() ?? 0;
            if ($locked->booking && $remaining === 0) {
                $settled = ['approved', 'no_show_confirmed', 'refunded', 'cancelled', 'teacher_rejected'];
                $unsettled = $locked->booking->participants()->whereNotIn('status', $settled)->exists();
                $earning = $locked->booking->participants()->whereIn('status', ['approved', 'no_show_confirmed'])->count();
                $gross = $earning * (float) $locked->booking->total_amount;
                $net = round($gross * (100 - (float) $locked->booking->commission_percent) / 100);
                $locked->booking->update([
                    'status' => $unsettled ? 'partially_refunded' : ($earning > 0 ? 'completed' : 'refunded'),
                    'gross_amount' => $gross,
                    'teacher_net_amount' => $net,
                    'payout_status' => $unsettled ? 'locked' : ($earning > 0 ? 'ready' : 'cancelled'),
                ]);
            }

            $message = match ($destinationMethod) {
                'bimbelku_balance' => 'Refund sudah masuk ke Saldo BimbelKu.',
                'xendit_original' => 'Refund eksternal berhasil dikembalikan otomatis ke metode pembayaran asal.'.($breakdown['wallet_funded_amount'] > 0.009 ? ' Bagian Saldo BimbelKu juga sudah dikembalikan ke saldo.' : ''),
                default => 'Refund lama telah dicatat selesai.',
            };

            if ($destinationMethod === 'bimbelku_balance') {
                event('auto_wallet_refund_processed', [$locked->fresh(), $walletTransaction]);
                event(new AutoWalletRefundProcessed($locked->fresh(), $walletTransaction));

                $bookingRequest = $locked->order?->learningPackage?->subjects()->first()?->bookingRequest
                    ?? $locked->booking?->bookingRequest
                    ?? $locked->order?->participant?->bookingRequest;

                if ($bookingRequest) {
                    MatchingOperationLog::create([
                        'booking_request_id' => $bookingRequest->id,
                        'actor_id' => $actorId,
                        'action' => 'auto_wallet_refund_processed',
                        'reason' => 'Refund otomatis ke Saldo BimbelKu berhasil diproses.',
                        'before_state' => ['status' => 'refund_pending'],
                        'after_state' => [
                            'status' => 'refunded',
                            'wallet_balance' => $walletTransaction?->balance_after,
                            'refund_id' => $locked->id,
                        ],
                        'metadata' => [
                            'refund_id' => $locked->id,
                            'amount' => $locked->amount,
                            'destination' => 'bimbelku_balance',
                        ],
                    ]);
                }
            }
            Notification::create([
                'user_id' => $locked->user_id,
                'title' => $destinationMethod === 'bimbelku_balance' ? 'Refund masuk ke Saldo BimbelKu' : 'Refund selesai',
                'message' => $message,
                'type' => 'success',
                'target_url' => '/student/history',
                'unique_key' => "refund-completed:{$locked->id}",
            ]);
        }, 3);

        return ['refund' => $refund->fresh(), 'wallet_transaction' => $walletTransaction, 'breakdown' => $breakdown ?: $refund->tenderBreakdown()];
    }

    private function restorePromotionClaim(Order $order, string $reason): void
    {
        if (! in_array(mb_strtolower(trim($reason)), ['keadaan darurat tutor', 'keberatan murid disetujui', 'tutor tidak hadir'], true) || ! $order->learning_package_id) {
            return;
        }
        PromotionClaim::query()->where('order_id', $order->id)->where('status', 'used')->update([
            'status' => 'available', 'order_id' => null, 'learning_package_id' => null,
            'used_at' => null, 'released_at' => now(),
        ]);
    }
}
