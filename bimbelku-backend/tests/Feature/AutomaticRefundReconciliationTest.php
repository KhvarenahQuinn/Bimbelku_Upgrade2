<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomaticRefundReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_test');
        config()->set('xendit.webhook_token', 'refund-webhook-token');
        config()->set('xendit.base_url', 'https://api.xendit.test');
    }

    public function test_admin_refund_list_settles_a_succeeded_gateway_refund_without_manual_action(): void
    {
        Http::fake([
            'https://api.xendit.test/refunds/rfd-auto-admin' => Http::response([
                'id' => 'rfd-auto-admin',
                'status' => 'SUCCEEDED',
            ]),
        ]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $refund = $this->pendingGatewayRefund($student, 'INV-AUTO-ADMIN', 'rfd-auto-admin');

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/finance/refunds')
            ->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refunds/rfd-auto-admin'));
        $this->assertSame('paid', $refund->fresh()->status);
        $this->assertSame('refunded', $refund->order->fresh()->status);
    }

    public function test_student_order_history_settles_a_succeeded_gateway_refund_without_admin_action(): void
    {
        Http::fake([
            'https://api.xendit.test/refunds/rfd-auto-student' => Http::response([
                'id' => 'rfd-auto-student',
                'status' => 'SUCCEEDED',
            ]),
        ]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $refund = $this->pendingGatewayRefund($student, 'INV-AUTO-STUDENT', 'rfd-auto-student');

        Sanctum::actingAs($student);

        $this->getJson('/api/orders')->assertOk();

        $this->assertSame('paid', $refund->fresh()->status);
        $this->assertSame('refunded', $refund->order->fresh()->status);
    }

    public function test_admin_refund_list_settles_on_the_next_poll_instead_of_waiting_a_minute(): void
    {
        Http::fake([
            'https://api.xendit.test/refunds/rfd-auto-fast' => Http::response([
                'id' => 'rfd-auto-fast',
                'status' => 'SUCCEEDED',
            ]),
        ]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        // The page polls every 15 seconds; a refund checked 20 seconds ago
        // must still be polled again on the next refresh.
        $refund = $this->pendingGatewayRefund($student, 'INV-AUTO-FAST', 'rfd-auto-fast', now()->subSeconds(20));

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/finance/refunds')->assertOk();

        $this->assertSame('paid', $refund->fresh()->status);
    }

    public function test_admin_refund_list_retries_a_refund_that_never_reached_the_gateway(): void
    {
        Http::fake([
            'https://api.xendit.test/refunds' => Http::response([
                'id' => 'rfd-auto-retry',
                'status' => 'SUCCEEDED',
            ]),
        ]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $refund = $this->pendingGatewayRefund($student, 'INV-AUTO-RETRY', null);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/finance/refunds')->assertOk();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/refunds'));
        $this->assertSame('paid', $refund->fresh()->status);
    }

    private function pendingGatewayRefund(
        User $student,
        string $code,
        ?string $gatewayRefundId,
        ?\DateTimeInterface $lastReconciledAt = null
    ): Refund {
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => $code,
            'amount' => 150000,
            'status' => 'refund_pending',
            'gateway_payment_request_id' => 'pr-'.$code,
        ]);
        $order->forceFill(['payment_provider' => 'xendit'])->save();

        return Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 150000,
            'reason' => 'Tutor tidak ditemukan',
            'source_type' => 'no_teacher',
            'status' => 'pending',
            'destination_method' => 'xendit_original',
            'destination_selected_at' => now()->subMinutes(2),
            'gateway_refund_id' => $gatewayRefundId,
            'gateway_reference_id' => 'BKU-RFD-TEST-'.$code,
            'gateway_status' => $gatewayRefundId ? 'PENDING' : null,
            'gateway_submitted_at' => $gatewayRefundId ? now()->subMinutes(2) : null,
            'last_reconciled_at' => $lastReconciledAt ?: now()->subMinutes(2),
        ]);
    }
}
