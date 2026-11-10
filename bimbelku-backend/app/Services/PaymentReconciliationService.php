<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Order;
use App\Models\PackageSubject;
use App\Models\PaymentSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentReconciliationService
{
    public function __construct(
        private readonly FinancialLedgerService $ledger,
        private readonly CustomerWalletService $wallets,
    ) {}

    public function recordSubmission(Order $order, ?string $proofPath, array $details): PaymentSubmission
    {
        return DB::transaction(function () use ($order, $proofPath, $details) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $sequence = ((int) $lockedOrder->paymentSubmissions()->max('sequence')) + 1;

            return PaymentSubmission::create([
                'order_id' => $lockedOrder->id,
                'sequence' => $sequence,
                'proof_path' => $proofPath,
                'sender_name' => $details['sender_name'] ?? null,
                'bank_name' => $details['bank_name'] ?? null,
                'sender_account_number' => $details['sender_account_number'] ?? null,
                'status' => 'submitted',
            ]);
        }, 3);
    }

    /**
     * @return array{result:string,received:float,outstanding:float,surplus:float}
     */
    public function verify(Order $order, float $receivedAmount, User $admin): array
    {
        return DB::transaction(function () use ($order, $receivedAmount, $admin) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($lockedOrder->status === 'submitted', 409, 'Pembayaran ini sudah diproses.');

            $submission = $lockedOrder->paymentSubmissions()
                ->where('status', 'submitted')
                ->latest('sequence')
                ->lockForUpdate()
                ->first();
            if (! $submission) {
                $submission = PaymentSubmission::create([
                    'order_id' => $lockedOrder->id,
                    'sequence' => ((int) $lockedOrder->paymentSubmissions()->max('sequence')) + 1,
                    'proof_path' => $lockedOrder->payment_proof,
                    'sender_name' => $lockedOrder->sender_name,
                    'bank_name' => $lockedOrder->bank_name,
                    'sender_account_number' => $lockedOrder->sender_account_number,
                    'status' => 'submitted',
                ]);
            }

            $receivedAmount = round($receivedAmount, 2);
            abort_if($receivedAmount <= 0, 422, 'Nominal dana masuk harus lebih besar dari nol.');
            $submission->update([
                'status' => 'verified',
                'received_amount' => $receivedAmount,
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);
            $this->ledger->recordExternalPaymentReceipt($submission->fresh());

            $externalRequired = round(max(0, (float) $lockedOrder->amount - (float) $lockedOrder->wallet_reserved_amount), 2);
            $receivedTotal = round((float) $lockedOrder->external_received_amount + $receivedAmount, 2);
            $outstanding = round(max(0, $externalRequired - $receivedTotal), 2);
            $surplus = round(max(0, $receivedTotal - $externalRequired), 2);
            $result = $outstanding > 0 ? 'underpaid' : ($surplus > 0 ? 'overpaid' : 'exact');

            $lockedOrder->update([
                'external_received_amount' => $receivedTotal,
                'payment_outstanding_amount' => $outstanding,
                'payment_surplus_amount' => $surplus,
                'payment_reconciliation_status' => $result,
                'top_up_due_at' => $outstanding > 0 ? now()->addHours($lockedOrder->cheap_class_enrollment_id ? 6 : 24) : null,
                'verified_at' => now(),
                'verified_by' => $admin->id,
            ]);

            if ($result === 'underpaid') {
                $lockedOrder->update(['status' => 'partially_paid']);
                if ($package = $lockedOrder->learningPackage) {
                    $package->update([
                        'status' => 'partially_paid',
                        'payment_due_at' => $lockedOrder->top_up_due_at,
                    ]);
                    $package->subjects()->update(['status' => 'partially_paid']);
                    $package->subjects()->with(['sessions', 'bookingRequest'])->get()
                        ->each(function (PackageSubject $subject) {
                            $subject->sessions()->update(['status' => 'partially_paid']);
                            $subject->bookingRequest?->update(['status' => 'partially_paid']);
                        });
                }
                if ($enrollment = $lockedOrder->cheapClassEnrollment) {
                    $enrollment->update([
                        'status' => 'partially_paid',
                        'seat_expires_at' => $lockedOrder->top_up_due_at,
                    ]);
                }
                Notification::updateOrCreate(
                    ['unique_key' => "payment-underpaid:{$lockedOrder->id}:{$lockedOrder->user_id}"],
                    [
                        'user_id' => $lockedOrder->user_id,
                        'title' => 'Pembayaran masih kurang',
                        'message' => 'Dana masuk Rp'.number_format($receivedTotal, 0, ',', '.')
                            .' telah dicatat. Tambahkan Rp'.number_format($outstanding, 0, ',', '.')
                            .' sebelum '.$lockedOrder->top_up_due_at->format('d/m/Y H:i').'.',
                        'type' => 'warning',
                        'target_url' => '/payment',
                        'is_read' => false,
                    ]
                );
            }

            return compact('result', 'outstanding', 'surplus') + [
                'received' => $receivedTotal,
            ];
        }, 3);
    }

    public function creditSurplusToWallet(Order $order, User $admin): float
    {
        return DB::transaction(function () use ($order, $admin) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $surplus = round(max(0, (float) $lockedOrder->payment_surplus_amount), 2);
            if ($surplus <= 0) {
                return 0.0;
            }

            $this->wallets->creditOverpayment($lockedOrder, $surplus, $admin->id);
            $this->ledger->recordOverpaymentCredited($lockedOrder->fresh());

            return $surplus;
        }, 3);
    }

    public function rejectCurrentSubmission(Order $order, string $reason, User $admin): bool
    {
        return DB::transaction(function () use ($order, $reason, $admin) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $submission = $lockedOrder->paymentSubmissions()
                ->where('status', 'submitted')
                ->latest('sequence')
                ->lockForUpdate()
                ->first();
            if ($submission) {
                $submission->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                    'verified_by' => $admin->id,
                    'verified_at' => now(),
                ]);
            }

            if ((float) $lockedOrder->external_received_amount <= 0) {
                return false;
            }

            $lockedOrder->update([
                'status' => 'partially_paid',
                'payment_rejection_reason' => $reason,
                'payment_proof' => null,
                'verified_at' => now(),
                'verified_by' => $admin->id,
            ]);
            $lockedOrder->learningPackage?->update(['status' => 'partially_paid']);
            $lockedOrder->cheapClassEnrollment?->update(['status' => 'partially_paid']);
            Notification::updateOrCreate(
                ['unique_key' => "payment-proof-rejected:{$lockedOrder->id}:{$submission?->id}:{$lockedOrder->user_id}"],
                [
                    'user_id' => $lockedOrder->user_id,
                    'title' => 'Bukti tambahan ditolak',
                    'message' => 'Dana yang sebelumnya sudah diterima tetap tercatat. Unggah bukti baru untuk melunasi sisa Rp'
                        .number_format((float) $lockedOrder->payment_outstanding_amount, 0, ',', '.').'. Alasan: '.$reason,
                    'type' => 'warning',
                    'target_url' => '/payment',
                    'is_read' => false,
                ]
            );

            return true;
        }, 3);
    }

    public function returnExpiredPartialPayment(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (
                $lockedOrder->status !== 'partially_paid'
                || (float) $lockedOrder->external_received_amount <= 0
            ) {
                return false;
            }

            $amount = round((float) $lockedOrder->external_received_amount, 2);
            $this->wallets->creditExpiredPartialPayment($lockedOrder, $amount);
            $this->ledger->recordExpiredPartialPaymentCredited($lockedOrder->fresh());
            $lockedOrder->update([
                'payment_reconciliation_status' => 'underpayment_returned',
                'payment_outstanding_amount' => 0,
                'top_up_due_at' => null,
            ]);
            Notification::updateOrCreate(
                ['unique_key' => "payment-topup-expired:{$lockedOrder->id}:{$lockedOrder->user_id}"],
                [
                    'user_id' => $lockedOrder->user_id,
                    'title' => 'Batas top-up berakhir',
                    'message' => 'Dana parsial Rp'.number_format($amount, 0, ',', '.')
                        .' dikembalikan ke Saldo BimbelKu dan dapat digunakan untuk pembayaran berikutnya.',
                    'type' => 'warning',
                    'target_url' => '/student/history',
                    'is_read' => false,
                ]
            );

            return true;
        }, 3);
    }
}
