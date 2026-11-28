<?php

use App\Services\RefundSettlementService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('refunds:reconcile', function (RefundSettlementService $settlement) {
    // Read paths already settle refunds while an admin/student page is open.
    // This is the background safety net for closed tabs: poll at most once a
    // minute, and retry refunds whose first submission never reached Xendit.
    $settlement->reconcileDue(null, 100, 60);
})->purpose('Reconcile pending automatic refunds with the payment provider');

// Denyut penjadwal: bila cron host berhenti, nilai ini ikut basi sehingga
// operator (atau health check) bisa membedakan "tidak ada pekerjaan" dari
// "penjadwal mati". Cache dipakai agar murah dan tidak menyentuh database.
Artisan::command('schedule:heartbeat', function () {
    Cache::forever('scheduler:last_heartbeat_at', now()->toIso8601String());
})->purpose('Record that the scheduler ran');

Schedule::command('schedule:heartbeat')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('payments:reconcile')
    ->everyMinute()
    ->runInBackground()
    ->withoutOverlapping(10);

Schedule::command('payments:reconcile-payouts')
    ->everyFiveMinutes()
    ->runInBackground()
    ->withoutOverlapping(10);

Schedule::command('payments:alert-stuck-payouts')
    ->everyFifteenMinutes()
    ->runInBackground()
    ->withoutOverlapping(10);

Schedule::command('bookings:expire')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('guest-packages:expire')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);

Schedule::command('finance:verify-ledger')
    ->dailyAt('02:00')
    ->withoutOverlapping(30);
// Verifikasi sisi wallet: saldo tersedia, saldo tertahan vs hold aktif, dan
// kewajiban ledger. Tanpa jadwal ini kerusakan saldo hanya terdeteksi saat
// murid berikutnya melakukan checkout (503 dari assertMutationBaseline).
Schedule::command('finance:verify-wallets')
    ->dailyAt('02:30')
    ->withoutOverlapping(30);
Schedule::command('queue:prune-failed --hours=168')
    ->weekly()
    ->withoutOverlapping(10);
Schedule::command('refunds:reconcile')
    ->everyMinute()
    ->runInBackground()
    ->withoutOverlapping(10);
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('03:00')
    ->withoutOverlapping(30);
// Prune destruktif: hanya di production, setelah jadwal 02:00/02:30/03:00.
Schedule::command('storage:prune-orphaned-private --force')
    ->dailyAt('03:30')
    ->environments(['production'])
    ->withoutOverlapping(30);
