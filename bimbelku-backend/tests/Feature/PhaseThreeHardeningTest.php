<?php

namespace Tests\Feature;

use App\Console\Commands\VerifyCustomerWallets;
use App\Models\CustomerWallet;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PhaseThreeHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_finance_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(
            fn ($event) => str_contains((string) $event->command, 'finance:verify-wallets')
        );

        $this->assertCount(1, $events, 'finance:verify-wallets harus terdaftar di penjadwal.');
        $event = $events->first();
        $this->assertSame('30 2 * * *', $event->expression, 'Kontrol saldo harus berjalan 02:30 setiap hari.');
    }

    public function test_scheduler_heartbeat_command_is_scheduled_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(
            fn ($event) => str_contains((string) $event->command, 'schedule:heartbeat')
        );

        $this->assertCount(1, $events, 'Denyut penjadwal harus terdaftar.');
        $this->assertSame('* * * * *', $events->first()->expression);
    }

    public function test_heartbeat_records_last_run_timestamp(): void
    {
        Cache::forget('scheduler:last_heartbeat_at');

        $this->assertSame(0, Artisan::call('schedule:heartbeat'));

        $stamp = Cache::get('scheduler:last_heartbeat_at');
        $this->assertNotNull($stamp);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $stamp));
    }

    public function test_verify_wallets_command_succeeds_on_consistent_ledger(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $wallet = CustomerWallet::unguarded(fn () => CustomerWallet::create([
            'user_id' => $student->id,
            'current_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'IDR',
        ]));

        $this->assertNotNull($wallet);

        $this->assertSame(VerifyCustomerWallets::SUCCESS, Artisan::call('finance:verify-wallets'));
    }

    public function test_verify_wallets_command_detects_a_tampered_balance(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        CustomerWallet::unguarded(fn () => CustomerWallet::create([
            'user_id' => $student->id,
            'current_balance' => 125000,
            'reserved_balance' => 0,
            'currency' => 'IDR',
        ]));

        // Saldo 125000 tanpa satu pun mutasi: saldo tidak mungkin muncul dari
        // alur normal, jadi pemeriksa harus menandainya sebagai rusak.
        $this->assertSame(VerifyCustomerWallets::FAILURE, Artisan::call('finance:verify-wallets'));
    }

    public function test_refund_mass_assignment_cannot_set_settlement_fields(): void
    {
        $refund = new Refund;

        $this->assertFalse($refund->isFillable('status'));
        $this->assertFalse($refund->isFillable('gateway_refund_id'));
        $this->assertFalse($refund->isFillable('gateway_status'));
        $this->assertFalse($refund->isFillable('processed_at'));
        $this->assertFalse($refund->isFillable('proof'));

        // Kolom identitas dan nominal sengaja tetap fillable agar pembuatan
        // refund lewat create() tidak berubah perilakunya.
        $this->assertTrue($refund->isFillable('order_id'));
        $this->assertTrue($refund->isFillable('user_id'));
        $this->assertTrue($refund->isFillable('amount'));
    }

    public function test_refund_create_then_settlement_requires_force_fill(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-GUARD-1',
            'amount' => 100000,
            'status' => 'refund_pending',
        ]);

        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 100000,
            'reason' => 'Tutor tidak ditemukan',
            'status' => 'pending',
        ]);

        $this->assertSame('pending', $refund->fresh()->status);

        // Jalur penyelesaian wajib memakai forceFill(); update() massal tidak
        // boleh menaikkan status refund ke paid.
        $refund->update(['status' => 'paid']);
        $this->assertSame('pending', $refund->fresh()->status);

        $refund->forceFill(['status' => 'paid', 'processed_at' => now()])->save();
        $this->assertSame('paid', $refund->fresh()->status);
        $this->assertNotNull($refund->fresh()->processed_at);
    }
}
