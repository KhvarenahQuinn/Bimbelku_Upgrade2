<?php

namespace Tests\Feature;

use App\Models\CustomerWalletTransaction;
use App\Models\FinancialJournal;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RefundStatusMachineTest extends TestCase
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

    public function test_no_teacher_refund_settles_to_balance_without_admin_approval(): void
    {
        Http::fake();
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = $this->order($student, 'INV-NO-TEACHER-BALANCE');
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 150000,
            'reason' => 'Tutor tidak tersedia untuk jadwal ini',
            'source_type' => 'no_teacher',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => "refund-no-teacher-{$refund->id}"]);
        $response->assertOk();

        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id,
            'status' => 'paid',
            'destination_method' => 'bimbelku_balance',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        $this->assertSame(1, CustomerWalletTransaction::query()->where('refund_id', $refund->id)->count());
        $this->assertFalse(Notification::query()
            ->where('unique_key', 'like', "refund-action:{$refund->id}:%")
            ->exists(), 'Refund no_teacher tidak boleh menunggu tindakan admin.');
        Http::assertNothingSent();
    }

    public function test_pending_gateway_status_is_persisted_and_exposed_with_timeline(): void
    {
        Http::fake([
            'https://api.xendit.test/refunds' => Http::response([
                'id' => 'rfd-pending-1',
                'status' => 'PENDING',
            ]),
        ]);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = $this->order($student, 'INV-PENDING-REFUND');
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 150000,
            'reason' => 'Tutor tidak ditemukan',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'xendit_original',
        ], ['Idempotency-Key' => "refund-original-{$refund->id}"]);
        $response->assertOk();

        $refund->refresh();
        $this->assertSame('pending', $refund->status);
        $this->assertSame('PENDING', $refund->gateway_status);
        $this->assertSame('rfd-pending-1', $refund->gateway_refund_id);
        $this->assertNotNull($refund->gateway_submitted_at);
        $this->assertNotNull($refund->last_reconciled_at);

        $response = $this->postJson("/api/student/refunds/{$refund->id}/sync", [], [
            'Idempotency-Key' => "refund-sync-{$refund->id}",
        ])->assertOk();
        $response->assertJsonPath('refund.stage', 'processing');
        $response->assertJsonPath('refund.gateway_status', 'PENDING');
        $response->assertJsonPath('refund.gateway_refund_id', 'rfd-pending-1');
        $response->assertJsonCount(4, 'refund.timeline');
        $this->assertNotEmpty($response->json('refund.status_message'));
        $this->assertNotNull($response->json('refund.last_reconciled_at'));
        Http::assertSentCount(1);
    }

    public function test_failed_gateway_refund_is_marked_and_can_switch_to_balance_once(): void
    {
        Http::fake([
            'https://api.xendit.test/refunds' => Http::response([
                'id' => 'rfd-failed-1',
                'status' => 'FAILED',
                'failure_code' => 'INSUFFICIENT_BALANCE',
                'failure_message' => 'Saldo operasional tidak cukup',
            ]),
        ]);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = $this->order($student, 'INV-FAILED-REFUND');
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 150000,
            'reason' => 'Tutor tidak ditemukan',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'xendit_original',
        ], ['Idempotency-Key' => "refund-original-{$refund->id}"])->assertOk();

        $refund->refresh();
        $this->assertSame('failed', $refund->status);
        $this->assertSame('FAILED', $refund->gateway_status);
        $this->assertSame('INSUFFICIENT_BALANCE', $refund->gateway_failure_code);
        $this->assertSame('Saldo operasional tidak cukup', $refund->gateway_failure_message);
        $this->assertSame('refund_pending', $order->fresh()->status);
        $this->assertSame(0, CustomerWalletTransaction::query()->where('refund_id', $refund->id)->count());

        $switch = $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => "refund-failed-switch-{$refund->id}"])->assertOk();
        $switch->assertJsonPath('refund.status', 'paid');
        $switch->assertJsonPath('refund.destination_method', 'bimbelku_balance');

        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'paid']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        $this->assertSame(1, CustomerWalletTransaction::query()->where('refund_id', $refund->id)->count());
        $this->assertSame(1, FinancialJournal::query()
            ->where('event_key', "refund:{$refund->id}:wallet_credited")
            ->count());

        // A second switch must not credit the wallet again.
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => "refund-failed-switch-again-{$refund->id}"])->assertStatus(422);
        $this->assertSame(1, CustomerWalletTransaction::query()->where('refund_id', $refund->id)->count());

        // A late success webhook must not double-credit an already paid refund.
        $this->postJson('/api/webhooks/xendit', [
            'id' => 'evt-late-refund-success',
            'event' => 'refund.succeeded',
            'data' => [
                'id' => 'rfd-failed-1',
                'reference_id' => "BKU-RFD-{$refund->id}",
                'status' => 'SUCCEEDED',
            ],
        ], ['x-callback-token' => 'refund-webhook-token'])->assertOk();

        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'paid']);
        $this->assertSame(1, CustomerWalletTransaction::query()->where('refund_id', $refund->id)->count());
        $this->assertSame(1, FinancialJournal::query()
            ->where('event_key', "refund:{$refund->id}:wallet_credited")
            ->count());
    }

    public function test_admin_retry_reopens_a_failed_gateway_refund(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response([
                    'id' => 'rfd-retry-1',
                    'status' => 'FAILED',
                    'failure_code' => 'INSUFFICIENT_BALANCE',
                    'failure_message' => 'Saldo operasional tidak cukup',
                ])
                : Http::response(['id' => 'rfd-retry-1', 'status' => 'PENDING']);
        });
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $order = $this->order($student, 'INV-RETRY-REFUND');
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 150000,
            'reason' => 'Tutor tidak ditemukan',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'xendit_original',
        ], ['Idempotency-Key' => "refund-retry-original-{$refund->id}"])->assertOk();
        $this->assertSame('failed', $refund->fresh()->status);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/refunds/{$refund->id}/retry", [], [
            'Idempotency-Key' => "refund-admin-retry-{$refund->id}",
        ])->assertOk();

        $refund->refresh();
        $this->assertSame('pending', $refund->status);
        $this->assertSame('PENDING', $refund->gateway_status);
        $this->assertSame('rfd-retry-1', $refund->gateway_refund_id);
        $this->assertNull($refund->gateway_failure_code);
        $this->assertNull($refund->gateway_failure_message);
        $this->assertSame(2, $calls);
    }

    public function test_failed_webhook_marks_reason_and_late_success_recovers_once(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = $this->order($student, 'INV-WEBHOOK-REFUND');
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 150000,
            'reason' => 'Tutor tidak ditemukan',
            'status' => 'pending',
            'destination_method' => 'xendit_original',
            'destination_selected_at' => now(),
            'gateway_refund_id' => 'rfd-webhook-1',
            'gateway_reference_id' => 'BKU-RFD-WEBHOOK-1',
            'gateway_status' => 'PENDING',
            'gateway_submitted_at' => now(),
        ]);

        $this->webhook('refund.failed', [
            'id' => 'rfd-webhook-1',
            'reference_id' => 'BKU-RFD-WEBHOOK-1',
            'status' => 'FAILED',
            'failure_code' => 'INSUFFICIENT_BALANCE',
            'failure_message' => 'Saldo operasional tidak cukup',
        ])->assertOk();

        $refund->refresh();
        $this->assertSame('failed', $refund->status);
        $this->assertSame('FAILED', $refund->gateway_status);
        $this->assertSame('INSUFFICIENT_BALANCE', $refund->gateway_failure_code);
        $this->assertSame('Saldo operasional tidak cukup', $refund->gateway_failure_message);

        // A late success notification is still allowed to finish the refund.
        $this->webhook('refund.succeeded', [
            'id' => 'rfd-webhook-1',
            'reference_id' => 'BKU-RFD-WEBHOOK-1',
            'status' => 'SUCCEEDED',
        ])->assertOk();
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'paid']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        $this->assertSame(1, FinancialJournal::query()
            ->where('event_key', "refund:{$refund->id}:paid")
            ->count());

        // Replaying the same success webhook must not record a second payout.
        $this->webhook('refund.succeeded', [
            'id' => 'rfd-webhook-1',
            'reference_id' => 'BKU-RFD-WEBHOOK-1',
            'status' => 'SUCCEEDED',
        ])->assertOk();
        $this->assertSame(1, FinancialJournal::query()
            ->where('event_key', "refund:{$refund->id}:paid")
            ->count());
    }

    private function webhook(string $event, array $data)
    {
        return $this->postJson('/api/webhooks/xendit', [
            'id' => 'evt-'.$event.'-'.uniqid(),
            'event' => $event,
            'data' => $data,
        ], ['x-callback-token' => 'refund-webhook-token']);
    }

    private function order(User $student, string $code): Order
    {
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => $code,
            'amount' => 150000,
            'status' => 'refund_pending',
            'gateway_payment_request_id' => 'pr-'.$code,
        ]);
        $order->forceFill(['payment_provider' => 'xendit'])->save();

        return $order;
    }
}
