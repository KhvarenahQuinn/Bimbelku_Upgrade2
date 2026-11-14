<?php

use App\Services\RefundSettlementService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('refunds:reconcile', function (RefundSettlementService $settlement) {
    // Read paths already settle refunds while an admin/student page is open.
    // This is the background safety net for closed tabs: poll at most once a
    // minute, and retry refunds whose first submission never reached Xendit.
    $settlement->reconcileDue(null, 100, 60);
})->purpose('Reconcile pending automatic refunds with the payment provider');

Schedule::command('payments:reconcile')
    ->everyMinute()
    ->runInBackground()
    ->withoutOverlapping(10);

Schedule::command('payments:reconcile-payouts')
    ->everyFiveMinutes()
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
Schedule::command('refunds:reconcile')
    ->everyMinute()
    ->runInBackground()
    ->withoutOverlapping(10);
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('03:00')
    ->withoutOverlapping(30);
