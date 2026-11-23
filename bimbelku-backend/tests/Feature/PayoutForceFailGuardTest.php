<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayoutForceFailGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_payout_the_gateway_may_still_be_transferring_cannot_be_force_failed(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $payout = Payout::create([
            ...$this->payoutAttributes($teacher, 'processing'),
            'gateway_payout_id' => 'disb-123',
            'gateway_status' => 'REQUESTED',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/finance/payouts/{$payout->id}/force-fail", [
            'reason' => 'Bank menolak transfer dan dana sudah kembali ke rekening platform.',
        ])->assertUnprocessable();

        // Melepas reservasi di sini akan membuat payout kedua atas dana yang sama.
        $this->assertSame('processing', $payout->fresh()->status);
        $this->assertNotSame('failed', $payout->fresh()->status);
    }

    public function test_a_submitting_payout_without_a_gateway_id_can_still_be_released(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $payout = Payout::create([
            ...$this->payoutAttributes($teacher, 'processing'),
            'gateway_status' => 'SUBMITTING',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/finance/payouts/{$payout->id}/force-fail", [
            'reason' => 'Gateway tidak pernah menerima permintaan transfer ini.',
        ])->assertOk();

        $this->assertSame('failed', $payout->fresh()->status);
    }

    public function test_an_on_hold_payout_can_be_released_even_with_a_gateway_id(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $payout = Payout::create([
            ...$this->payoutAttributes($teacher, 'on_hold'),
            'gateway_payout_id' => 'disb-held-1',
            'gateway_status' => 'ACCEPTED',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/finance/payouts/{$payout->id}/force-fail", [
            'reason' => 'Pencairan ditahan sebelum dikirim dan dibatalkan oleh admin.',
        ])->assertOk();

        $this->assertSame('failed', $payout->fresh()->status);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function payoutAttributes(User $teacher, string $status): array
    {
        return [
            'user_id' => $teacher->id,
            'amount' => 100000,
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
        ];
    }
}
