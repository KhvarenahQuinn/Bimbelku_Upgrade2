<?php

namespace App\Services;

use App\Models\CheapClass;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PackageRenewal;
use App\Models\Refund;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GatewayPaymentSettlementService
{
    public function __construct(
        private readonly XenditPaymentService $xendit,
        private readonly PackageCheckoutService $packages,
        private readonly CheapClassService $cheapClasses,
        private readonly RefundSettlementService $refunds,
        private readonly CustomerWalletService $wallets,
        private readonly ScheduleReleaseService $scheduleRelease,
    ) {}

    public function settle(Order $order, array $data): void
    {
        $xendit = $this->xendit;
        $packages = $this->packages;
        $cheapClasses = $this->cheapClasses;
        Cache::lock('gateway-order-settlement:'.$order->id, 180)->block(15, function () use ($order, $data, $xendit, $packages, $cheapClasses) {
            $current = $order->fresh();
            $sessionId = Arr::get($data, 'payment_session_id') ?? Arr::get($data, 'session_id') ?? Arr::get($data, 'payment_session.id');
            $reference = Arr::get($data, 'reference_id') ?? Arr::get($data, 'payment_session.reference_id');
            if ((filled($sessionId) && filled($current->gateway_session_id) && $sessionId !== $current->gateway_session_id)
                || (filled($reference) && $reference !== $current->gateway_reference_id)) {
                throw new RuntimeException('Identitas pembayaran tidak cocok dengan checkout tagihan.');
            }
            if (in_array($current->status, ['paid', 'refund_pending', 'refunded'], true)) {
                if ($current->status === 'refund_pending' && $current->payment_reconciliation_status === 'late_payment_refund') {
                    $current->forceFill([
                        'gateway_payment_id' => Arr::get($data, 'payment_id') ?? Arr::get($data, 'payment.id') ?? $current->gateway_payment_id,
                        'gateway_payment_request_id' => Arr::get($data, 'payment_request_id') ?? Arr::get($data, 'payment_request.id') ?? $current->gateway_payment_request_id,
                        'xendit_payment_request_id' => Arr::get($data, 'payment_request_id') ?? Arr::get($data, 'payment_request.id') ?? $current->xendit_payment_request_id,
                    ])->save();
                    if ($current->refund) {
                        $this->refunds->startAutomatic($current->refund);
                    }
                }

                return;
            }

            $paidAmount = $this->paidAmount($data);
            if ($current->status !== 'submitted') {
                $expectedAmount = $current->gateway_expected_amount ?? $xendit->externalDue($current);
                // Expiry releases wallet holds. For legacy sessions without a
                // stored quote, the authenticated receipt can be smaller than
                // the invoice; refund only the money that actually arrived.
                $late = $this->mustRefund($current);
                if ($late && $current->gateway_expected_amount === null) {
                    $expectedAmount = $paidAmount <= (float) $current->amount ? $paidAmount : (float) $current->amount;
                }
                if ($paidAmount <= 0 || abs($paidAmount - $expectedAmount) > 0.009) {
                    throw new RuntimeException('Nominal pembayaran tidak sama dengan tagihan.');
                }

                DB::transaction(function () use ($current, $data, $paidAmount) {
                    $locked = Order::query()->lockForUpdate()->findOrFail($current->id);
                    if (in_array($locked->status, ['paid', 'refund_pending', 'refunded'], true)) {
                        return;
                    }
                    if ($locked->status === 'submitted' && $locked->gateway_status === 'COMPLETED') {
                        return;
                    }

                    if ($this->mustRefund($locked)) {
                        $this->recordLateReceipt($locked, $data, $paidAmount);

                        return;
                    }
                    abort_unless(in_array($locked->status, ['pending', 'rejected', 'partially_paid'], true), 409, 'Tagihan tidak lagi dapat diselesaikan.');
                    $locked->forceFill([
                        'payment_provider' => 'xendit',
                        'gateway_payment_id' => Arr::get($data, 'payment_id') ?? Arr::get($data, 'payment.id'),
                        'gateway_payment_request_id' => Arr::get($data, 'payment_request_id') ?? Arr::get($data, 'payment_request.id'),
                        'xendit_payment_request_id' => Arr::get($data, 'payment_request_id') ?? Arr::get($data, 'payment_request.id'),
                        'gateway_status' => 'COMPLETED',
                        'gateway_session_id' => Arr::get($data, 'payment_session_id') ?? Arr::get($data, 'session_id') ?? Arr::get($data, 'payment_session.id') ?? $locked->gateway_session_id,
                        'gateway_creation_uncertain' => false,
                        'gateway_paid_at' => now(),
                        'status' => 'submitted',
                        'external_received_amount' => round((float) $locked->external_received_amount + ($paidAmount > 0 ? $paidAmount : max(0, (float) $locked->amount - (float) $locked->wallet_reserved_amount)), 2),
                        'payment_outstanding_amount' => 0,
                        'payment_surplus_amount' => 0,
                        'payment_reconciliation_status' => 'exact',
                        'payment_submitted_at' => now(),
                        'payment_rejection_reason' => null,
                    ])->save();
                }, 3);
            }

            $fresh = $order->fresh();
            if ($fresh->payment_reconciliation_status === 'late_payment_refund' && $fresh->refund) {
                $this->refunds->startAutomatic($fresh->refund);

                return;
            }
            if ($fresh->status !== 'submitted') {
                return;
            }
            if ($fresh->learning_package_id) {
                $packages->activatePaidPackage($fresh, null);
            } elseif ($fresh->cheap_class_enrollment_id) {
                $result = $cheapClasses->verifyPayment($fresh, 'paid', '', null);
                if (! empty($result['class_id'])) {
                    $class = CheapClass::find($result['class_id']);
                    if ($class) {
                        $cheapClasses->finalizeIfReady($class);
                    }
                }
            }
        });
    }

    private function mustRefund(Order $order): bool
    {
        if (in_array($order->status, ['expired', 'cancelled'], true)) {
            return true;
        }
        $deadline = $order->learningPackage?->payment_due_at ?? $order->cheapClassEnrollment?->seat_expires_at;
        if ($deadline && ! $deadline->isFuture()) {
            return true;
        }
        if ($order->learningPackage && in_array($order->learningPackage->status, ['cancelled', 'payment_expired'], true)) {
            return true;
        }

        return $order->cheapClassEnrollment
            && (in_array($order->cheapClassEnrollment->status, ['cancelled', 'payment_expired', 'cancellation_pending'], true)
                || $order->cheapClassEnrollment->cheapClass?->status === 'cancelled');
    }

    private function recordLateReceipt(Order $order, array $data, float $amount): void
    {
        // A released wallet reservation is NOT part of the external refund.
        $this->wallets->releaseReserved($order, null, 'late_gateway_payment');
        $order->refresh()->forceFill([
            'payment_provider' => 'xendit',
            'gateway_payment_id' => Arr::get($data, 'payment_id') ?? Arr::get($data, 'payment.id') ?? $order->gateway_payment_id,
            'gateway_payment_request_id' => Arr::get($data, 'payment_request_id') ?? Arr::get($data, 'payment_request.id') ?? $order->gateway_payment_request_id,
            'xendit_payment_request_id' => Arr::get($data, 'payment_request_id') ?? Arr::get($data, 'payment_request.id') ?? $order->xendit_payment_request_id,
            'gateway_status' => 'COMPLETED',
            'gateway_session_id' => Arr::get($data, 'payment_session_id') ?? Arr::get($data, 'session_id') ?? Arr::get($data, 'payment_session.id') ?? $order->gateway_session_id,
            'gateway_creation_uncertain' => false,
            'gateway_expected_amount' => $amount,
            'gateway_paid_at' => now(),
            'external_received_amount' => round((float) $order->external_received_amount + $amount, 2),
            'payment_outstanding_amount' => 0,
            'payment_reconciliation_status' => 'late_payment_refund',
            'verified_at' => now(),
            'status' => 'refund_pending',
        ])->save();
        if ($package = $order->learningPackage) {
            $package->update(['status' => 'refund_pending', 'payment_due_at' => null]);
            $package->subjects()->with('bookingRequest')->get()->each(function ($subject) {
                $subject->update(['status' => 'refund_pending']);
                $subject->sessions()->update(['status' => 'cancelled']);
                $subject->bookingRequest?->update(['status' => 'cancelled']);
                $subject->bookingRequest?->offers()->whereIn('status', ['pending', 'accepted'])
                    ->update(['status' => 'cancelled', 'responded_at' => now()]);
            });
            $this->scheduleRelease->releasePackage($package, 'cancelled', 'cancelled');
            $package->promotionClaims()->where('status', 'reserved')->update([
                'status' => 'available', 'released_at' => now(), 'learning_package_id' => null,
            ]);
            PackageRenewal::query()->where('new_package_id', $package->id)
                ->whereIn('status', ['requested', 'tutor_accepted'])->update(['status' => 'cancelled']);
        }
        $order->cheapClassEnrollment?->update(['status' => 'refund_pending']);
        Refund::firstOrCreate(['order_id' => $order->id], [
            'user_id' => $order->user_id,
            'amount' => $amount,
            'wallet_refund_amount' => 0,
            'external_refund_amount' => $amount,
            'reason' => 'Pembayaran diterima setelah tagihan berakhir atau dibatalkan',
            'source_type' => 'late_gateway_payment',
            'status' => 'pending',
            'destination_method' => 'xendit_original',
            'destination_selected_at' => now(),
        ]);
        Notification::firstOrCreate(['unique_key' => "late-payment-refund:{$order->id}"], [
            'user_id' => $order->user_id,
            'title' => 'Pembayaran diterima, pengembalian dana diproses',
            'message' => 'Dana masuk setelah tagihan berakhir atau dibatalkan. Pesanan tidak diaktifkan kembali; pengembalian ke metode pembayaran asal diproses otomatis. Pantau status di Riwayat Pembayaran.',
            'type' => 'warning',
            'target_url' => '/student/history',
        ]);
    }

    private function paidAmount(array $data): float
    {
        return (float) (Arr::get($data, 'amount') ?? Arr::get($data, 'payment_details.amount') ?? Arr::get($data, 'payment.amount') ?? 0);
    }
}
