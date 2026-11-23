<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\FinancialJournal;
use App\Models\FinancialLedgerEntry;
use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FinancialLedgerService
{
    public function recordLateGatewayPaymentReceived(Order $order): FinancialJournal
    {
        $amount = round((float) ($order->gateway_expected_amount ?? $order->external_received_amount), 2);

        return $this->record(
            "order:{$order->id}:late_gateway_receipt",
            'payment_received', Order::class, $order->id,
            "Dana tagihan {$order->order_id} diterima setelah pembatalan/kedaluwarsa",
            [
                ['account' => 'platform_cash', 'side' => 'debit', 'amount' => $amount],
                ['account' => 'customer_funds', 'side' => 'credit', 'amount' => $amount],
            ]
        );
    }

    public function recordPaymentReceived(Order $order): FinancialJournal
    {
        $total = round((float) $order->amount, 2);
        $wallet = min($total, max(0, round((float) $order->wallet_applied_amount, 2)));
        $external = round(max(0, $total - $wallet), 2);
        $entries = [];
        if ($external > 0) {
            // Akun clearing hanya boleh didebit sebesar transfer manual yang
            // benar-benar dikreditkan oleh `recordExternalPaymentReceipt`.
            // Pembayaran gateway langsung masuk ke kas platform, sehingga dulu
            // membuat `customer_payment_deposits` minus sebesar volume Xendit.
            $clearing = min($external, max(0, round((float) PaymentSubmission::query()
                ->where('order_id', $order->id)
                ->where('status', 'verified')
                ->sum('received_amount'), 2)));
            $cash = round($external - $clearing, 2);
            if ($clearing > 0) {
                $entries[] = ['account' => 'customer_payment_deposits', 'side' => 'debit', 'amount' => $clearing];
            }
            if ($cash > 0) {
                $entries[] = ['account' => 'platform_cash', 'side' => 'debit', 'amount' => $cash];
            }
        }
        if ($wallet > 0) {
            $entries[] = ['account' => 'customer_wallet_liability', 'side' => 'debit', 'amount' => $wallet];
        }
        $entries[] = ['account' => 'customer_funds', 'side' => 'credit', 'amount' => $total];

        return $this->record(
            "order:{$order->id}:payment_received",
            'payment_received',
            Order::class,
            $order->id,
            "Dana tagihan {$order->order_id} diterima",
            $entries
        );
    }

    public function recordExternalPaymentReceipt(PaymentSubmission $submission): FinancialJournal
    {
        $amount = round((float) $submission->received_amount, 2);

        return $this->record(
            "payment_submission:{$submission->id}:received",
            'external_payment_received',
            PaymentSubmission::class,
            $submission->id,
            "Dana transfer pembayaran #{$submission->id} diterima",
            [
                ['account' => 'platform_cash', 'side' => 'debit', 'amount' => $amount],
                ['account' => 'customer_payment_deposits', 'side' => 'credit', 'amount' => $amount],
            ]
        );
    }

    public function recordOverpaymentCredited(Order $order): FinancialJournal
    {
        $amount = round((float) $order->payment_surplus_amount, 2);

        return $this->record(
            "order:{$order->id}:overpayment_wallet_credit",
            'overpayment_wallet_credit',
            Order::class,
            $order->id,
            "Kelebihan pembayaran {$order->order_id} masuk ke Saldo BimbelKu",
            [
                ['account' => 'customer_payment_deposits', 'side' => 'debit', 'amount' => $amount],
                ['account' => 'customer_wallet_liability', 'side' => 'credit', 'amount' => $amount],
            ]
        );
    }

    public function recordExpiredPartialPaymentCredited(Order $order): FinancialJournal
    {
        $amount = round((float) $order->external_received_amount, 2);

        return $this->record(
            "order:{$order->id}:expired_partial_wallet_credit",
            'expired_partial_wallet_credit',
            Order::class,
            $order->id,
            "Dana parsial {$order->order_id} dikembalikan ke Saldo BimbelKu",
            [
                ['account' => 'customer_payment_deposits', 'side' => 'debit', 'amount' => $amount],
                ['account' => 'customer_wallet_liability', 'side' => 'credit', 'amount' => $amount],
            ]
        );
    }

    public function recordRefundQueued(Refund $refund): FinancialJournal
    {
        return $this->record(
            "refund:{$refund->id}:queued",
            'refund_queued',
            Refund::class,
            $refund->id,
            "Dana refund pesanan {$refund->order_id} ditahan",
            [
                ['account' => 'customer_funds', 'side' => 'debit', 'amount' => $refund->amount],
                ['account' => 'refunds_payable', 'side' => 'credit', 'amount' => $refund->amount],
            ]
        );
    }

    public function recordBookingSettled(Booking $booking): FinancialJournal
    {
        $gross = (float) $booking->gross_amount;
        $net = (float) $booking->teacher_net_amount;
        $commission = max(0, $gross - $net);

        return $this->record(
            "booking:{$booking->id}:settled",
            'booking_settled',
            Booking::class,
            $booking->id,
            "Hak tutor dan komisi sesi {$booking->id} dibentuk",
            [
                ['account' => 'customer_funds', 'side' => 'debit', 'amount' => $gross],
                ['account' => 'tutor_payable', 'side' => 'credit', 'amount' => $net],
                ['account' => 'platform_revenue', 'side' => 'credit', 'amount' => $commission],
            ]
        );
    }

    public function recordPayout(Payout $payout): FinancialJournal
    {
        [$requested, $transferred, $tax] = $this->payoutAmounts($payout);
        $entries = [
            // Debit sebesar hak bruto agar `tutor_payable` benar-benar bersih;
            // pajak yang dipotong dicatat sebagai kewajiban tersendiri.
            ['account' => 'tutor_payable', 'side' => 'debit', 'amount' => $requested],
            ['account' => 'platform_cash', 'side' => 'credit', 'amount' => $transferred],
        ];
        if ($tax > 0) {
            $entries[] = ['account' => 'tax_payable', 'side' => 'credit', 'amount' => $tax];
        }

        return $this->record(
            "payout:{$payout->id}:completed",
            'payout_completed',
            Payout::class,
            $payout->id,
            "Hak tutor {$payout->user_id} dicairkan",
            $entries
        );
    }

    public function recordPayoutReversed(Payout $payout): FinancialJournal
    {
        [$requested, $transferred, $tax] = $this->payoutAmounts($payout);
        $entries = [
            ['account' => 'platform_cash', 'side' => 'debit', 'amount' => $transferred],
        ];
        if ($tax > 0) {
            $entries[] = ['account' => 'tax_payable', 'side' => 'debit', 'amount' => $tax];
        }
        $entries[] = ['account' => 'tutor_payable', 'side' => 'credit', 'amount' => $requested];

        return $this->record(
            "payout:{$payout->id}:reversed",
            'payout_reversed',
            Payout::class,
            $payout->id,
            "Payout tutor {$payout->user_id} dikembalikan oleh bank",
            $entries
        );
    }

    /**
     * Baris payout lama hanya menyimpan nominal transfer tanpa rincian pajak.
     * Jangan mengarang potongan untuk data seperti itu.
     *
     * @return array{0:float,1:float,2:float} requested, transferred, tax
     */
    private function payoutAmounts(Payout $payout): array
    {
        $transferred = round((float) $payout->amount, 2);
        $tax = round((float) ($payout->tax_amount ?? 0), 2);
        $requested = round((float) ($payout->requested_amount ?? 0), 2);
        if ($requested <= 0.009 || abs($requested - ($transferred + $tax)) > 0.009) {
            return [$transferred, $transferred, 0.0];
        }

        return [$requested, $transferred, $tax];
    }

    public function recordRefundPaid(Refund $refund): FinancialJournal
    {
        $refund->loadMissing('order');
        $breakdown = $refund->tenderBreakdown();
        $entries = [
            ['account' => 'refunds_payable', 'side' => 'debit', 'amount' => $breakdown['total_amount']],
        ];
        if ($breakdown['wallet_funded_amount'] > 0) {
            $entries[] = [
                'account' => 'customer_wallet_liability',
                'side' => 'credit',
                'amount' => $breakdown['wallet_funded_amount'],
            ];
        }
        if ($breakdown['external_funded_amount'] > 0) {
            $entries[] = [
                'account' => 'platform_cash',
                'side' => 'credit',
                'amount' => $breakdown['external_funded_amount'],
            ];
        }

        return $this->record(
            "refund:{$refund->id}:paid",
            'refund_paid',
            Refund::class,
            $refund->id,
            "Refund pesanan {$refund->order_id} dicairkan sesuai sumber pembayaran",
            $entries
        );
    }

    public function recordRefundCreditedToWallet(Refund $refund): FinancialJournal
    {
        return $this->record(
            "refund:{$refund->id}:wallet_credited",
            'refund_wallet_credited',
            Refund::class,
            $refund->id,
            "Refund pesanan {$refund->order_id} masuk ke Saldo BimbelKu",
            [
                ['account' => 'refunds_payable', 'side' => 'debit', 'amount' => $refund->amount],
                ['account' => 'customer_wallet_liability', 'side' => 'credit', 'amount' => $refund->amount],
            ]
        );
    }

    /**
     * Reversal journal for a session that did not take place. The original
     * `booking:{id}:settled` journal is immutable, so the correction is always
     * a new journal keyed on the refund.
     */
    public function recordSessionRefundAdjustment(Booking $booking, Refund $refund, float $grossDelta, float $netDelta): ?FinancialJournal
    {
        // Sesi yang belum pernah diselesaikan belum punya jurnal
        // `booking:{id}:settled`, jadi tidak ada tutor_payable atau
        // platform_revenue yang bisa dibalik. Tanpa penjagaan ini, refund sesi
        // yang belum terlaksana (mis. tutor keluar) memposting debit negatif
        // yang tidak pernah punya lawan.
        $settled = FinancialJournal::query()
            ->where('event_key', "booking:{$booking->id}:settled")
            ->exists();
        if (! $settled) {
            return null;
        }

        $gross = round($grossDelta, 2);
        $net = round(min(max(0, $netDelta), $gross), 2);
        $commission = round($gross - $net, 2);
        $bearer = in_array($refund->cost_bearer, ['tutor_share', 'platform'], true)
            ? $refund->cost_bearer
            : 'tutor_share';

        if ($bearer === 'platform') {
            $entries = [
                ['account' => 'platform_revenue', 'side' => 'debit', 'amount' => $gross],
                ['account' => 'customer_funds', 'side' => 'credit', 'amount' => $gross],
            ];
            $bearerLabel = 'platform';
        } else {
            $paid = (float) $booking->teacher_paid_amount > 0;
            $entries = [
                [
                    'account' => $paid ? 'tutor_receivable' : 'tutor_payable',
                    'side' => 'debit',
                    'amount' => $net,
                ],
                ['account' => 'platform_revenue', 'side' => 'debit', 'amount' => $commission],
                ['account' => 'customer_funds', 'side' => 'credit', 'amount' => $gross],
            ];
            $bearerLabel = $paid
                ? 'tutor_share (tutor sudah menerima pencairan)'
                : 'tutor_share (dipotong dari hak tutor)';
        }

        $notes = trim((string) $refund->decision_notes);

        return $this->record(
            "refund:{$refund->id}:adjust:{$booking->id}",
            'refund_session_adjusted',
            Booking::class,
            $booking->id,
            "Pembalik nilai sesi {$booking->id} karena refund {$refund->refund_code}; penanggung: {$bearerLabel}"
                .($notes !== '' ? "; alasan admin: {$notes}" : ''),
            $entries
        );
    }

    /**
     * Net tutor share earned on a booking after deducting every refund that is
     * booked against the tutor. Used by settlement so the stored amount always
     * matches the journals that were already posted.
     */
    public function tutorRefundedNet(Booking $booking): float
    {
        $refunds = Refund::query()
            ->where('booking_id', $booking->id)
            ->where('cost_bearer', 'tutor_share')
            ->whereIn('status', ['pending', 'paid'])
            ->get();

        $rate = (100 - (float) $booking->commission_percent) / 100;

        return round((float) $refunds->sum(fn (Refund $refund) => (float) $refund->amount * $rate), 2);
    }

    public function record(
        string $eventKey,
        string $eventType,
        ?string $referenceType,
        ?int $referenceId,
        string $description,
        array $entries
    ): FinancialJournal {
        $existing = FinancialJournal::query()->where('event_key', $eventKey)->first();
        if ($existing) {
            return $existing;
        }

        $debit = 0;
        $credit = 0;
        $normalizedEntries = [];
        foreach ($entries as $entry) {
            $cents = (int) round((float) $entry['amount'] * 100);
            if ($cents < 0 || ! in_array($entry['side'], ['debit', 'credit'], true)) {
                throw new InvalidArgumentException('Baris jurnal keuangan tidak valid.');
            }
            if ($entry['side'] === 'debit') {
                $debit += $cents;
            } else {
                $credit += $cents;
            }
            $normalizedEntries[] = [
                'account' => (string) $entry['account'],
                'side' => (string) $entry['side'],
                'amount' => number_format($cents / 100, 2, '.', ''),
                'currency' => 'IDR',
            ];
        }
        if ($debit <= 0 || $debit !== $credit) {
            throw new InvalidArgumentException('Jurnal keuangan harus seimbang.');
        }
        $entries = $normalizedEntries;

        try {
            return DB::transaction(function () use (
                $eventKey,
                $eventType,
                $referenceType,
                $referenceId,
                $description,
                $entries
            ) {
                Setting::query()
                    ->where('key', 'finance_ledger_chain_lock')
                    ->lockForUpdate()
                    ->firstOrFail();
                $previousHash = FinancialJournal::query()
                    ->latest('id')
                    ->value('entry_hash');
                $occurredAt = now()->startOfSecond();
                $actorId = Auth::id();
                $hashPayload = json_encode([
                    'previous_hash' => $previousHash,
                    'event_key' => $eventKey,
                    'event_type' => $eventType,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'actor_id' => $actorId,
                    'description' => $description,
                    'occurred_at' => $occurredAt->toISOString(),
                    'entries' => $entries,
                ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

                $journal = FinancialJournal::create([
                    'uuid' => (string) Str::uuid(),
                    'event_key' => $eventKey,
                    'event_type' => $eventType,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'actor_id' => $actorId,
                    'description' => $description,
                    'previous_hash' => $previousHash,
                    'entry_hash' => hash('sha256', (string) $hashPayload),
                    'occurred_at' => $occurredAt,
                ]);

                foreach ($entries as $entry) {
                    FinancialLedgerEntry::create([
                        'financial_journal_id' => $journal->id,
                        'account_code' => $entry['account'],
                        'side' => $entry['side'],
                        'amount' => round((float) $entry['amount'], 2),
                        'currency' => $entry['currency'],
                    ]);
                }

                return $journal->load('entries');
            }, 3);
        } catch (QueryException $exception) {
            $existing = FinancialJournal::query()->where('event_key', $eventKey)->first();
            if ($existing) {
                return $existing;
            }
            throw $exception;
        }
    }
}
