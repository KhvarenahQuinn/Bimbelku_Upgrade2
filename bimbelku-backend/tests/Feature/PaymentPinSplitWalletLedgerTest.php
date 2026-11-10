<?php

namespace Tests\Feature;

use App\Models\CustomerWallet;
use App\Models\CustomerWalletTransaction;
use App\Models\LearningPackage;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSubject;
use App\Models\Refund;
use App\Models\User;
use App\Services\CustomerWalletService;
use App\Services\WalletIntegrityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PaymentPinSplitWalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_split_test');
        config()->set('xendit.webhook_token', 'callback-split-test');
        config()->set('xendit.base_url', 'https://api.xendit.test');
    }

    public function test_password_account_resets_pin_after_forgot_verification(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('password123'),
            'has_password' => true,
            'payment_pin_hash' => Hash::make('111111'),
        ]);
        Sanctum::actingAs($student);

        $this->postJson('/api/student/payment-pin/forgot')
            ->assertOk()
            ->assertJsonPath('verification_method', 'password')
            ->assertJsonPath('requires_email_code', false);

        $this->postJson('/api/student/payment-pin/reset', [
            'current_password' => 'password123',
            'pin' => '222222',
            'pin_confirmation' => '222222',
        ])->assertOk()->assertJsonPath('verified_with', 'password');

        $this->assertTrue(Hash::check('222222', $student->fresh()->payment_pin_hash));

        $this->postJson('/api/student/payment-pin/reset', [
            'current_password' => 'wrong-password',
            'pin' => '333333',
            'pin_confirmation' => '333333',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_oauth_account_resets_pin_with_email_code_sent_by_forgot_endpoint(): void
    {
        Mail::fake();
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make(\Illuminate\Support\Str::random(32)),
            'has_password' => false,
            'payment_pin_hash' => Hash::make('111111'),
        ]);
        Sanctum::actingAs($student);

        $this->postJson('/api/student/payment-pin/forgot')
            ->assertOk()
            ->assertJsonPath('verification_method', 'email')
            ->assertJsonPath('requires_email_code', true);

        $this->assertDatabaseCount('verification_codes', 1);

        // Replace the generated code with a known one so the reset can be verified.
        DB::table('verification_codes')->where('user_id', $student->id)->update([
            'code_hash' => Hash::make('654321'),
            'attempts' => 0,
            'used_at' => null,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/student/payment-pin/reset', [
            'code' => '654321',
            'pin' => '444444',
            'pin_confirmation' => '444444',
        ])->assertOk()->assertJsonPath('verified_with', 'email');

        $this->assertTrue(Hash::check('444444', $student->fresh()->payment_pin_hash));
    }

    public function test_split_payment_reserves_wallet_and_invoices_only_the_remainder(): void
    {
        Http::fake([
            'https://api.xendit.test/sessions' => Http::response([
                'payment_session_id' => 'ps-split-1',
                'status' => 'ACTIVE',
                'payment_link_url' => 'https://checkout.xendit.test/split-1',
            ]),
        ]);

        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('123456'),
        ]);
        $this->creditWallet($student, 40000);
        $order = $this->supportedOrder($student, 100000, 'INV-SPLIT-WALLET-1');
        Sanctum::actingAs($student);

        $response = $this->postJson("/api/orders/{$order->id}/xendit-session", [
            'mode' => 'payment_link',
            'use_wallet' => true,
            'wallet_expected_amount' => 40000,
            'payment_pin' => '123456',
        ], ['Idempotency-Key' => 'split-wallet-1']);
        $response->assertOk();

        $response->assertJsonPath('data.wallet_amount', 40000)
            ->assertJsonPath('data.xendit_amount', 60000)
            ->assertJsonPath('data.checkout_url', 'https://checkout.xendit.test/split-1');

        $order->refresh();
        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('40000.00', $order->wallet_reserved_amount);
        $this->assertSame('wallet_plus_xendit', $order->payment_provider);
        $this->assertSame('0.00', $wallet->current_balance);
        $this->assertSame('40000.00', $wallet->reserved_balance);
        $this->assertSame(60000, (int) $order->gateway_expected_amount);

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/sessions')
            && (int) $request['amount'] === 60000
            && $request['reference_id'] === 'INV-SPLIT-WALLET-1');
    }

    public function test_expired_split_session_returns_the_wallet_hold(): void
    {
        Http::fake([
            'https://api.xendit.test/sessions' => Http::response([
                'payment_session_id' => 'ps-split-expire',
                'status' => 'ACTIVE',
                'payment_link_url' => 'https://checkout.xendit.test/split-expire',
            ]),
        ]);

        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('123456'),
        ]);
        $this->creditWallet($student, 40000);
        $order = $this->supportedOrder($student, 100000, 'INV-SPLIT-EXPIRE');
        Sanctum::actingAs($student);

        $this->postJson("/api/orders/{$order->id}/xendit-session", [
            'mode' => 'payment_link',
            'use_wallet' => true,
            'wallet_expected_amount' => 40000,
            'payment_pin' => '123456',
        ], ['Idempotency-Key' => 'split-expire-1'])->assertOk();

        $this->postJson('/api/webhooks/xendit', [
            'id' => 'evt-split-expire-1',
            'event' => 'payment_session.expired',
            'data' => [
                'payment_session_id' => 'ps-split-expire',
                'reference_id' => 'INV-SPLIT-EXPIRE',
                'status' => 'EXPIRED',
            ],
        ], ['x-callback-token' => 'callback-split-test'])->assertOk();

        $order->refresh();
        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('EXPIRED', $order->gateway_status);
        $this->assertSame('0.00', $order->wallet_reserved_amount);
        $this->assertSame('40000.00', $wallet->current_balance);
        $this->assertSame('0.00', $wallet->reserved_balance);
        $this->assertDatabaseHas('customer_wallet_transactions', [
            'order_id' => $order->id,
            'type' => 'payment_release',
            'amount' => 40000,
        ]);
    }

    public function test_failed_split_session_also_returns_the_wallet_hold(): void
    {
        Http::fake([
            'https://api.xendit.test/sessions' => Http::response([
                'payment_session_id' => 'ps-split-fail',
                'status' => 'ACTIVE',
                'payment_link_url' => 'https://checkout.xendit.test/split-fail',
            ]),
        ]);

        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('123456'),
        ]);
        $this->creditWallet($student, 40000);
        $order = $this->supportedOrder($student, 100000, 'INV-SPLIT-FAIL');
        Sanctum::actingAs($student);

        $this->postJson("/api/orders/{$order->id}/xendit-session", [
            'mode' => 'payment_link',
            'use_wallet' => true,
            'wallet_expected_amount' => 40000,
            'payment_pin' => '123456',
        ], ['Idempotency-Key' => 'split-fail-1'])->assertOk();

        $this->postJson('/api/webhooks/xendit', [
            'id' => 'evt-split-fail-1',
            'event' => 'payment_session.failed',
            'data' => [
                'payment_session_id' => 'ps-split-fail',
                'reference_id' => 'INV-SPLIT-FAIL',
                'status' => 'FAILED',
            ],
        ], ['x-callback-token' => 'callback-split-test'])->assertOk();

        $order->refresh();
        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('FAILED', $order->gateway_status);
        $this->assertSame('0.00', $order->wallet_reserved_amount);
        $this->assertSame('40000.00', $wallet->current_balance);
        $this->assertSame('0.00', $wallet->reserved_balance);
    }

    public function test_expired_split_session_can_retry_with_a_rotated_reference(): void
    {
        $sessionIds = ['ps-split-retry-1', 'ps-split-retry-2'];
        $index = 0;
        Http::fake([
            'https://api.xendit.test/sessions' => function (ClientRequest $request) use (&$index, $sessionIds) {
                $id = $sessionIds[$index++] ?? 'ps-split-retry-extra';

                return Http::response([
                    'payment_session_id' => $id,
                    'status' => 'ACTIVE',
                    'payment_link_url' => 'https://checkout.xendit.test/'.$id,
                ]);
            },
        ]);

        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('123456'),
        ]);
        $this->creditWallet($student, 40000);
        $order = $this->supportedOrder($student, 100000, 'INV-SPLIT-RETRY');
        Sanctum::actingAs($student);

        $this->postJson("/api/orders/{$order->id}/xendit-session", [
            'mode' => 'payment_link',
            'use_wallet' => true,
            'wallet_expected_amount' => 40000,
            'payment_pin' => '123456',
        ], ['Idempotency-Key' => 'split-retry-1'])->assertOk();

        $firstReference = $order->fresh()->gateway_reference_id;
        $this->assertSame('INV-SPLIT-RETRY', $firstReference);
        $this->assertSame('ps-split-retry-1', $order->fresh()->gateway_session_id);

        $this->postJson('/api/webhooks/xendit', [
            'id' => 'evt-split-retry-expire',
            'event' => 'payment_session.expired',
            'data' => [
                'payment_session_id' => 'ps-split-retry-1',
                'reference_id' => $firstReference,
                'status' => 'EXPIRED',
            ],
        ], ['x-callback-token' => 'callback-split-test'])->assertOk();

        $this->assertSame('40000.00', CustomerWallet::query()->where('user_id', $student->id)->firstOrFail()->current_balance);

        $retry = $this->postJson("/api/orders/{$order->id}/xendit-session", [
            'mode' => 'payment_link',
            'use_wallet' => true,
            'wallet_expected_amount' => 40000,
            'payment_pin' => '123456',
        ], ['Idempotency-Key' => 'split-retry-2'])->assertOk();

        $retry->assertJsonPath('data.wallet_amount', 40000)
            ->assertJsonPath('data.xendit_amount', 60000)
            ->assertJsonPath('data.checkout_url', 'https://checkout.xendit.test/ps-split-retry-2');

        $order->refresh();
        $this->assertSame('ps-split-retry-2', $order->gateway_session_id);
        $this->assertNotSame($firstReference, $order->gateway_reference_id);
        $this->assertStringStartsWith('INV-SPLIT-RETRY-', (string) $order->gateway_reference_id);
        $this->assertSame('40000.00', $order->wallet_reserved_amount);

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/sessions')
            && (int) $request['amount'] === 60000
            && $request['reference_id'] === $order->gateway_reference_id);
    }

    public function test_split_payment_requires_a_valid_pin(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('123456'),
        ]);
        $this->creditWallet($student, 40000);
        $order = $this->supportedOrder($student, 100000, 'INV-SPLIT-PIN');
        Sanctum::actingAs($student);

        $this->postJson("/api/orders/{$order->id}/xendit-session", [
            'use_wallet' => true,
            'wallet_expected_amount' => 40000,
            'payment_pin' => '000000',
        ], ['Idempotency-Key' => 'split-pin-1'])->assertStatus(422)->assertJsonPath('code', 'payment_pin_invalid');

        $this->assertSame('0.00', $order->fresh()->wallet_reserved_amount);
    }

    public function test_ledger_checksum_detects_tampering_and_blocks_mutations(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->creditWallet($student, 60000);
        $order = $this->supportedOrder($student, 40000, 'INV-LEDGER-TAMPER');

        $this->assertSame([], app(WalletIntegrityService::class)->errors());

        $transaction = CustomerWalletTransaction::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertNotNull($transaction->checksum);
        $this->assertNotNull($transaction->reference_type);
        $this->assertNotNull($transaction->reference_id);

        DB::table('customer_wallet_transactions')->where('id', $transaction->id)->update(['amount' => 999999]);

        $errors = app(WalletIntegrityService::class)->errors();
        $this->assertTrue(collect($errors)->contains(
            fn (string $error) => str_contains($error, 'Checksum')
        ));

        try {
            app(CustomerWalletService::class)->reserveForPayment($order, $student->id, null, $student->id);
            $this->fail('A tampered ledger must not be allowed to move money.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }

        $this->assertSame('0.00', $order->fresh()->wallet_reserved_amount);
    }

    public function test_reference_pair_is_unique_to_prevent_duplicate_credits(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->creditWallet($student, 60000);

        $transaction = CustomerWalletTransaction::query()->where('user_id', $student->id)->firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('customer_wallet_transactions')->insert([
            'customer_wallet_id' => $transaction->customer_wallet_id,
            'user_id' => $transaction->user_id,
            'refund_id' => null,
            'order_id' => $transaction->order_id,
            'actor_id' => null,
            'event_key' => 'manual-duplicate-reference',
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'type' => 'refund_credit',
            'direction' => 'credit',
            'amount' => 60000,
            'balance_before' => 0,
            'balance_after' => 60000,
            'description' => 'Injeksi manual dengan referensi yang sama',
            'metadata' => null,
            'checksum' => null,
            'created_at' => now(),
        ]);
    }

    private function creditWallet(User $student, float $amount): void
    {
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-REFUND-SEED-'.$student->id.'-'.((int) $amount),
            'amount' => $amount,
            'status' => 'refund_pending',
        ]);
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => $amount,
            'reason' => 'Seed saldo melalui lifecycle refund yang tercatat.',
            'status' => 'pending',
            'destination_method' => 'bimbelku_balance',
            'destination_selected_at' => now(),
        ]);

        app(CustomerWalletService::class)->creditRefund($refund, $student->id);
        $refund->update(['status' => 'paid', 'processed_at' => now()]);
        $order->update(['status' => 'refunded']);
    }

    private function supportedOrder(User $student, float $amount, string $invoice): Order
    {
        $plan = PackagePlan::create([
            'name' => 'Paket Split '.$invoice,
            'slug' => strtolower(str_replace('_', '-', $invoice)),
            'session_count' => 1,
            'validity_days' => 30,
            'maximum_subjects' => 1,
            'is_active' => true,
        ]);
        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-'.$invoice,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'status' => 'awaiting_payment',
            'total_sessions' => 1,
            'subtotal_amount' => $amount,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'payment_due_at' => now()->addDay(),
        ]);
        PackageSubject::create([
            'learning_package_id' => $package->id,
            'subject_name' => 'Matematika '.$invoice,
            'allocated_sessions' => 1,
            'unit_price' => $amount,
            'subtotal_amount' => $amount,
            'status' => 'awaiting_payment',
        ]);

        return Order::create([
            'user_id' => $student->id,
            'learning_package_id' => $package->id,
            'order_id' => $invoice,
            'amount' => $amount,
            'status' => 'pending',
        ]);
    }
}
