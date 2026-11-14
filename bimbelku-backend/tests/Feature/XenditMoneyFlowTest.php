<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\RefundSettlementService;
use App\Services\XenditMoneyMovementService;
use App\Services\XenditPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class XenditMoneyFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_test');
        config()->set('xendit.webhook_token', 'callback-test');
        config()->set('xendit.base_url', 'https://api.xendit.test');
    }

    public function test_only_manual_refunds_alert_admin_and_completed_alerts_clear(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $automaticOrder = Order::create(['user_id' => $student->id, 'order_id' => 'INV-AUTO-REFUND', 'amount' => 10000, 'status' => 'refund_pending']);
        $automatic = Refund::create(['order_id' => $automaticOrder->id, 'user_id' => $student->id, 'amount' => 10000, 'reason' => 'Tutor tidak ditemukan', 'status' => 'pending']);
        $this->assertFalse(Notification::query()->where('unique_key', 'like', "refund-action:{$automatic->id}:%")->exists());

        $manualOrder = Order::create(['user_id' => $student->id, 'order_id' => 'INV-MANUAL-REFUND', 'amount' => 10000, 'status' => 'refund_pending']);
        $manual = Refund::create(['order_id' => $manualOrder->id, 'user_id' => $student->id, 'amount' => 10000, 'reason' => 'Sengketa sesi', 'status' => 'pending']);
        $this->assertTrue(Notification::query()->where('unique_key', 'like', "refund-action:{$manual->id}:%")->where('is_read', false)->exists());
        $manual->update(['status' => 'paid']);
        $this->assertFalse(Notification::query()->where('unique_key', 'like', "refund-action:{$manual->id}:%")->where('is_read', false)->exists());
    }

    public function test_http_local_return_urls_are_not_sent_to_gateway(): void
    {
        config(['xendit.success_url' => 'http://localhost:8080/payment?gateway=success',
            'xendit.cancel_url' => 'http://127.0.0.1:8080/payment?gateway=cancelled']);
        Http::fake(['*/sessions' => Http::response([
            'payment_session_id' => 'ps-local', 'payment_link_url' => 'https://checkout.xendit.test/local',
            'status' => 'ACTIVE', 'expires_at' => now()->addHour()->toIso8601String(),
        ])]);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create(['user_id' => $student->id, 'order_id' => 'INV-LOCAL-URL', 'amount' => 150000, 'status' => 'pending']);
        app(XenditPaymentService::class)->createSession($order, $student);
        Http::assertSent(fn (Request $request) => ! array_key_exists('success_return_url', $request->data())
            && ! array_key_exists('cancel_return_url', $request->data()));
    }

    public function test_embedded_checkout_uses_allowed_origin_and_reuses_the_same_session(): void
    {
        config()->set('cors.allowed_origins', ['https://bimbelcerdas.com']);
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['payment_session_id' => 'ps-embedded', 'components_sdk_key' => 'sdk-reloaded', 'status' => 'ACTIVE']);
            }

            return Http::response([
                'payment_session_id' => 'ps-embedded', 'components_sdk_key' => 'sdk-created',
                'status' => 'ACTIVE', 'expires_at' => now()->addHour()->toIso8601String(),
            ]);
        });

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create(['user_id' => $student->id, 'order_id' => 'INV-EMBEDDED', 'amount' => 150000, 'status' => 'pending']);
        $service = app(XenditPaymentService::class);

        $created = $service->createSession($order, $student, 'COMPONENTS', 'https://bimbelcerdas.com');
        $reopened = $service->createSession($order->fresh(), $student, 'COMPONENTS', 'https://bimbelcerdas.com');

        $this->assertSame('COMPONENTS', $created['mode']);
        $this->assertSame('sdk-created', $created['components_sdk_key']);
        $this->assertNull($created['checkout_url']);
        $this->assertSame('sdk-reloaded', $reopened['components_sdk_key']);
        $this->assertSame($created['session_id'], $reopened['session_id']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['mode'] === 'COMPONENTS'
            && $request['components_configuration']['origins'] === ['https://bimbelcerdas.com']);
    }

    public function test_local_http_checkout_uses_hosted_link_and_reuses_one_session(): void
    {
        config()->set('cors.allowed_origins', ['http://127.0.0.1:8080']);
        Http::fake(['*/sessions' => Http::response([
            'payment_session_id' => 'ps-local-hosted',
            'payment_link_url' => 'https://checkout.xendit.test/local-hosted',
            'status' => 'ACTIVE', 'expires_at' => now()->addHour()->toIso8601String(),
        ])]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create(['user_id' => $student->id, 'order_id' => 'INV-LOCAL-HOSTED', 'amount' => 150000, 'status' => 'pending']);
        $service = app(XenditPaymentService::class);

        $created = $service->createSession($order, $student, 'COMPONENTS', 'http://127.0.0.1:8080');
        $reopened = $service->createSession($order->fresh(), $student, 'COMPONENTS', 'http://127.0.0.1:8080');

        $this->assertSame('PAYMENT_LINK', $created['mode']);
        $this->assertSame('https://checkout.xendit.test/local-hosted', $created['checkout_url']);
        $this->assertSame($created['session_id'], $reopened['session_id']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['mode'] === 'PAYMENT_LINK'
            && ! array_key_exists('components_configuration', $request->data()));
    }

    public function test_rejected_session_can_retry_with_a_new_reference_without_a_second_invoice(): void
    {
        $acceptRequest = false;
        Http::fake(function (Request $request) use (&$acceptRequest) {
            return $acceptRequest
                ? Http::response([
                    'payment_session_id' => 'ps-retry-hosted',
                    'payment_link_url' => 'https://checkout.xendit.test/retry-hosted',
                    'status' => 'ACTIVE', 'expires_at' => now()->addHour()->toIso8601String(),
                ])
                : Http::response(['error_code' => 'API_VALIDATION_ERROR', 'message' => 'Invalid payment session'], 400);
        });

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create(['user_id' => $student->id, 'order_id' => 'INV-RETRY-SESSION', 'amount' => 150000, 'status' => 'pending']);
        $service = app(XenditPaymentService::class);
        try {
            $service->createSession($order, $student);
            $this->fail('Sesi pertama seharusnya ditolak penyedia pembayaran.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SESSION_FAILED', $order->fresh()->gateway_status);
        }
        $firstReference = $order->fresh()->gateway_reference_id;
        $acceptRequest = true;

        $retry = $service->createSession($order->fresh(), $student);
        $this->assertSame('PAYMENT_LINK', $retry['mode']);
        $this->assertSame('ps-retry-hosted', $retry['session_id']);
        $this->assertNotSame($firstReference, $order->fresh()->gateway_reference_id);
        $this->assertSame(1, Order::query()->whereKey($order->id)->count());
    }

    public function test_embedded_checkout_rejects_unapproved_origin_before_calling_gateway(): void
    {
        config()->set('cors.allowed_origins', ['https://bimbelcerdas.com']);
        Http::fake();
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create(['user_id' => $student->id, 'order_id' => 'INV-ORIGIN', 'amount' => 150000, 'status' => 'pending']);

        $this->expectException(\RuntimeException::class);
        try {
            app(XenditPaymentService::class)->createSession($order, $student, 'COMPONENTS', 'https://untrusted.example');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_checkout_refund_and_tutor_payout_use_xendit_apis(): void
    {
        Http::fake([
            'https://api.xendit.test/sessions' => Http::response([
                'payment_session_id' => 'ps-test-1', 'payment_link_url' => 'https://checkout.xendit.test/1',
                'status' => 'ACTIVE', 'expires_at' => now()->addHour()->toIso8601String(),
            ]),
            'https://api.xendit.test/v3/refunds' => Http::response(['refund_id' => 'rfd-test-1', 'status' => 'PENDING']),
            'https://api.xendit.test/v3/payouts' => Http::response(['payout_id' => 'po-test-1', 'status' => 'ACCEPTED']),
        ]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create(['user_id' => $student->id, 'order_id' => 'INV-XENDIT-001', 'amount' => 150000, 'status' => 'pending']);
        app(XenditPaymentService::class)->createSession($order, $student);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_provider' => 'xendit', 'gateway_session_id' => 'ps-test-1']);

        $order->forceFill([
            'status' => 'refund_pending', 'payment_provider' => 'xendit',
            'gateway_payment_request_id' => 'pr-test-1', 'gateway_payment_id' => 'py-test-1',
        ])->save();
        $refund = Refund::create(['order_id' => $order->id, 'user_id' => $student->id, 'amount' => 150000, 'reason' => 'Test Xendit', 'status' => 'pending']);
        app(XenditMoneyMovementService::class)->createRefund($refund);
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'gateway_refund_id' => 'rfd-test-1', 'destination_method' => 'xendit_original']);

        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'address' => 'Yogyakarta']);
        TeacherProfile::create([
            'user_id' => $teacher->id, 'bank_name' => 'Bank Central Asia (BCA)', 'payout_channel_code' => 'BCA',
            'account_number' => '1234567890', 'account_name' => $teacher->name,
        ]);
        $payout = Payout::create([
            'user_id' => $teacher->id, 'amount' => 120000, 'period' => 'September 2026', 'total_classes' => 2,
            'status' => 'processing', 'payment_provider' => 'xendit', 'account_number' => '1234567890',
            'account_name' => $teacher->name, 'bank_name' => 'BCA', 'payout_channel_code' => 'BCA', 'booking_ids' => [],
        ]);
        app(XenditMoneyMovementService::class)->createPayout($payout, $teacher->load('teacherProfile'));
        $this->assertDatabaseHas('payouts', ['id' => $payout->id, 'gateway_payout_id' => 'po-test-1', 'gateway_status' => 'ACCEPTED']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.xendit.test/sessions'
            && $request['session_type'] === 'PAY'
            && $request['mode'] === 'PAYMENT_LINK'
            && $request['amount'] === 150000
            // Kode transaksi lokal (PKG-.../INV-...) menjadi reference_id utama
            // agar bisa difilter langsung pada dashboard Xendit.
            && $request['reference_id'] === $order->order_id
            && str_starts_with((string) data_get($request->data(), 'customer.reference_id'), 'USER-'.$student->id.'-'.$order->order_id));
        // Xendit rejects idempotency keys under 10 characters, so the id is
        // padded to a fixed width rather than used bare.
        // Payments API v3: refunds post to /v3/refunds with the required
        // api-version header and reference the captured payment_id, not the
        // payment request.
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.xendit.test/v3/refunds'
            && $request->hasHeader('api-version', '2024-11-11')
            && $request->hasHeader('idempotency-key', 'BKU-RFD-'.str_pad((string) $refund->id, 8, '0', STR_PAD_LEFT))
            && $request['payment_id'] === 'py-test-1'
            && ! array_key_exists('payment_request_id', $request->data())
            && ! array_key_exists('currency', $request->data())
            && $request['amount'] === 150000);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.xendit.test/v3/payouts'
            && $request->hasHeader('api-version', '2025-09-01')
            && $request->hasHeader('idempotency-key', 'BKU-PAY-'.str_pad((string) $payout->id, 8, '0', STR_PAD_LEFT))
            && data_get($request->data(), 'recipient.account_details.routing_value_1') === 'CENAIDJA');
    }

    public function test_automatic_refund_settles_immediate_gateway_success_only_once(): void
    {
        Http::fake(['https://api.xendit.test/v3/refunds' => Http::response(['refund_id' => 'rfd-auto-1', 'status' => 'SUCCEEDED'])]);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id, 'order_id' => 'INV-AUTO-REFUND', 'amount' => 150000,
            'status' => 'refund_pending',
        ]);
        $order->forceFill([
            'payment_provider' => 'xendit', 'gateway_payment_request_id' => 'pr-auto-1',
            'gateway_payment_id' => 'py-auto-1',
        ])->save();
        $refund = Refund::create([
            'order_id' => $order->id, 'user_id' => $student->id, 'amount' => 150000,
            'reason' => 'Tutor tidak ditemukan', 'status' => 'pending',
        ]);

        $settlement = app(RefundSettlementService::class);
        $this->assertNull($refund->fresh()->destination_selected_at);
        $this->assertFalse($settlement->startAutomatic($refund));
        Http::assertNothingSent();
        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'xendit_original',
        ], ['Idempotency-Key' => 'refund-auto-original-'.$refund->id])->assertOk();
        $this->assertTrue($settlement->startAutomatic($refund), json_encode($refund->fresh()->only('gateway_refund_id', 'gateway_status', 'status')));
        $this->assertTrue($settlement->startAutomatic($refund));
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'paid', 'gateway_refund_id' => 'rfd-auto-1']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        Http::assertSentCount(1);
    }

    public function test_gateway_rejection_routes_automatic_refund_to_manual_review(): void
    {
        Http::fake(['https://api.xendit.test/v3/refunds' => Http::response(['refund_id' => 'rfd-rejected-1', 'status' => 'FAILED'])]);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id, 'order_id' => 'INV-REJECTED-REFUND', 'amount' => 150000,
            'status' => 'refund_pending',
        ]);
        $order->forceFill([
            'payment_provider' => 'xendit', 'gateway_payment_request_id' => 'pr-rejected-1',
            'gateway_payment_id' => 'py-rejected-1',
        ])->save();
        $refund = Refund::create(['order_id' => $order->id, 'user_id' => $student->id, 'amount' => 150000, 'reason' => 'Tutor tidak ditemukan', 'status' => 'pending']);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'xendit_original',
        ], ['Idempotency-Key' => 'refund-rejected-original-'.$refund->id])->assertOk();
        $this->assertFalse(app(RefundSettlementService::class)->startAutomatic($refund));
        $this->assertDatabaseHas('notifications', ['unique_key' => "refund-review:{$refund->id}:{$admin->id}", 'is_read' => false]);
    }

    public function test_student_can_confirm_automatic_refund_to_bimbelku_balance_without_gateway_refund(): void
    {
        Http::fake();
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id, 'order_id' => 'INV-AUTO-WALLET-REFUND',
            'amount' => 150000, 'status' => 'refund_pending',
        ]);
        $order->forceFill(['payment_provider' => 'xendit', 'gateway_payment_request_id' => 'pr-wallet-choice-1'])->save();
        $refund = Refund::create([
            'order_id' => $order->id, 'user_id' => $student->id, 'amount' => 150000,
            'reason' => 'Tutor tidak ditemukan', 'status' => 'pending',
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => 'refund-auto-wallet-'.$refund->id])->assertOk();

        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id, 'status' => 'paid', 'destination_method' => 'bimbelku_balance',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        Http::assertNothingSent();
    }

    public function test_reconciliation_settles_refund_when_success_webhook_is_missing(): void
    {
        Http::fake([
            'https://api.xendit.test/v3/refunds/rfd-reconcile-1' => Http::response([
                'refund_id' => 'rfd-reconcile-1', 'status' => 'SUCCEEDED',
            ]),
        ]);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id, 'order_id' => 'INV-RECONCILE-REFUND', 'amount' => 150000,
            'status' => 'refund_pending', 'payment_provider' => 'xendit', 'gateway_payment_request_id' => 'pr-reconcile-1',
        ]);
        $refund = Refund::create([
            'order_id' => $order->id, 'user_id' => $student->id, 'amount' => 150000,
            'reason' => 'Tutor tidak ditemukan', 'status' => 'pending',
        ]);
        $refund->forceFill([
            'destination_method' => 'xendit_original', 'gateway_refund_id' => 'rfd-reconcile-1',
            'gateway_status' => 'PENDING', 'gateway_submitted_at' => now()->subMinutes(10),
        ])->saveQuietly();

        $this->assertTrue(app(RefundSettlementService::class)->reconcile($refund));
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'paid', 'gateway_status' => 'SUCCEEDED']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        Http::assertSentCount(1);
    }

    public function test_active_checkout_session_is_reused_after_customer_closes_it(): void
    {
        Http::fake([
            'https://api.xendit.test/sessions' => Http::response([
                'payment_session_id' => 'ps-reusable-1',
                'payment_link_url' => 'https://checkout.xendit.test/reusable-1',
                'status' => 'ACTIVE',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]),
        ]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-XENDIT-REUSE-001',
            'amount' => 175000,
            'status' => 'pending',
        ]);

        $service = app(XenditPaymentService::class);
        $first = $service->createSession($order, $student);
        $second = $service->createSession($order->fresh(), $student);

        $this->assertSame($first['session_id'], $second['session_id']);
        $this->assertSame($first['checkout_url'], $second['checkout_url']);
        Http::assertSentCount(1);
    }

    public function test_student_can_sync_completed_session_without_double_credit(): void
    {
        Http::fake([
            'https://api.xendit.test/sessions/ps-sync-1' => Http::response([
                'payment_session_id' => 'ps-sync-1',
                'payment_id' => 'py-sync-1',
                'payment_request_id' => 'pr-sync-1',
                'status' => 'COMPLETED',
                'amount' => 160000,
                'expires_at' => now()->addHour()->toIso8601String(),
            ]),
        ]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-XENDIT-SYNC-001',
            'amount' => 160000,
            'status' => 'pending',
            'payment_provider' => 'xendit',
            'gateway_session_id' => 'ps-sync-1',
            'gateway_status' => 'ACTIVE',
            'gateway_expires_at' => now()->addHour(),
        ]);

        $this->actingAs($student)
            ->postJson("/api/orders/{$order->id}/payment-status-sync")
            ->assertOk()
            ->assertJsonPath('gateway_status', 'COMPLETED');

        $this->actingAs($student)
            ->postJson("/api/orders/{$order->id}/payment-status-sync")
            ->assertOk();

        $order->refresh();
        $this->assertSame('submitted', $order->status);
        $this->assertSame(160000.0, (float) $order->external_received_amount);
        $this->assertSame('py-sync-1', $order->gateway_payment_id);
    }
}
