<?php

namespace App\Console\Commands;

use App\Models\Payout;
use App\Services\XenditMoneyMovementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcilePayouts extends Command
{
    protected $signature = 'payments:reconcile-payouts {--limit=20}';

    protected $description = 'Memeriksa pencairan tutor yang belum menerima webhook terminal dari penyedia pembayaran';

    public function handle(XenditMoneyMovementService $gateway): int
    {
        if (! $gateway->configured()) {
            return self::SUCCESS;
        }
        $startedAt = microtime(true);
        $checked = 0;
        $failed = 0;
        $limit = max(1, (int) $this->option('limit'));
        Payout::query()
            ->whereIn('status', ['processing', 'on_hold'])
            ->whereNotNull('gateway_payout_id')
            // Give the gateway time to send its own webhook first.
            ->where('created_at', '<=', now()->subMinutes(5))
            ->where(fn ($query) => $query->whereNull('gateway_last_checked_at')
                ->orWhere('gateway_last_checked_at', '<=', now()->subMinutes(10)))
            ->orderBy('gateway_last_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Payout $payout) use ($gateway, &$checked, &$failed, $startedAt) {
                if (microtime(true) - $startedAt >= 45) {
                    return false;
                }
                try {
                    $gateway->retrievePayout($payout);
                    $checked++;
                } catch (\Throwable $exception) {
                    report($exception);
                    $failed++;
                } finally {
                    // Query update avoids moving updated_at and changing the
                    // five-minute grace window of a fresh payout.
                    DB::table('payouts')->where('id', $payout->id)
                        ->update(['gateway_last_checked_at' => now()]);
                }
            });
        $this->info("Pencairan diperiksa: {$checked}; gagal: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
