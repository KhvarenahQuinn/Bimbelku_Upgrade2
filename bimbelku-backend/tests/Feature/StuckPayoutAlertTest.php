<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StuckPayoutAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_payout_past_the_threshold_creates_one_alert_per_hour(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->payout($teacher, 'processing', now()->subMinutes(45));

        $this->artisan('payments:alert-stuck-payouts')->assertSuccessful();
        $this->artisan('payments:alert-stuck-payouts')->assertSuccessful();

        $this->assertSame(1, Notification::query()->where('unique_key', 'like', 'payout-stuck:%')->count());
    }

    public function test_payout_below_the_threshold_is_ignored(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->payout($teacher, 'processing', now()->subMinutes(10));

        $this->artisan('payments:alert-stuck-payouts')->assertSuccessful();

        $this->assertSame(0, Notification::query()->where('unique_key', 'like', 'payout-stuck:%')->count());
    }

    public function test_final_payout_is_ignored(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->payout($teacher, 'completed', now()->subMinutes(90));

        $this->artisan('payments:alert-stuck-payouts')->assertSuccessful();

        $this->assertSame(0, Notification::query()->where('unique_key', 'like', 'payout-stuck:%')->count());
    }

    private function payout(User $teacher, string $status, $createdAt): Payout
    {
        $payout = new Payout([
            'user_id' => $teacher->id,
            'amount' => 50000,
            'period' => 'November 2026',
            'total_classes' => 1,
            'status' => $status,
            'payment_provider' => 'xendit',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'bank_name' => 'BCA',
            'payout_channel_code' => 'BCA',
            'booking_ids' => [],
            'allocation_breakdown' => [],
        ]);
        $payout->created_at = $createdAt;
        $payout->save();

        return $payout;
    }
}
