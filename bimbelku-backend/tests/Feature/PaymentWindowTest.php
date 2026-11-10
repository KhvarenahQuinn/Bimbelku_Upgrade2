<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\XenditPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PaymentWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00', 'Asia/Jakarta'));
        config(['xendit.enabled' => true, 'xendit.secret_key' => 'test', 'xendit.webhook_token' => 'test', 'xendit.base_url' => 'https://api.xendit.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_checkout_uses_original_one_hour_deadline_and_reopening_does_not_restart_it(): void
    {
        [$student, $order] = $this->invoice();
        $deadline = now()->addHour();
        Carbon::setTestNow(now()->addMinutes(20));
        Http::fake(['*/sessions' => Http::response(['payment_session_id' => 'ps-hour', 'payment_link_url' => 'https://checkout.xendit.test/hour', 'status' => 'ACTIVE'])]);

        $service = app(XenditPaymentService::class);
        $service->createSession($order, $student);
        Http::assertSent(fn (Request $request) => Carbon::parse($request['expires_at'])->equalTo($deadline));
        $this->assertTrue($order->fresh()->gateway_expires_at->equalTo($deadline));

        Carbon::setTestNow(now()->addMinutes(35));
        $service->createSession($order->fresh(), $student);
        Http::assertSentCount(1);
        $this->assertTrue($order->fresh()->gateway_expires_at->equalTo($deadline));
    }

    public function test_expired_invoice_cannot_reopen_checkout_even_with_a_stale_active_session(): void
    {
        [$student, $order] = $this->invoice();
        $order->forceFill(['gateway_session_id' => 'ps-stale', 'gateway_status' => 'ACTIVE', 'gateway_expires_at' => now()->addHours(2)])->save();
        Carbon::setTestNow(now()->addHour());
        Http::fake();
        try {
            app(XenditPaymentService::class)->createSession($order->fresh(), $student);
            $this->fail('Expired invoices must not create or reuse checkout.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Batas pembayaran sudah berakhir', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_gateway_minimum_session_duration_never_extends_an_invoice(): void
    {
        [$student, $order] = $this->invoice();
        Carbon::setTestNow(now()->addMinutes(55));
        Http::fake();
        try {
            app(XenditPaymentService::class)->createSession($order, $student);
            $this->fail('New checkout must not extend a nearly expired invoice.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('kurang dari 10 menit', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertNull($order->fresh()->gateway_reference_id);
    }

    public function test_retry_of_in_flight_session_preserves_its_expiry_and_idempotency_key(): void
    {
        [$student, $order] = $this->invoice();
        $deadline = now()->addMinutes(40);
        $order->forceFill(['gateway_status' => 'CREATING', 'gateway_reference_id' => 'BKU-in-flight', 'gateway_expires_at' => $deadline])->save();
        Carbon::setTestNow(now()->addMinutes(5));
        Http::fake(['*/sessions' => Http::response(['payment_session_id' => 'ps-retry', 'payment_link_url' => 'https://checkout.xendit.test/retry', 'status' => 'ACTIVE'])]);
        app(XenditPaymentService::class)->createSession($order, $student);
        Http::assertSent(fn (Request $request) => Carbon::parse($request['expires_at'])->equalTo($deadline)
            && $request->hasHeader('Idempotency-key', 'xendit-session-BKU-in-flight'));
        $this->assertTrue($order->fresh()->gateway_expires_at->equalTo($deadline));
    }

    private function invoice(): array
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create(['user_id' => $student->id, 'order_id' => 'INV-HOUR', 'amount' => 50000, 'status' => 'pending']);

        return [$student, $order];
    }
}
