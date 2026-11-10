<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Refund;
use App\Models\TeacherPayoutRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFinanceAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_specific_finance_attention_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Jakarta'));

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);

        $failedOrder = $this->order($student, 'INV-ATTN-FAILED');
        Refund::create([
            'order_id' => $failedOrder->id,
            'user_id' => $student->id,
            'amount' => 100000,
            'reason' => 'Pengiriman refund gagal',
            'status' => 'failed',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $staleOrder = $this->order($student, 'INV-ATTN-STALE');
        Refund::create([
            'order_id' => $staleOrder->id,
            'user_id' => $student->id,
            'amount' => 100000,
            'reason' => 'Menunggu tindakan admin',
            'status' => 'pending',
            'destination_method' => 'bank_transfer',
            'destination_selected_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $freshOrder = $this->order($student, 'INV-ATTN-FRESH');
        Refund::create([
            'order_id' => $freshOrder->id,
            'user_id' => $student->id,
            'amount' => 100000,
            'reason' => 'Baru dipilih murid',
            'status' => 'pending',
            'destination_method' => 'bank_transfer',
            'destination_selected_at' => now(),
        ]);

        $this->payout($teacher, 'failed', now()->subDays(2));
        $this->payout($teacher, 'pending', now());

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard-stats')
            ->assertOk()
            ->assertJsonPath('counts.refund_failed', 1)
            ->assertJsonPath('counts.refund_needs_review', 1)
            ->assertJsonPath('counts.payout_needs_review', 1)
            ->assertJsonPath('counts.refunds', 2)
            ->assertJsonPath('counts.payouts', 1);
    }

    public function test_finance_attention_is_zero_without_stale_or_failed_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard-stats')
            ->assertOk()
            ->assertJsonPath('counts.refund_failed', 0)
            ->assertJsonPath('counts.refund_needs_review', 0)
            ->assertJsonPath('counts.payout_needs_review', 0);
    }

    private function order(User $student, string $orderId): Order
    {
        return Order::create([
            'user_id' => $student->id,
            'order_id' => $orderId,
            'amount' => 100000,
            'status' => 'paid',
        ]);
    }

    private function payout(User $teacher, string $status, Carbon $createdAt): TeacherPayoutRequest
    {
        return TeacherPayoutRequest::create([
            'teacher_id' => $teacher->id,
            'booking_ids' => [],
            'gross_amount' => 100000,
            'commission_amount' => 10000,
            'net_amount' => 90000,
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'status' => $status,
            'requested_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
