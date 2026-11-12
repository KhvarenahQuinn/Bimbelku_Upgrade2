<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerWallet;
use App\Models\Order;
use App\Models\Refund;
use App\Services\RefundSettlementService;
use App\Support\RefundStatusPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminFinanceOperationsController extends Controller
{
    public function payments(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'pending', 'partially_paid', 'paid', 'rejected', 'refund_pending', 'refunded', 'cancelled'])],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $status = trim((string) ($validated['status'] ?? ''));

        $query = Order::query()
            ->where(function (Builder $orders) {
                $orders
                    ->whereNotNull('payment_proof')
                    ->orWhereIn('status', ['paid', 'rejected', 'refund_pending', 'refunded']);
            })
            ->with([
                'user:id,name,email',
                'verifier:id,name',
                'refund:id,order_id,status,amount,reason,destination_method,processed_at',
                'learningPackage.plan:id,name',
                'promotion:id,title,code',
                'paymentSubmissions.verifier:id,name',
                'cheapClassEnrollment.cheapClass' => fn ($classes) => $classes->withCount([
                    'enrollments as confirmed_payment_count' => fn ($items) => $items->where('status', 'confirmed'),
                ]),
            ]);

        if ($status !== '' && $status !== 'all') {
            if ($status === 'pending') {
                $query->where('status', 'submitted')->whereNotNull('payment_proof');
            } else {
                $query->where('status', $status);
            }
        }
        if ($search !== '') {
            $query->where(function (Builder $orders) use ($search) {
                $orders
                    ->where('order_id', 'like', "%{$search}%")
                    ->orWhere('sender_name', 'like', "%{$search}%")
                    ->orWhere('bank_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $users) => $users
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $orders = $query->latest('updated_at')->limit(300)->get();
        $pending = $orders
            ->filter(fn (Order $order) => $order->status === 'submitted' && filled($order->payment_proof))
            ->sortBy(fn (Order $order) => $order->payment_submitted_at?->getTimestamp() ?? $order->updated_at?->getTimestamp() ?? 0)
            ->map(fn (Order $order) => $this->paymentPayload($order))
            ->values();
        $history = $orders
            ->reject(fn (Order $order) => $order->status === 'submitted')
            ->map(fn (Order $order) => $this->paymentPayload($order))
            ->values();

        return response()->json([
            'summary' => [
                'pending_count' => Order::query()
                    ->where('status', 'submitted')
                    ->whereNotNull('payment_proof')
                    ->count(),
                'pending_amount' => round((float) Order::query()
                    ->where('status', 'submitted')
                    ->whereNotNull('payment_proof')
                    ->selectRaw('COALESCE(SUM(amount - wallet_reserved_amount), 0) AS total')
                    ->value('total'), 2),
                'accepted_30_days' => round((float) Order::query()
                    ->whereIn('status', ['paid', 'refund_pending', 'refunded'])
                    ->where('verified_at', '>=', now()->subDays(30))
                    ->selectRaw('COALESCE(SUM(amount - wallet_applied_amount), 0) AS total')
                    ->value('total'), 2),
                'rejected_30_days' => Order::query()
                    ->where('status', 'rejected')
                    ->where('updated_at', '>=', now()->subDays(30))
                    ->count(),
                'refund_pending_count' => Order::query()->where('status', 'refund_pending')->count(),
            ],
            'pending' => $pending,
            'history' => $history,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function refunds(Request $request, RefundSettlementService $settlement)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'pending', 'paid'])],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $status = trim((string) ($validated['status'] ?? ''));

        // Opening (or polling) the refund page is enough to finish refunds the
        // provider already succeeded on; no manual "Periksa status" required.
        $settlement->reconcileDue(null, 5, 10);

        $query = Refund::query()->with([
            'user:id,name,email',
            'order:id,order_id,user_id,amount,status,bank_name,sender_name,sender_account_number,class_details_snapshot,wallet_applied_amount',
            'booking.bookingRequest:id,subject_name',
            'processor:id,name',
            'walletTransaction:id,refund_id,balance_after',
        ]);
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(function (Builder $refunds) use ($search) {
                $refunds
                    ->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('order', fn (Builder $orders) => $orders->where('order_id', 'like', "%{$search}%"))
                    ->orWhereHas('user', fn (Builder $users) => $users
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $formatRefund = function (Refund $refund) {
            // Hanya tampilkan snapshot yang benar-benar dipilih murid. Data
            // rekening pengirim pembayaran bukan lagi tujuan refund otomatis.
            $bankName = $refund->destination_bank_name;
            $accountName = $refund->destination_account_name;
            $accountNumber = $refund->destination_account_number;

            $snapshot = is_array($refund->order?->class_details_snapshot)
                ? $refund->order->class_details_snapshot
                : [];
            $breakdown = $refund->tenderBreakdown();
            $needsAction = in_array($refund->status, ['pending', 'failed'], true)
                && $refund->destination_selected_at !== null
                && (in_array($refund->destination_method, ['bank_transfer', 'bimbelku_balance'], true)
                    || in_array($refund->gateway_status, ['FAILED', 'REJECTED'], true)
                    || ($refund->destination_method === 'xendit_original' && $refund->created_at?->lte(now()->subDay())));
            $actionLabel = $needsAction ? match (true) {
                $refund->destination_method === 'bank_transfer' => 'Perlu transfer',
                $refund->destination_method === 'bimbelku_balance' => 'Perlu kredit saldo',
                in_array($refund->gateway_status, ['FAILED', 'REJECTED'], true) => 'Pengiriman gagal',
                default => 'Perlu cek status',
            } : null;

            return [
                ...RefundStatusPresenter::present($refund),
                'id' => $refund->id,
                'order_id' => $refund->order?->order_id,
                'student' => $refund->user ? [
                    'id' => $refund->user->id,
                    'name' => $refund->user->name,
                    'email' => $refund->user->email,
                ] : null,
                'subject' => $refund->booking?->bookingRequest?->subject_name
                    ?? ($snapshot['subject'] ?? null),
                'amount' => (float) $refund->amount,
                'wallet_funded_amount' => $breakdown['wallet_funded_amount'],
                'external_funded_amount' => $breakdown['external_funded_amount'],
                'reason' => $refund->reason,
                'status' => $refund->status,
                'needs_action' => $needsAction,
                'action_label' => $actionLabel,
                'payment_provider' => $refund->payment_provider,
                'gateway_status' => $refund->gateway_status,
                'gateway_failure_code' => $refund->gateway_failure_code,
                'gateway_failure_message' => $refund->gateway_failure_message,
                'gateway_refund_id' => $refund->gateway_refund_id,
                'gateway_submitted_at' => $refund->gateway_submitted_at,
                'gateway_processed_at' => $refund->gateway_processed_at,
                'last_reconciled_at' => $refund->last_reconciled_at,
                'destination_method' => $refund->destination_method,
                'destination_selected_at' => $refund->destination_selected_at,
                'destination_selection_version' => (int) $refund->destination_selection_version,
                'bank_destination' => [
                    'bank_name' => $bankName,
                    'account_name' => $accountName,
                    'account_number' => $accountNumber,
                    'is_complete' => filled($bankName) && filled($accountName) && filled($accountNumber),
                ],
                'wallet_balance_after' => $refund->walletTransaction
                    ? (float) $refund->walletTransaction->balance_after
                    : null,
                'proof_url' => $refund->proof_url,
                'notes' => $refund->notes,
                'processor' => $refund->processor ? [
                    'id' => $refund->processor->id,
                    'name' => $refund->processor->name,
                ] : null,
                'processed_at' => $refund->processed_at,
                'created_at' => $refund->created_at,
                'updated_at' => $refund->updated_at,
                // Why the money is coming back and who carries the cost. The
                // admin only executes what was decided in the case center.
                'source_type' => $refund->source_type,
                'booking_id' => $refund->booking_id,
                'cost_bearer' => $refund->cost_bearer ?? 'tutor_share',
                'decided_by' => $refund->decided_by,
                'decision_notes' => $refund->decision_notes,
                'wallet_refund_amount' => (float) ($refund->wallet_refund_amount ?? 0),
                'external_refund_amount' => (float) ($refund->external_refund_amount ?? 0),
            ];
        };

        // Separate limits keep old history in its original newest-first order,
        // even when the pending queue grows. Prioritize actionable pending
        // refunds before limiting that queue.
        $pendingRefunds = (clone $query)
            ->whereIn('status', ['pending', 'failed'])
            ->orderByRaw("CASE WHEN destination_selected_at IS NOT NULL AND (destination_method IN ('bank_transfer', 'bimbelku_balance') OR gateway_status IN ('FAILED', 'REJECTED') OR (destination_method = 'xendit_original' AND created_at <= ?)) THEN 0 ELSE 1 END", [now()->subDay()])
            ->latest()
            ->limit(300)
            ->get()
            ->map($formatRefund)
            ->values();
        $historyRefunds = (clone $query)
            ->whereNotIn('status', ['pending', 'failed'])
            ->latest()
            ->limit(300)
            ->get()
            ->map($formatRefund)
            ->values();

        $recentPaidRefunds = Refund::query()
            ->with('order:id,amount,wallet_applied_amount')
            ->where('status', 'paid')
            ->where('processed_at', '>=', now()->subDays(30))
            ->get();
        $bankPaid30Days = round((float) $recentPaidRefunds
            ->whereIn('destination_method', ['bank_transfer', 'xendit_original'])
            ->sum(fn (Refund $recentRefund) => $recentRefund->tenderBreakdown()['external_funded_amount']), 2);
        $walletCredited30Days = round((float) $recentPaidRefunds
            ->sum(function (Refund $recentRefund) {
                $breakdown = $recentRefund->tenderBreakdown();

                return $recentRefund->destination_method === 'bimbelku_balance'
                    ? $breakdown['total_amount']
                    : $breakdown['wallet_funded_amount'];
            }), 2);

        return response()->json([
            'summary' => [
                'pending_count' => Refund::query()->where('status', 'pending')->count(),
                'pending_amount' => round((float) Refund::query()->where('status', 'pending')->sum('amount'), 2),
                'failed_count' => Refund::query()->where('status', 'failed')->count(),
                'bank_paid_30_days' => $bankPaid30Days,
                'wallet_credited_30_days' => $walletCredited30Days,
                'wallet_liability' => round((float) CustomerWallet::query()->selectRaw('COALESCE(SUM(current_balance + reserved_balance), 0) AS total')->value('total'), 2),
            ],
            'pending' => $pendingRefunds,
            'history' => $historyRefunds,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function reconcileRefund(Refund $refund, RefundSettlementService $settlement)
    {
        abort_unless(in_array($refund->status, ['pending', 'failed'], true) && $refund->destination_method === 'xendit_original', 422, 'Refund ini tidak memerlukan rekonsiliasi otomatis.');
        $settlement->reconcile($refund);

        return response()->json([
            'message' => 'Status refund sudah diperiksa ulang.',
            'refund' => $refund->fresh(),
        ]);
    }

    public function retryRefund(Refund $refund, RefundSettlementService $settlement)
    {
        abort_unless(
            in_array($refund->status, ['pending', 'failed'], true)
                && $refund->destination_method === 'xendit_original'
                && $settlement->isAutomatic($refund)
                && in_array($refund->gateway_status, ['FAILED', 'REJECTED', 'SUBMITTING'], true),
            422,
            'Refund ini tidak dapat dicoba ulang.'
        );
        $settlement->startAutomatic($refund);

        return response()->json([
            'message' => 'Percobaan refund sudah dikirim dengan referensi yang sama.',
            'refund' => $refund->fresh(),
        ]);
    }

    private function paymentPayload(Order $order): array
    {
        $details = is_array($order->class_details_snapshot) ? $order->class_details_snapshot : [];
        $subject = $details['subject'] ?? $details['subject_name'] ?? $order->learningPackage?->plan?->name ?? 'Pesanan belajar';
        $teacher = $details['teacher_name'] ?? null;
        $cheapEnrollment = $order->cheapClassEnrollment;
        $cheapClass = $cheapEnrollment?->cheapClass;
        $isCheapClass = ($details['kind'] ?? null) === 'cheap_class' || $order->cheap_class_enrollment_id !== null;
        $cheapClassCancelled = $isCheapClass
            && ($cheapClass?->status === 'cancelled' || $cheapEnrollment?->status === 'cancellation_pending');
        $capacityAlreadyFull = $isCheapClass
            && ! $cheapClassCancelled
            && $cheapClass
            && (int) ($cheapClass->confirmed_payment_count ?? 0) >= (int) $cheapClass->maximum_participants;
        $refundReasonIfAccepted = $cheapClassCancelled
            ? 'class_cancelled'
            : ($capacityAlreadyFull ? 'capacity_full' : null);
        $canResubmit = $isCheapClass
            && $order->status === 'rejected'
            && $cheapEnrollment?->status === 'payment_rejected'
            && $cheapEnrollment?->seat_expires_at?->isFuture()
            && $cheapClass?->registration_deadline?->isFuture()
            && in_array($cheapClass?->status, ['open', 'registration_closed', 'awaiting_verification'], true);
        $canResubmitIfRejected = $isCheapClass
            && $order->status === 'submitted'
            && $cheapEnrollment?->status === 'payment_submitted'
            && $cheapEnrollment?->seat_expires_at?->isFuture()
            && $cheapClass?->registration_deadline?->isFuture()
            && in_array($cheapClass?->status, ['open', 'registration_closed', 'awaiting_verification'], true)
            && (int) ($cheapClass->confirmed_payment_count ?? 0) < (int) $cheapClass->maximum_participants;

        return [
            'id' => $order->id,
            'order_id' => $order->order_id,
            'student' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
            ] : null,
            'title' => collect([$subject, $teacher])->filter()->join(' · '),
            'subtotal_amount' => (float) $order->subtotal_amount,
            'discount_amount' => (float) $order->discount_amount,
            'amount' => (float) $order->amount,
            'promotion' => ($order->promotion || ! empty($details['promotion_code']) || ! empty($details['promotion_title'])) ? [
                'id' => $order->promotion?->id,
                'title' => $order->promotion?->title ?? $details['promotion_title'] ?? 'Voucher',
                'code' => $order->promotion?->code ?? $details['promotion_code'],
            ] : null,
            'wallet_reserved_amount' => (float) $order->wallet_reserved_amount,
            'wallet_applied_amount' => (float) $order->wallet_applied_amount,
            'external_payment_amount' => round(max(0, (float) $order->amount - (float) ($order->status === 'submitted' ? $order->wallet_reserved_amount : $order->wallet_applied_amount)), 2),
            'external_received_amount' => (float) $order->external_received_amount,
            'payment_outstanding_amount' => (float) $order->payment_outstanding_amount,
            'payment_surplus_amount' => (float) $order->payment_surplus_amount,
            'payment_reconciliation_status' => $order->payment_reconciliation_status,
            'top_up_due_at' => $order->top_up_due_at,
            'payment_submissions' => $order->paymentSubmissions->map(fn ($submission) => [
                'id' => $submission->id,
                'sequence' => $submission->sequence,
                'status' => $submission->status,
                'received_amount' => $submission->received_amount !== null ? (float) $submission->received_amount : null,
                'rejection_reason' => $submission->rejection_reason,
                'verified_at' => $submission->verified_at,
                'verifier' => $submission->verifier ? [
                    'id' => $submission->verifier->id,
                    'name' => $submission->verifier->name,
                ] : null,
                'created_at' => $submission->created_at,
            ])->values(),
            'status' => $order->status,
            'payment_proof' => $order->payment_proof_url,
            'bank_name' => $order->bank_name,
            'sender_name' => $order->sender_name,
            'sender_account_number' => $order->sender_account_number,
            'payment_rejection_reason' => $order->payment_rejection_reason
                ?? ($details['payment_rejection_reason'] ?? null),
            'payment_submitted_at' => $order->payment_submitted_at,
            'class_start_at' => $details['start_at'] ?? null,
            'is_cheap_class' => $isCheapClass,
            'cheap_class_status' => $cheapClass?->status,
            'cheap_class_cancellation_reason' => $cheapClass?->cancellation_reason,
            'enrollment_status' => $cheapEnrollment?->status,
            'can_resubmit' => (bool) $canResubmit,
            'can_resubmit_if_rejected' => (bool) ($canResubmitIfRejected && ! $cheapClassCancelled),
            'will_refund_if_accepted' => (bool) ($order->status === 'submitted' && $refundReasonIfAccepted !== null),
            'refund_reason_if_accepted' => $order->status === 'submitted' ? $refundReasonIfAccepted : null,
            'verified_at' => $order->verified_at,
            'verifier' => $order->verifier ? [
                'id' => $order->verifier->id,
                'name' => $order->verifier->name,
            ] : null,
            'refund' => $order->refund ? [
                'id' => $order->refund->id,
                'status' => $order->refund->status,
                'amount' => (float) $order->refund->amount,
                'reason' => $order->refund->reason,
                'destination_method' => $order->refund->destination_method,
                'processed_at' => $order->refund->processed_at,
            ] : null,
            'updated_at' => $order->updated_at,
        ];
    }
}
