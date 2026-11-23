<?php

namespace Tests\Feature;

use App\Models\LearningPackage;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\User;
use App\Services\XenditPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00', 'Asia/Jakarta'));
        config(['xendit.enabled' => true, 'xendit.secret_key' => 'test', 'xendit.webhook_token' => 'test',
            'xendit.base_url' => 'https://api.xendit.test',
            'xendit.success_url' => 'https://bimbelku.test/payment?gateway=success&order_id=999#receipt',
            'xendit.cancel_url' => 'https://bimbelku.test/payment?gateway=cancelled',
            'cors.allowed_origins' => ['https://bimbelku.test']]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hosted_returns_bind_invoice_and_preserve_query_and_fragment(): void
    {
        $order = $this->invoice();
        $this->fakeSession();
        app(XenditPaymentService::class)->createSession($order, $order->user);
        Http::assertSent(fn (Request $request) => $request['success_return_url'] === "https://bimbelku.test/payment?gateway=success&order_id={$order->id}#receipt"
            && $request['cancel_return_url'] === "https://bimbelku.test/payment?gateway=cancelled&order_id={$order->id}");
    }

    public function test_components_return_binds_the_same_invoice(): void
    {
        $order = $this->invoice();
        $this->fakeSession();
        app(XenditPaymentService::class)->createSession($order, $order->user, 'COMPONENTS', 'https://bimbelku.test');
        Http::assertSent(fn (Request $request) => $request['components_configuration']['return_url'] === "https://bimbelku.test/payment?checkout=resume&order_id={$order->id}"
            && ! isset($request['success_return_url']));
    }

    public function test_paid_invoice_is_retrievable_even_when_a_newer_invoice_is_pending(): void
    {
        $order = $this->invoice();
        $order->update(['status' => 'paid']);
        Carbon::setTestNow(now()->addMinute());
        $newer = $this->invoice($order->user);
        Sanctum::actingAs($order->user);
        $this->getJson('/api/active-order')->assertOk()->assertJsonPath('order_id', $newer->id);
        $this->getJson("/api/student/orders/{$order->id}/status")->assertOk()
            ->assertJsonPath('id', $order->id)->assertJsonPath('status', 'paid')
            ->assertJsonPath('order_number', $order->order_id)->assertJsonPath('subject', 'Matematika')
            ->assertJsonPath('package_name', 'Paket Return')->assertJsonPath('duration_hours', 2);
        Http::assertNothingSent();
    }

    public function test_numeric_id_never_matches_another_invoice_number(): void
    {
        $owned = $this->invoice();
        $foreign = $this->invoice();
        $owned->update(['order_id' => (string) $foreign->id]);
        Sanctum::actingAs($owned->user);
        $this->getJson("/api/student/orders/{$foreign->id}/status")->assertNotFound();
        $this->getJson('/api/student/orders/999999/status')->assertNotFound();
    }

    public function test_non_numeric_invoice_number_remains_supported(): void
    {
        $order = $this->invoice();
        Sanctum::actingAs($order->user);
        $this->getJson("/api/student/orders/{$order->order_id}/status")->assertOk()->assertJsonPath('id', $order->id);
    }

    public function test_expired_invoice_return_retains_identity_and_notification_has_no_old_deadline(): void
    {
        $order = $this->invoice();
        Carbon::setTestNow(now()->addHour()->addSecond());
        Sanctum::actingAs($order->user);
        $this->getJson('/api/active-order')->assertOk()->assertJsonMissingPath('order_id');
        $this->getJson("/api/student/orders/{$order->id}/status")->assertOk()
            ->assertJsonPath('id', $order->id)->assertJsonPath('status', 'expired');
        $notification = Notification::where('user_id', $order->user_id)->where('title', 'Batas pembayaran paket berakhir')->sole();
        $this->assertSame('Tagihan dibatalkan karena batas waktu pembayaran sudah berakhir.', $notification->message);
        $this->assertStringNotContainsString('48 jam', $notification->message);
    }

    public function test_order_status_reports_only_the_amount_still_needing_an_external_transfer(): void
    {
        $order = $this->invoice();
        $order->forceFill([
            'status' => 'partially_paid',
            'amount' => 150000,
            'wallet_reserved_amount' => 40000,
            'wallet_applied_amount' => 0,
            'external_received_amount' => 60000,
        ])->save();
        Sanctum::actingAs($order->user);

        $response = $this->getJson("/api/student/orders/{$order->id}/status")->assertOk();
        // 150.000 - 40.000 saldo tertahan - 60.000 transfer yang sudah masuk.
        $this->assertSame(50000.0, (float) $response->json('external_payment_amount'));

        // Setelah saldo benar-benar terpakai, tidak ada sisa yang perlu ditransfer.
        $order->forceFill([
            'status' => 'paid',
            'wallet_reserved_amount' => 0,
            'wallet_applied_amount' => 40000,
            'external_received_amount' => 110000,
        ])->save();
        $paid = $this->getJson("/api/student/orders/{$order->id}/status")->assertOk();
        $this->assertSame(0.0, (float) $paid->json('external_payment_amount'));
    }

    private function fakeSession(): void
    {
        Http::fake(['*/sessions' => Http::response(['payment_session_id' => 'ps-return', 'status' => 'ACTIVE',
            'payment_link_url' => 'https://checkout.xendit.test/return', 'components_sdk_key' => 'sdk-return'])]);
    }

    private function invoice(?User $user = null): Order
    {
        $user ??= User::factory()->create(['role' => 'student', 'status' => 'active']);
        $plan = PackagePlan::firstOrCreate(['slug' => 'return-test'], ['name' => 'Paket Return', 'session_count' => 1,
            'validity_days' => 30, 'maximum_subjects' => 1, 'is_active' => true]);
        $package = LearningPackage::create(['student_id' => $user->id, 'package_plan_id' => $plan->id,
            'package_code' => 'PKG-RETURN-'.Str::uuid(),
            'education_level' => 'SMP', 'learning_mode' => 'online', 'duration_hours' => 2,
            'total_sessions' => 1, 'subtotal_amount' => 100000, 'total_amount' => 90000,
            'status' => 'awaiting_payment', 'payment_due_at' => now()->addHour()]);

        return Order::create(['user_id' => $user->id, 'learning_package_id' => $package->id,
            'amount' => 90000, 'subtotal_amount' => 100000, 'discount_amount' => 10000, 'status' => 'pending',
            'class_details_snapshot' => ['subject' => 'Matematika', 'method' => 'online', 'duration_hours' => 2]]);
    }
}
