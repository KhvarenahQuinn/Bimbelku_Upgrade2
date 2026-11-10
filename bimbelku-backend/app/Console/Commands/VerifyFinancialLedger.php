<?php

namespace App\Console\Commands;

use App\Models\FinancialAuditLog;
use App\Models\FinancialJournal;
use App\Models\IdempotencyRecord;
use App\Models\PayoutApproval;
use App\Services\WalletIntegrityService;
use Illuminate\Console\Command;

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
}
