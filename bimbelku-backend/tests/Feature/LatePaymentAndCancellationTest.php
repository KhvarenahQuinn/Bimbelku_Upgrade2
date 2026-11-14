<?php

namespace Tests\Feature;

use App\Models\CheapClass;
use App\Models\CheapClassEnrollment;
use App\Models\FinancialJournal;
use App\Models\LearningPackage;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSubject;
use App\Models\Refund;
use App\Models\User;
use App\Services\CustomerWalletService;
use App\Services\XenditPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LatePaymentAndCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00', 'Asia/Jakarta'));
        config(['xendit.enabled' => true, 'xendit.secret_key' => 'test',
            'xendit.webhook_token' => 'test', 'xendit.base_url' => 'https://api.xendit.test']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_expired_payment_is_recorded_and_automatically_refunded_once(): void
    {
        $order = $this->invoice();
        $order->update(['status' => 'expired']);
        $order->learningPackage->update(['status' => 'payment_expired', 'payment_due_at' => null]);
        $this->fakeRefund();
        $this->receipt($order, 'late-first')->assertOk();
        $this->receipt($order, 'late-first')->assertOk();
        $this->receipt($order, 'late-duplicate')->assertOk();
        $this->assertSame('refund_pending', $order->fresh()->status);
        $this->assertSame(50000.0, (float) $order->fresh()->external_received_amount);
        $this->assertSame('COMPLETED', $order->fresh()->gateway_status);
        $this->assertSame('refund_pending', $order->learningPackage->fresh()->status);
        $this->assertDatabaseCount('refunds', 1);
        $refund = $order->refund()->firstOrFail();
        $this->assertSame('xendit_original', $refund->destination_method);
        $this->assertSame('late_gateway_payment', $refund->source_type);
        $this->assertSame('rfd-late', $refund->gateway_refund_id);
        $journal = FinancialJournal::where('event_key', "order:{$order->id}:late_gateway_receipt")->firstOrFail();
        $this->assertSame(50000.0, (float) $journal->entries()->where('side', 'debit')->sum('amount'));
        $this->assertSame(50000.0, (float) $journal->entries()->where('side', 'credit')->sum('amount'));
        Http::assertSentCount(1);
        $this->assertSame(1, Notification::where('unique_key', "late-payment-refund:{$order->id}")->count());
    }

    public function test_definitively_rejected_checkout_can_be_cancelled_without_remote_session(): void
    {
        $order = $this->invoice();
        $order->forceFill(['gateway_session_id' => null, 'gateway_reference_id' => null, 'gateway_status' => null])->save();
        Http::fake(['*/sessions' => Http::response(['error_code' => 'API_VALIDATION_ERROR'], 422)]);
        try {
            app(XenditPaymentService::class)->createSession($order, $order->user);
            $this->fail('Creation must be rejected.');
        } catch (\RuntimeException) {
        }
        $this->assertSame('SESSION_FAILED', $order->fresh()->gateway_status);
        $this->assertFalse($order->fresh()->gateway_creation_uncertain);
        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->learningPackage->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_cancelled_payment_immediate_refund_never_reactivates_package(): void
    {
        $order = $this->invoice();
        $order->update(['status' => 'cancelled']);
        $order->learningPackage->update(['status' => 'cancelled', 'payment_due_at' => null]);
        $this->fakeRefund('SUCCEEDED');
        $this->receipt($order, 'cancelled-paid')->assertOk();
        $this->receipt($order, 'cancelled-paid-repeat')->assertOk();
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('refunded', $order->learningPackage->fresh()->status);
        $this->assertSame('paid', $order->refund()->firstOrFail()->status);
        Http::assertSentCount(1);
    }

    public function test_overdue_pending_order_cannot_activate_before_expiry_worker_runs(): void
    {
        $order = $this->invoice();
        $order->learningPackage->update(['payment_due_at' => now()]);
        $this->fakeRefund();
        $this->receipt($order, 'overdue-pending')->assertOk();
        $this->assertSame('refund_pending', $order->fresh()->status);
        $this->assertSame('refund_pending', $order->learningPackage->fresh()->subjects()->first()->status);
    }

    public function test_released_wallet_hold_is_not_refunded_a_second_time(): void
    {
        $order = $this->invoice();
        $wallets = app(CustomerWalletService::class);
        $seed = Order::create(['user_id' => $order->user_id, 'amount' => 20000, 'status' => 'refund_pending']);
        $credit = Refund::create(['order_id' => $seed->id, 'user_id' => $order->user_id,
            'amount' => 20000, 'reason' => 'Seed saldo uji', 'status' => 'pending']);
        $wallets->creditRefund($credit);
        $wallets->reserveForPayment($order, $order->user_id, 20000);
        $order->forceFill(['gateway_expected_amount' => 30000])->save();
        $order->update(['status' => 'expired']);
        $this->assertSame(20000.0, $wallets->snapshot($order->user_id)['balance']);
        $this->fakeRefund('SUCCEEDED');
        $this->receipt($order, 'mixed-late', 30000)->assertOk();
        $refund = $order->refund()->firstOrFail();
        $this->assertSame(30000.0, (float) $refund->amount);
        $this->assertSame(0.0, (float) $refund->wallet_refund_amount);
        $this->assertSame(20000.0, $wallets->snapshot($order->user_id)['balance']);
        Http::assertSent(fn (Request $request) => $request['amount'] === 30000);
    }

    public function test_late_receipt_with_wrong_amount_does_not_record_or_refund(): void
    {
        $order = $this->invoice();
        $order->update(['status' => 'expired']);
        Http::fake();
        $this->receipt($order, 'wrong-late', 60000)->assertStatus(202);
        $this->assertSame(0.0, (float) $order->fresh()->external_received_amount);
        $this->assertDatabaseCount('refunds', 0);
        Http::assertNothingSent();
    }

    public function test_failed_refund_retains_receipt_and_can_be_retried_by_scheduler(): void
    {
        $order = $this->invoice();
        $order->update(['status' => 'expired']);
        $available = false;
        Http::fake(function () use (&$available) {
            return $available
                ? Http::response(['id' => 'rfd-retry', 'status' => 'SUCCEEDED'])
                : Http::response(['message' => 'Unavailable'], 500);
        });
        $this->receipt($order, 'refund-outage')->assertOk();
        $this->assertSame(50000.0, (float) $order->fresh()->external_received_amount);
        $this->assertSame('pending', $order->refund()->firstOrFail()->status);
        $available = true;
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_cancel_closes_gateway_checkout_before_local_order_and_is_idempotent(): void
    {
        $order = $this->invoice();
        $cancelled = false;
        Http::fake(function (Request $request) use ($order, &$cancelled) {
            if ($request->method() === 'POST') {
                $this->assertSame('pending', $order->fresh()->status);
                $cancelled = true;

                return Http::response(['status' => 'CANCELED']);
            }

            return Http::response(['status' => $cancelled ? 'CANCELED' : 'ACTIVE']);
        });
        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('CANCELED', $order->fresh()->gateway_status);
        $this->assertSame('cancelled', $order->learningPackage->fresh()->status);
        $this->assertSame(1, Http::recorded(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/cancel'))->count());
    }

    public function test_cancel_failure_leaves_order_payable_and_preserves_wallet_reservation(): void
    {
        $order = $this->invoice();
        $wallets = app(CustomerWalletService::class);
        $seed = Order::create(['user_id' => $order->user_id, 'amount' => 20000, 'status' => 'refund_pending']);
        $credit = Refund::create(['order_id' => $seed->id, 'user_id' => $order->user_id,
            'amount' => 20000, 'reason' => 'Seed saldo uji', 'status' => 'pending']);
        $wallets->creditRefund($credit);
        $wallets->reserveForPayment($order, $order->user_id, 20000);
        Http::fake(function (Request $request) {
            return $request->method() === 'POST'
                ? Http::response(['message' => 'Unavailable'], 500)
                : Http::response(['status' => 'ACTIVE']);
        });
        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(502);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('awaiting_payment', $order->learningPackage->fresh()->status);
        $this->assertSame('ACTIVE', $order->fresh()->gateway_status);
        $this->assertSame(20000.0, $wallets->snapshot($order->user_id)['reserved_balance']);
        $this->assertSame(0.0, $wallets->snapshot($order->user_id)['balance']);
    }

    public function test_missing_original_payment_reference_is_recovered_before_automatic_refund(): void
    {
        $order = $this->invoice();
        $order->update(['status' => 'expired']);
        Http::fake([
            '*/sessions/*' => Http::response(['status' => 'COMPLETED', 'amount' => 50000,
                'payment_id' => 'pay-recovered', 'payment_request_id' => 'pr-recovered']),
            '*/refunds' => Http::response(['refund_id' => 'rfd-recovered', 'status' => 'SUCCEEDED']),
        ]);
        $this->postJson('/api/webhooks/xendit', ['id' => 'missing-payment-request', 'event' => 'payment_session.completed',
            'data' => ['payment_session_id' => $order->gateway_session_id, 'status' => 'COMPLETED', 'amount' => 50000]],
            ['x-callback-token' => 'test'])->assertOk();
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('pr-recovered', $order->fresh()->gateway_payment_request_id);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v3/refunds')
            && $request->hasHeader('api-version', '2024-11-11')
            && $request['payment_id'] === 'pay-recovered');
    }

    public function test_other_user_cannot_cancel_or_close_checkout(): void
    {
        $order = $this->invoice();
        Http::fake();
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'status' => 'active']));
        $this->postJson("/api/orders/{$order->id}/cancel")->assertNotFound();
        $this->assertSame('pending', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_payment_winning_cancel_race_is_settled_instead_of_cancelled(): void
    {
        $order = $this->invoice();
        $reads = 0;
        Http::fake(function (Request $request) use (&$reads) {
            if ($request->method() === 'POST') {
                return Http::response(['message' => 'Already completed'], 409);
            }
            $reads++;

            return Http::response($reads === 1 ? ['status' => 'ACTIVE'] : [
                'status' => 'COMPLETED', 'amount' => 50000,
                'payment_id' => 'pay-race', 'payment_request_id' => 'pr-race',
            ]);
        });
        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertUnprocessable();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertNotSame('cancelled', $order->learningPackage->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_in_flight_checkout_without_session_id_cannot_be_cancelled_blindly(): void
    {
        $order = $this->invoice();
        $order->forceFill(['gateway_session_id' => null, 'gateway_status' => 'CREATING'])->save();
        Http::fake();
        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(502);
        $this->assertSame('pending', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_server_reconciliation_recovers_missing_webhook_without_browser(): void
    {
        $order = $this->invoice();
        $order->update(['status' => 'expired']);
        Http::fake([
            '*/sessions/*' => Http::response(['status' => 'COMPLETED', 'amount' => 50000,
                'payment_id' => 'pay-reconcile', 'payment_request_id' => 'pr-reconcile']),
            '*/refunds' => Http::response(['id' => 'rfd-reconcile', 'status' => 'SUCCEEDED']),
        ]);
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(50000.0, (float) $order->fresh()->external_received_amount);
        $this->assertNotNull($order->fresh()->gateway_last_checked_at);
        Http::assertSentCount(2);
    }

    public function test_shared_class_checkout_is_cancelled_and_late_payment_does_not_reclaim_seat(): void
    {
        $order = $this->invoice();
        $class = CheapClass::create(['subject_name' => 'Matematika', 'education_level' => 'SD', 'grade' => 'Kelas 6', 'chapter' => 'Pecahan',
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(),
            'session_count' => 1, 'registration_opens_at' => now()->subDay(),
            'registration_deadline' => now()->addDay(), 'price_per_session' => 50000,
            'price_per_student' => 50000, 'minimum_participants' => 2, 'maximum_participants' => 6,
            'payment_window_minutes' => 60, 'status' => 'open']);
        $enrollment = CheapClassEnrollment::create(['cheap_class_id' => $class->id,
            'student_id' => $order->user_id, 'amount' => 50000,
            'status' => 'seat_held', 'seat_expires_at' => now()->addHour()]);
        $order->update(['learning_package_id' => null, 'cheap_class_enrollment_id' => $enrollment->id]);
        Http::fake(['*/cancel' => Http::response(['status' => 'CANCELED']),
            '*/sessions/*' => Http::response(['status' => 'ACTIVE']),
            '*/refunds' => Http::response(['id' => 'rfd-shared', 'status' => 'PENDING'])]);
        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $enrollment->fresh()->status);
        $this->receipt($order, 'shared-late')->assertOk();
        $this->assertSame('refund_pending', $enrollment->fresh()->status);
        $this->assertSame('open', $class->fresh()->status);
        $this->assertSame(0, $class->enrollments()->where('status', 'confirmed')->count());
    }

    private function receipt(Order $order, string $event, int $amount = 50000)
    {
        return $this->postJson('/api/webhooks/xendit', ['id' => $event, 'event' => 'payment_session.completed',
            'data' => ['payment_session_id' => $order->gateway_session_id, 'status' => 'COMPLETED',
                'amount' => $amount, 'payment_id' => 'pay-'.$order->id, 'payment_request_id' => 'pr-'.$order->id]],
            ['x-callback-token' => 'test']);
    }

    private function fakeRefund(string $status = 'PENDING'): void
    {
        Http::fake(['*/refunds' => Http::response(['id' => 'rfd-late', 'status' => $status])]);
    }

    private function invoice(): Order
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $plan = PackagePlan::create(['name' => 'Paket Audit', 'slug' => 'audit', 'session_count' => 1,
            'validity_days' => 30, 'maximum_subjects' => 1, 'is_active' => true]);
        $package = LearningPackage::create(['student_id' => $student->id, 'package_plan_id' => $plan->id,
            'package_code' => 'PKG-AUDIT', 'education_level' => 'SMP', 'learning_mode' => 'online',
            'total_sessions' => 1, 'subtotal_amount' => 50000, 'total_amount' => 50000,
            'status' => 'awaiting_payment', 'payment_due_at' => now()->addHour()]);
        PackageSubject::create(['learning_package_id' => $package->id, 'subject_name' => 'Matematika',
            'allocated_sessions' => 1, 'unit_price' => 50000, 'subtotal_amount' => 50000, 'status' => 'awaiting_payment']);
        $order = Order::create(['user_id' => $student->id, 'learning_package_id' => $package->id,
            'amount' => 50000, 'status' => 'pending']);
        $order->forceFill(['payment_provider' => 'xendit', 'gateway_session_id' => 'ps-audit',
            'gateway_reference_id' => 'ref-audit', 'gateway_status' => 'ACTIVE',
            'gateway_expected_amount' => 50000, 'gateway_expires_at' => now()->addHour()])->save();

        return $order;
    }
}
