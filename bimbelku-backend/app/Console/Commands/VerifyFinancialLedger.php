<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\FinancialAuditLog;
use App\Models\FinancialJournal;
use App\Models\IdempotencyRecord;
use App\Models\Order;
use App\Models\PayoutApproval;
use App\Models\Refund;
use App\Services\WalletIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyFinancialLedger extends Command
{
    protected $signature = 'finance:verify-ledger';

    protected $description = 'Memeriksa keseimbangan, rantai hash, dan antrean keamanan keuangan';

    public function handle(WalletIntegrityService $walletIntegrity): int
    {
        $previousHash = null;
        $errors = [];

        FinancialJournal::query()
            ->with(['entries' => fn ($query) => $query->orderBy('id')])
            ->orderBy('id')
            ->each(function (FinancialJournal $journal) use (&$previousHash, &$errors) {
                if (($journal->previous_hash ?: null) !== $previousHash) {
                    $errors[] = "Rantai hash terputus pada jurnal {$journal->id}.";
                }

                $debit = 0;
                $credit = 0;
                $entries = $journal->entries->map(function ($entry) use (&$debit, &$credit) {
                    $cents = (int) round((float) $entry->amount * 100);
                    if ($entry->side === 'debit') {
                        $debit += $cents;
                    } else {
                        $credit += $cents;
                    }

                    return [
                        'account' => $entry->account_code,
                        'side' => $entry->side,
                        'amount' => number_format($cents / 100, 2, '.', ''),
                        'currency' => $entry->currency,
                    ];
                })->all();

                if ($debit <= 0 || $debit !== $credit) {
                    $errors[] = "Jurnal {$journal->id} tidak seimbang.";
                }

                $expectedHash = hash('sha256', json_encode([
                    'previous_hash' => $journal->previous_hash,
                    'event_key' => $journal->event_key,
                    'event_type' => $journal->event_type,
                    'reference_type' => $journal->reference_type,
                    'reference_id' => $journal->reference_id,
                    'actor_id' => $journal->actor_id,
                    'description' => $journal->description,
                    'occurred_at' => $journal->occurred_at->toISOString(),
                    'entries' => $entries,
                ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
                if (! hash_equals($journal->entry_hash, $expectedHash)) {
                    $errors[] = "Isi jurnal {$journal->id} tidak cocok dengan hash.";
                }

                $previousHash = $journal->entry_hash;
            });

        $previousAuditHash = null;
        FinancialAuditLog::query()
            ->orderBy('id')
            ->each(function (FinancialAuditLog $log) use (&$previousAuditHash, &$errors) {
                if (($log->previous_hash ?: null) !== $previousAuditHash) {
                    $errors[] = "Rantai audit terputus pada log {$log->id}.";
                }

                $expectedHash = hash('sha256', json_encode([
                    'previous_hash' => $log->previous_hash,
                    'request_id' => $log->request_id,
                    'actor_id' => $log->actor_id,
                    'action' => $log->action,
                    'method' => $log->method,
                    'path' => $log->route_name,
                    'status' => $log->response_status,
                    'payload' => $log->payload ?? [],
                    'created_at' => $log->created_at->toISOString(),
                ], JSON_UNESCAPED_UNICODE));
                if (! hash_equals($log->entry_hash, $expectedHash)) {
                    $errors[] = "Isi log audit {$log->id} tidak cocok dengan hash.";
                }

                $previousAuditHash = $log->entry_hash;
            });

        IdempotencyRecord::query()
            ->where('expires_at', '<=', now())
            ->delete();
        PayoutApproval::query()
            ->whereIn('status', ['pending', 'approved'])
            ->whereNull('consumed_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $errors = [...$errors, ...$this->sourceDriftErrors()];
        $errors = [...$errors, ...$walletIntegrity->errors()];

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info('Jurnal keuangan seimbang, rantai jurnal/audit valid, dan Saldo BimbelKu konsisten.');

        return self::SUCCESS;
    }

    /**
     * Jurnal yang seimbang tetap bisa salah kalau nominalnya tidak sama dengan
     * data sumbernya. Tiap pemeriksaan membandingkan angka ledger dengan tabel
     * asal supaya drift ketahuan sebelum dipakai mengambil keputusan uang.
     *
     * @return list<string>
     */
    private function sourceDriftErrors(): array
    {
        $errors = [];

        $entriesQuery = fn (string $eventType, string $referenceType, string $account, string $side) => DB::table('financial_journals as journals')
            ->join('financial_ledger_entries as entries', 'entries.financial_journal_id', '=', 'journals.id')
            ->where('journals.event_type', $eventType)
            ->where('journals.reference_type', $referenceType)
            ->where('entries.account_code', $account)
            ->where('entries.side', $side);

        foreach ($entriesQuery('booking_settled', Booking::class, 'tutor_payable', 'credit')
            ->join('bookings', 'bookings.id', '=', 'journals.reference_id')
            // Refund sesi sengaja mengurangi angka di tabel booking tanpa menulis
            // ulang jurnal yang sudah terbit, jadi sesi seperti itu dibandingkan
            // lewat jurnal penyesuaiannya sendiri.
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('financial_journals as adjustments')
                    ->where('adjustments.event_type', 'refund_session_adjusted')
                    ->where('adjustments.reference_type', Booking::class)
                    ->whereColumn('adjustments.reference_id', 'bookings.id');
            })
            ->whereRaw('ABS(entries.amount - bookings.teacher_net_amount) > 0.009')
            ->select('journals.id', 'bookings.id as booking_id', 'entries.amount', 'bookings.teacher_net_amount')
            ->limit(10)
            ->get() as $row) {
            $errors[] = "Jurnal {$row->id} mengkredit tutor_payable {$row->amount} untuk sesi {$row->booking_id}, padahal teacher_net_amount sesi itu {$row->teacher_net_amount}.";
        }

        foreach ($entriesQuery('payment_received', Order::class, 'customer_funds', 'credit')
            ->join('orders', 'orders.id', '=', 'journals.reference_id')
            ->whereRaw('ABS(entries.amount - orders.amount) > 0.009')
            ->select('journals.id', 'orders.id as order_id', 'entries.amount', 'orders.amount as order_amount')
            ->limit(10)
            ->get() as $row) {
            $errors[] = "Jurnal {$row->id} mengkredit customer_funds {$row->amount} untuk pesanan {$row->order_id}, padahal nilai pesanan itu {$row->order_amount}.";
        }

        foreach ($entriesQuery('refund_queued', Refund::class, 'refunds_payable', 'credit')
            ->join('refunds', 'refunds.id', '=', 'journals.reference_id')
            ->whereRaw('ABS(entries.amount - refunds.amount) > 0.009')
            ->select('journals.id', 'refunds.id as refund_id', 'entries.amount', 'refunds.amount as refund_amount')
            ->limit(10)
            ->get() as $row) {
            $errors[] = "Jurnal {$row->id} menahan refunds_payable {$row->amount} untuk refund {$row->refund_id}, padahal nilai refund itu {$row->refund_amount}.";
        }

        $refundEvents = DB::table('financial_journals')
            ->where('reference_type', Refund::class)
            ->whereIn('event_type', ['refund_queued', 'refund_paid', 'refund_wallet_credited'])
            ->select('reference_id', 'event_type')
            ->get();
        $settledRefundIds = $refundEvents
            ->whereIn('event_type', ['refund_paid', 'refund_wallet_credited'])
            ->pluck('reference_id')
            ->unique();
        $outstandingRefundIds = $refundEvents
            ->where('event_type', 'refund_queued')
            ->pluck('reference_id')
            ->unique()
            ->diff($settledRefundIds)
            ->values()
            ->all();
        $ledgerRefundsPayable = $this->accountBalance('refunds_payable');
        $outstandingRefunds = round((float) DB::table('refunds')->whereIn('id', $outstandingRefundIds)->sum('amount'), 2);
        if (abs($ledgerRefundsPayable - $outstandingRefunds) > 0.01) {
            $errors[] = "Saldo refunds_payable {$ledgerRefundsPayable} tidak cocok dengan total refund yang belum dibayar {$outstandingRefunds}.";
        }

        $clearing = $this->signedBalance('customer_payment_deposits', 'debit');
        if ($clearing < -0.01) {
            $errors[] = "Saldo customer_payment_deposits negatif ({$clearing}): ada dana gateway yang didebitkan ke akun transfer manual.";
        }

        return $errors;
    }

    private function accountBalance(string $account): float
    {
        return round((float) DB::table('financial_ledger_entries')
            ->where('account_code', $account)
            ->selectRaw("SUM(CASE WHEN side = 'credit' THEN amount ELSE -amount END) AS balance")
            ->value('balance'), 2);
    }

    private function signedBalance(string $account, string $normalSide): float
    {
        $subtracted = $normalSide === 'debit' ? 'credit' : 'debit';

        return round((float) DB::table('financial_ledger_entries')
            ->where('account_code', $account)
            ->selectRaw('SUM(CASE WHEN side = ? THEN -amount ELSE amount END) AS balance', [$subtracted])
            ->value('balance'), 2);
    }
}
