<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentGatewayEvent;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\User;
use App\Services\XenditMoneyMovementService;
use App\Services\XenditPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PaymentGatewaySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00', 'Asia/Jakarta'));
        config(['xendit.enabled' => true, 'xendit.secret_key' => 'test', 'xendit.webhook_token' => 'test', 'xendit.base_url' => 'https://api.xendit.test']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function uncertainResponses(): array
    {
        return ['server error' => [500], 'rate limit' => [429], 'conflict' => [409], 'malformed success' => [200], 'timeout' => [0]];
    }

    #[DataProvider('uncertainResponses')]
    public function test_uncertain_creation_never_reposts_or_rotates_reference(int $status): void
    {
        $order = $this->invoice(false);
        $attempts = 0;
        Http::fake(function () use ($status, &$attempts) {
            $attempts++;
            if ($status === 0) {
                throw new ConnectionException('Simulated lost response');
            }

            return Http::response(['message' => 'Unknown result'], $status);
        });
        $service = app(XenditPaymentService::class);
        try {
            $service->createSession($order, $order->user);
            $this->fail('Uncertain result must not expose checkout.');
        } catch (RuntimeException) {
        }
        $first = $order->fresh();
        $this->assertTrue($first->gateway_creation_uncertain);
        $this->assertSame('CREATING', $first->gateway_status);
        $this->assertNotEmpty($first->gateway_reference_id);
        $this->assertNull($first->gateway_session_id);
        Carbon::setTestNow(now()->addMinutes(2));
        try {
            $service->createSession($first, $order->user);
            $this->fail('Ambiguous attempt must be held, not retried.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('mencegah pembayaran ganda', $exception->getMessage());
        }
        $this->assertSame($first->gateway_reference_id, $order->fresh()->gateway_reference_id);
        $this->assertTrue($first->gateway_expires_at->equalTo($order->fresh()->gateway_expires_at));
        $this->assertSame(1, $attempts);
        Http::assertSentCount($status === 0 ? 0 : 1);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_success_with_missing_link_recovers_the_same_session_by_get(): void
    {
        $order = $this->invoice(false);
        Http::fake(function ($request) {
            return $request->method() === 'POST'
                ? Http::response(['payment_session_id' => 'ps-recovered', 'status' => 'ACTIVE'])
                : Http::response(['payment_session_id' => 'ps-recovered', 'status' => 'ACTIVE', 'payment_link_url' => 'https://checkout.xendit.test/recovered']);
        });
        $service = app(XenditPaymentService::class);
        try {
            $service->createSession($order, $order->user);
            $this->fail('Checkout without UI must not be returned.');
        } catch (RuntimeException) {
        }
        $this->assertSame('ps-recovered', $order->fresh()->gateway_session_id);
        $this->assertFalse($order->fresh()->gateway_creation_uncertain);
        $result = $service->createSession($order->fresh(), $order->user);
        $this->assertSame('ps-recovered', $result['session_id']);
        $this->assertSame('https://checkout.xendit.test/recovered', $result['checkout_url']);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_authenticated_reference_callback_recovers_session_after_lost_create_response(): void
    {
        $order = $this->invoice(false);
        $order->forceFill(['gateway_status' => 'CREATING', 'gateway_reference_id' => 'ref-lost', 'gateway_creation_uncertain' => true])->save();
        $this->webhook('payment_session.expired', ['payment_session_id' => 'ps-lost', 'reference_id' => 'ref-lost', 'status' => 'EXPIRED'])->assertOk();
        $this->assertSame('ps-lost', $order->fresh()->gateway_session_id);
        $this->assertFalse($order->fresh()->gateway_creation_uncertain);
        $this->assertSame('EXPIRED', $order->fresh()->gateway_status);
    }

    public function test_paid_order_cannot_be_downgraded_by_expiry_failure_or_active_events(): void
    {
        $order = $this->invoice();
        $order->forceFill(['status' => 'paid', 'gateway_status' => 'COMPLETED', 'gateway_paid_at' => now(), 'external_received_amount' => 50000])->saveQuietly();
        foreach (['EXPIRED', 'FAILED', 'ACTIVE', 'CANCELED'] as $status) {
            $this->webhook('payment_session.'.strtolower($status), ['payment_session_id' => 'ps-safety', 'status' => $status])->assertOk();
            $this->assertSame('COMPLETED', $order->fresh()->gateway_status);
            $this->assertSame('paid', $order->fresh()->status);
            $this->assertSame(50000.0, (float) $order->fresh()->external_received_amount);
        }
    }

    public function test_payment_attempt_failure_does_not_close_an_active_session(): void
    {
        $order = $this->invoice();
        $this->webhook('payment.capture.failed', ['payment_session_id' => 'ps-safety', 'status' => 'FAILED', 'amount' => 50000])->assertOk();
        $this->assertSame('ACTIVE', $order->fresh()->gateway_status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0.0, (float) $order->fresh()->external_received_amount);
    }

    public function test_expired_session_cannot_be_reactivated_by_stale_active_event(): void
    {
        $order = $this->invoice();
        $this->webhook('payment_session.expired', ['payment_session_id' => 'ps-safety', 'status' => 'EXPIRED'])->assertOk();
        $this->webhook('payment_session.active', ['payment_session_id' => 'ps-safety', 'status' => 'ACTIVE'])->assertOk();
        $this->assertSame('EXPIRED', $order->fresh()->gateway_status);
    }

    public function test_stale_sync_response_cannot_overwrite_a_webhook_receipt(): void
    {
        $order = $this->invoice();
        Http::fake(function () use ($order) {
            $order->forceFill(['status' => 'paid', 'gateway_status' => 'COMPLETED', 'gateway_paid_at' => now()])->saveQuietly();

            return Http::response(['payment_session_id' => 'ps-safety', 'status' => 'ACTIVE']);
        });
        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/payment-status-sync")->assertOk()->assertJsonPath('gateway_status', 'COMPLETED');
        $this->assertSame('paid', $order->fresh()->status);
    }

    public static function staleRefundStatuses(): array
    {
        return ['failure' => ['FAILED'], 'rejection' => ['REJECTED'], 'pending' => ['PENDING']];
    }

    #[DataProvider('staleRefundStatuses')]
    public function test_stale_refund_lookup_cannot_overwrite_concurrent_success(string $status): void
    {
        $order = $this->invoice();
        $refund = Refund::create(['order_id' => $order->id, 'user_id' => $order->user_id,
            'amount' => 50000, 'reason' => 'Tutor tidak ditemukan', 'status' => 'pending',
            'gateway_refund_id' => 'rfd-stale', 'gateway_status' => 'SUBMITTING',
            'destination_method' => 'xendit_original']);
        Http::fake(function () use ($refund, $status) {
            Refund::whereKey($refund->id)->update(['status' => 'paid', 'gateway_status' => 'SUCCEEDED', 'gateway_failure_code' => null]);

            return Http::response(['id' => 'rfd-stale', 'status' => $status, 'failure_code' => 'OLD_ERROR']);
        });
        $result = app(XenditMoneyMovementService::class)->retrieveRefund($refund);
        $this->assertSame('SUCCEEDED', $result['status']);
        $this->assertSame('paid', $refund->fresh()->status);
        $this->assertSame('SUCCEEDED', $refund->fresh()->gateway_status);
        $this->assertNull($refund->fresh()->gateway_failure_code);
    }

    public function test_stale_refund_creation_response_cannot_overwrite_webhook_success(): void
    {
        $order = $this->invoice();
        $order->forceFill(['gateway_payment_request_id' => 'pr-stale', 'gateway_payment_id' => 'py-stale'])->save();
        $refund = Refund::create(['order_id' => $order->id, 'user_id' => $order->user_id,
            'amount' => 50000, 'reason' => 'Tutor tidak ditemukan', 'status' => 'pending',
            'destination_method' => 'xendit_original']);
        Http::fake(function () use ($refund) {
            Refund::whereKey($refund->id)->update(['status' => 'paid', 'gateway_status' => 'SUCCEEDED', 'gateway_refund_id' => 'rfd-created']);

            return Http::response(['id' => 'rfd-created', 'status' => 'PENDING']);
        });
        $result = app(XenditMoneyMovementService::class)->createRefund($refund);
        $this->assertSame('SUCCEEDED', $result['status']);
        $this->assertSame('paid', $refund->fresh()->status);
        $this->assertSame('SUCCEEDED', $refund->fresh()->gateway_status);
        Http::assertSentCount(1);
    }

    public function test_already_successful_refund_is_not_submitted_again_even_from_stale_model(): void
    {
        $order = $this->invoice();
        $refund = Refund::create(['order_id' => $order->id, 'user_id' => $order->user_id,
            'amount' => 50000, 'reason' => 'Tutor tidak ditemukan', 'status' => 'pending',
            'destination_method' => 'xendit_original']);
        Refund::whereKey($refund->id)->update(['status' => 'paid', 'gateway_status' => 'SUCCEEDED', 'gateway_refund_id' => 'rfd-done']);
        Http::fake();
        $result = app(XenditMoneyMovementService::class)->createRefund($refund);
        $this->assertSame('SUCCEEDED', $result['status']);
        Http::assertNothingSent();
    }

    public static function badIdentities(): array
    {
        return [
            'missing' => [[]],
            'empty session' => [['payment_session_id' => '']],
            'array session' => [['payment_session_id' => ['ps-safety']]],
            'array reference' => [['reference_id' => ['ref-safety']]],
            'metadata only' => [['metadata' => ['order_id' => '1']]],
            'unknown session plus metadata' => [['payment_session_id' => 'ps-other', 'metadata' => ['order_id' => '1']]],
            'conflicting ref' => [['payment_session_id' => 'ps-safety', 'reference_id' => 'ref-other']],
            'conflicting order' => [['payment_session_id' => 'ps-safety', 'metadata' => ['order_id' => '999']]],
            'invalid order' => [['payment_session_id' => 'ps-safety', 'metadata' => ['order_id' => ['1']]]],
        ];
    }

    #[DataProvider('badIdentities')]
    public function test_missing_malformed_or_conflicting_identity_never_modifies_first_order(array $identity): void
    {
        $order = $this->invoice();
        Http::fake();
        $this->webhook('payment_session.completed', array_merge(['status' => 'COMPLETED', 'amount' => 50000], $identity))->assertStatus(202);
        $this->assertSame('ACTIVE', $order->fresh()->gateway_status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0.0, (float) $order->fresh()->external_received_amount);
        $this->assertDatabaseCount('refunds', 0);
        $event = PaymentGatewayEvent::firstOrFail();
        $this->assertSame('ignored', $event->status);
        $this->assertNull($event->order_id);
        Http::assertNothingSent();
    }

    public function test_amount_omitted_from_success_requires_actual_completed_remote_session(): void
    {
        $order = $this->invoice();
        Http::fake(['*/sessions/*' => Http::response(['status' => 'ACTIVE', 'amount' => 50000])]);
        $this->webhook('payment_session.completed', ['payment_session_id' => 'ps-safety', 'status' => 'COMPLETED'])->assertStatus(202);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0.0, (float) $order->fresh()->external_received_amount);
    }

    public function test_missing_refund_identity_does_not_modify_first_refund(): void
    {
        $order = $this->invoice();
        $refund = Refund::create(['order_id' => $order->id, 'user_id' => $order->user_id, 'amount' => 50000,
            'reason' => 'Uji sengketa', 'status' => 'pending', 'gateway_refund_id' => 'rfd-safety', 'gateway_status' => 'PENDING']);
        $this->webhook('refund.failed', ['status' => 'FAILED'])->assertOk();
        $this->assertSame('PENDING', $refund->fresh()->gateway_status);
        $this->assertNull(PaymentGatewayEvent::firstOrFail()->refund_id);
    }

    public function test_completed_refund_is_not_overwritten_by_late_failure(): void
    {
        $order = $this->invoice();
        $refund = Refund::create(['order_id' => $order->id, 'user_id' => $order->user_id, 'amount' => 50000,
            'reason' => 'Uji sengketa', 'status' => 'paid', 'gateway_refund_id' => 'rfd-safety', 'gateway_status' => 'SUCCEEDED']);
        $this->webhook('refund.failed', ['id' => 'rfd-safety', 'status' => 'FAILED'])->assertOk();
        $this->assertSame('SUCCEEDED', $refund->fresh()->gateway_status);
        $this->assertSame('paid', $refund->fresh()->status);
    }

    public function test_missing_or_conflicting_payout_identity_never_modifies_any_payout(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $payout = Payout::create(['user_id' => $teacher->id, 'period' => 'Uji', 'amount' => 10000,
            'total_classes' => 0, 'proof_url' => '', 'status' => 'processing',
            'gateway_payout_id' => 'po-safety', 'gateway_reference_id' => 'ref-payout', 'gateway_status' => 'ACCEPTED']);
        foreach ([[], ['payout_id' => ['po-safety']], ['payout_id' => 'po-safety', 'reference_id' => 'ref-other']] as $identity) {
            $this->webhook('v3_payout.failed', array_merge(['status' => 'FAILED'], $identity))->assertOk();
            $this->assertSame('processing', $payout->fresh()->status);
            $this->assertSame('ACCEPTED', $payout->fresh()->gateway_status);
        }
    }

    public function test_valid_refund_success_still_completes_once(): void
    {
        $order = $this->invoice();
        $order->forceFill(['status' => 'refund_pending', 'payment_provider' => 'xendit'])->saveQuietly();
        $refund = Refund::create(['order_id' => $order->id, 'user_id' => $order->user_id, 'amount' => 50000,
            'reason' => 'Tutor tidak ditemukan', 'status' => 'pending', 'gateway_refund_id' => 'rfd-safety',
            'gateway_reference_id' => 'ref-refund', 'gateway_status' => 'PENDING',
            'destination_method' => 'xendit_original', 'destination_selected_at' => now()]);
        foreach ([1, 2] as $delivery) {
            $this->webhook('refund.succeeded', ['id' => 'rfd-safety', 'reference_id' => 'ref-refund', 'status' => 'SUCCEEDED'])->assertOk();
        }
        $this->assertSame('paid', $refund->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->status);
    }

    private function webhook(string $event, array $data)
    {
        return $this->postJson('/api/webhooks/xendit', ['id' => 'evt-'.PaymentGatewayEvent::count().'-'.$event,
            'event' => $event, 'data' => $data], ['x-callback-token' => 'test']);
    }

    private function invoice(bool $session = true): Order
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create(['user_id' => $student->id, 'amount' => 50000, 'status' => 'pending']);
        if ($session) {
            $order->forceFill(['gateway_session_id' => 'ps-safety', 'gateway_reference_id' => 'ref-safety',
                'gateway_status' => 'ACTIVE', 'gateway_expires_at' => now()->addHour()])->save();
        }

        return $order;
    }
}
