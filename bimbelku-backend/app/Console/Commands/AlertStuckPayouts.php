<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Console\Command;

class AlertStuckPayouts extends Command
{
    protected $signature = 'payments:alert-stuck-payouts {--minutes=30}';

    protected $description = 'Memperingatkan admin tentang pencairan yang tertahan melewati ambang waktu';

    public function handle(): int
    {
        $minutes = max(5, (int) $this->option('minutes'));
        $admins = User::query()->where('role', 'admin')->pluck('id');
        if ($admins->isEmpty()) {
            return self::SUCCESS;
        }

        $stuck = Payout::query()
            ->whereIn('status', ['processing', 'on_hold'])
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->get();

        $alerted = 0;
        foreach ($stuck as $payout) {
            // One alert per payout per hour, so a 15-minute schedule does not
            // turn a single stuck payout into four notifications.
            $uniqueKey = "payout-stuck:{$payout->id}:".now()->format('YmdH');
            $created = Notification::query()->firstOrCreate(
                ['unique_key' => $uniqueKey],
                [
                    'user_id' => $admins->first(),
                    'title' => 'Pencairan tertahan',
                    'message' => 'Pencairan Rp'.number_format((float) $payout->amount, 0, ',', '.').
                        ' untuk tutor #'.$payout->user_id.' sudah '.$minutes.' menit tanpa status final.',
                    'type' => 'warning',
                    'target_url' => '/admin/finance?tab=payouts',
                    'is_read' => false,
                ]
            );
            if ($created->wasRecentlyCreated) {
                $alerted++;
            }
        }

        $this->info("Pencairan tertahan diperiksa: {$stuck->count()}, peringatan baru: {$alerted}");

        return self::SUCCESS;
    }
}
