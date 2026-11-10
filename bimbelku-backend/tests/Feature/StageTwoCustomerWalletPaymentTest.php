<?php

namespace Tests\Feature;

use App\Models\CustomerWallet;
use App\Models\FinancialJournal;
use App\Models\LearningPackage;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSubject;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerWalletService;
use App\Services\WalletIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StageTwoCustomerWalletPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This class covers the wallet/manual fallback, independently of the
        // developer machine's Xendit-enabled local configuration.
        config()->set('xendit.enabled', false);
    }

    public function test_student_owns_refund_destination_and_has_no_self_credit_endpoint(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        $otherStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-WALLET-OWNER-ST2',
            'amount' => 75000,
            'status' => 'refund_pending',
        ]);
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 75000,
            'reason' => 'Refund uji kepemilikan tujuan.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($otherStudent);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => 'wallet-other-student-st2'])
            ->assertForbidden();

        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => 'wallet-owner-student-st2'])
            ->assertOk()
            ->assertJsonPath('refund.destination_method', 'bimbelku_balance');

        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id,
            'destination_method' => 'bimbelku_balance',
        ]);
        $this->assertNotNull($refund->fresh()->destination_selected_at);

        $response = $this->postJson('/api/student/wallet', ['current_balance' => 999999999]);
        $this->assertContains($response->status(), [404, 405]);
        $this->assertDatabaseMissing('customer_wallets', ['user_id' => $student->id]);
    }

    public function test_admin_cannot_complete_refund_before_student_selects_destination(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('123456'),
        ]);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-WALLET-NO-DEST-ST2',
            'amount' => 45000,
            'status' => 'refund_pending',
        ]);
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 45000,
            'reason' => 'Refund belum punya tujuan.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/refunds/{$refund->id}/complete", [
            'destination_selection_version' => 1,
        ], [
            'Idempotency-Key' => 'wallet-admin-no-destination-st2',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('customer_wallet_transactions', ['refund_id' => $refund->id]);
    }

    public function test_admin_refund_rejects_stale_student_destination_snapshot(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-WALLET-DEST-VERSION-ST2',
            'amount' => 90000,
            'status' => 'refund_pending',
        ]);
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 90000,
            'reason' => 'Refund uji snapshot tujuan.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => 'wallet-destination-v1-st2'])->assertOk();
        $staleVersion = (int) $refund->fresh()->destination_selection_version;

        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bank_transfer',
            'bank_name' => 'BCA',
            'account_name' => 'Murid Tujuan Baru',
            'account_number' => '1234567890',
        ], ['Idempotency-Key' => 'wallet-destination-v2-st2'])->assertOk();
        $this->assertSame($staleVersion + 1, (int) $refund->fresh()->destination_selection_version);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/refunds/{$refund->id}/complete", [
            'destination_selection_version' => $staleVersion,
        ], ['Idempotency-Key' => 'wallet-stale-destination-admin-st2'])
            ->assertStatus(409);

        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id,
            'status' => 'pending',
            'destination_method' => 'bank_transfer',
        ]);
        $this->assertDatabaseMissing('customer_wallet_transactions', ['refund_id' => $refund->id]);
    }

    public function test_stale_expected_balance_cannot_double_spend_wallet(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->creditWallet($student, 100000);
        $wallets = app(CustomerWalletService::class);

        $first = $this->supportedOrder($student, 80000, 'INV-WALLET-FIRST-ST2');
        $second = $this->supportedOrder($student, 80000, 'INV-WALLET-SECOND-ST2');

        $this->assertSame(80000.0, $wallets->reserveForPayment($first, $student->id, 80000, $student->id));

        try {
            $wallets->reserveForPayment($second, $student->id, 80000, $student->id);
            $this->fail('Expected stale wallet amount to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('20000.00', $wallet->current_balance);
        $this->assertSame('80000.00', $wallet->reserved_balance);
        $this->assertSame('0.00', $second->fresh()->wallet_reserved_amount);

        $this->assertSame(20000.0, $wallets->reserveForPayment($second, $student->id, null, $student->id));
        $wallet = $wallet->fresh();
        $this->assertSame('0.00', $wallet->current_balance);
        $this->assertSame('100000.00', $wallet->reserved_balance);

        $first->update(['status' => 'rejected']);
        $wallet = $wallet->fresh();
        $this->assertSame('80000.00', $wallet->current_balance);
        $this->assertSame('20000.00', $wallet->reserved_balance);
        $this->assertSame('0.00', $first->fresh()->wallet_reserved_amount);
    }

    public function test_full_wallet_package_checkout_needs_no_transfer_proof(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('123456'),
        ]);
        $this->creditWallet($student, 100000);
        $order = $this->supportedOrder($student, 80000, 'INV-WALLET-FULL-ST2');

        Sanctum::actingAs($student);
        $this->postJson("/api/orders/{$order->id}/pay", [
            'use_wallet' => true,
            'wallet_expected_amount' => 80000,
            'payment_pin' => '123456',
        ], ['Idempotency-Key' => 'wallet-full-checkout-st2'])
            ->assertOk()
            ->assertJsonPath('external_due', 0);

        $order->refresh();
        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('80000.00', $order->wallet_applied_amount);
        $this->assertSame('0.00', $order->wallet_reserved_amount);
        $this->assertSame('20000.00', $wallet->current_balance);
        $this->assertSame('0.00', $wallet->reserved_balance);
        $this->assertContains($order->status, ['paid', 'refund_pending']);
    }

    public function test_full_wallet_package_checkout_rolls_back_hold_if_auto_settlement_fails(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('123456'),
        ]);
        $this->creditWallet($student, 100000);
        $order = $this->supportedOrder($student, 80000, 'INV-WALLET-ATOMIC-ST2');

        // Paksa ledger gagal sesudah status submitted/reserve dibuat. Full-wallet
        // checkout harus berada pada transaksi luar yang sama sehingga reserve
        // dan mutasi wallet ikut rollback, bukan meninggalkan saldo tertahan.
        Setting::query()->where('key', 'finance_ledger_chain_lock')->delete();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/orders/{$order->id}/pay", [
            'use_wallet' => true,
            'wallet_expected_amount' => 80000,
            'payment_pin' => '123456',
        ], ['Idempotency-Key' => 'wallet-atomic-checkout-st2']);
        $this->assertGreaterThanOrEqual(400, $response->status());

        $order->refresh();
        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertSame('0.00', $order->wallet_reserved_amount);
        $this->assertSame('0.00', $order->wallet_applied_amount);
        $this->assertSame('100000.00', $wallet->current_balance);
        $this->assertSame('0.00', $wallet->reserved_balance);
        $this->assertDatabaseMissing('customer_wallet_transactions', [
            'order_id' => $order->id,
            'type' => 'payment_reserve',
        ]);
    }

    public function test_captured_wallet_payment_debits_liability_and_only_external_part_hits_cash(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->creditWallet($student, 100000);
        $wallets = app(CustomerWalletService::class);
        $order = $this->supportedOrder($student, 150000, 'INV-WALLET-SPLIT-ST2');

        $this->assertSame(100000.0, $wallets->reserveForPayment($order, $student->id, 100000, $student->id));
        $order->update(['status' => 'paid']);
        $order->refresh();

        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('0.00', $wallet->current_balance);
        $this->assertSame('0.00', $wallet->reserved_balance);
        $this->assertSame('0.00', $order->wallet_reserved_amount);
        $this->assertSame('100000.00', $order->wallet_applied_amount);

        $event = "order:{$order->id}:payment_received";
        $this->assertDatabaseHas('financial_journals', ['event_key' => $event]);
        $journalId = (int) FinancialJournal::query()->where('event_key', $event)->value('id');
        $this->assertDatabaseHas('financial_ledger_entries', [
            'financial_journal_id' => $journalId,
            'account_code' => 'customer_wallet_liability',
            'side' => 'debit',
            'amount' => 100000,
        ]);
        $this->assertDatabaseHas('financial_ledger_entries', [
            'financial_journal_id' => $journalId,
            'account_code' => 'platform_cash',
            'side' => 'debit',
            'amount' => 50000,
        ]);
        $this->assertDatabaseMissing('financial_ledger_entries', [
            'financial_journal_id' => $journalId,
            'account_code' => 'platform_cash',
            'amount' => 150000,
        ]);

        $this->assertSame([], app(WalletIntegrityService::class)->errors());
    }

    public function test_wallet_integrity_detects_direct_balance_tampering(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->creditWallet($student, 60000);
        $this->assertSame([], app(WalletIntegrityService::class)->errors());

        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $wallet->forceFill(['current_balance' => 999999])->save();

        $errors = app(WalletIntegrityService::class)->errors();
        $this->assertNotEmpty($errors);
        $this->assertTrue(collect($errors)->contains(
            fn (string $error) => str_contains($error, 'tidak sama')
        ));
    }

    public function test_tampered_balance_cannot_be_spent(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->creditWallet($student, 60000);
        $wallets = app(CustomerWalletService::class);
        $order = $this->supportedOrder($student, 50000, 'INV-WALLET-TAMPER-SPEND-ST2');

        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $wallet->forceFill(['current_balance' => 999999])->save();

        try {
            $wallets->reserveForPayment($order, $student->id, null, $student->id);
            $this->fail('Expected a tampered wallet to be locked before spending.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringContainsString('pemeriksaan integritas', $exception->getMessage());
        }

        $this->assertSame('0.00', $order->fresh()->wallet_reserved_amount);
        $this->assertDatabaseMissing('customer_wallet_transactions', [
            'order_id' => $order->id,
            'type' => 'payment_reserve',
        ]);
    }

    public function test_tampered_reserved_balance_cannot_be_released(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->creditWallet($student, 60000);
        $wallets = app(CustomerWalletService::class);
        $order = $this->supportedOrder($student, 40000, 'INV-WALLET-TAMPER-HOLD-ST2');
        $this->assertSame(40000.0, $wallets->reserveForPayment($order, $student->id, 40000, $student->id));

        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $wallet->forceFill(['reserved_balance' => 0])->save();

        try {
            $wallets->releaseReserved($order, $student->id, 'tamper_test');
            $this->fail('Expected a mismatched wallet hold to be locked before release.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }

        $this->assertSame('40000.00', $order->fresh()->wallet_reserved_amount);
        $this->assertDatabaseMissing('customer_wallet_transactions', [
            'order_id' => $order->id,
            'type' => 'payment_release',
        ]);
    }

    public function test_fully_wallet_funded_refund_cannot_be_selected_as_bank_withdrawal(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->creditWallet($student, 100000);
        $wallets = app(CustomerWalletService::class);
        $order = $this->supportedOrder($student, 80000, 'INV-WALLET-REFUND-NOCASH-ST2');

        $this->assertSame(80000.0, $wallets->reserveForPayment($order, $student->id, 80000, $student->id));
        $order->update(['status' => 'paid']);
        $order->refresh();
        $this->assertSame('80000.00', $order->wallet_applied_amount);

        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 80000,
            'reason' => 'Refund pembayaran penuh saldo.',
            'status' => 'pending',
        ]);
        $order->update(['status' => 'refund_pending']);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bank_transfer',
            'bank_name' => 'BCA',
            'account_name' => 'Murid Tidak Bisa Cash Out',
            'account_number' => '1234567890',
        ], ['Idempotency-Key' => 'wallet-no-cashout-bank-st2'])
            ->assertUnprocessable();

        $this->assertNull($refund->fresh()->destination_method);

        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => 'wallet-no-cashout-balance-st2'])
            ->assertOk()
            ->assertJsonPath('refund.wallet_funded_amount', 80000)
            ->assertJsonPath('refund.external_funded_amount', 0);
    }

    public function test_partial_wallet_refund_to_bank_returns_wallet_part_to_balance_and_only_external_part_to_cash(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->creditWallet($student, 100000);
        $wallets = app(CustomerWalletService::class);
        $order = $this->supportedOrder($student, 150000, 'INV-WALLET-REFUND-SPLIT-ST2');

        $this->assertSame(100000.0, $wallets->reserveForPayment($order, $student->id, 100000, $student->id));
        $order->update(['status' => 'paid']);
        $order->refresh();
        $this->assertSame('100000.00', $order->wallet_applied_amount);
        $this->assertSame('0.00', CustomerWallet::query()->where('user_id', $student->id)->value('current_balance'));

        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 150000,
            'reason' => 'Refund pembayaran campuran.',
            'status' => 'pending',
        ]);
        $order->update(['status' => 'refund_pending']);

        Sanctum::actingAs($student);
        $destination = $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bank_transfer',
            'bank_name' => 'BCA',
            'account_name' => 'Murid Refund Campuran',
            'account_number' => '1234567890',
        ], ['Idempotency-Key' => 'wallet-split-bank-destination-st2'])
            ->assertOk()
            ->assertJsonPath('refund.wallet_funded_amount', 100000)
            ->assertJsonPath('refund.external_funded_amount', 50000);

        $version = (int) $destination->json('refund.destination_selection_version');
        Sanctum::actingAs($admin);
        $this->post("/api/admin/refunds/{$refund->id}/complete", [
            'destination_selection_version' => $version,
            'proof' => UploadedFile::fake()->image('refund-split.jpg', 800, 800),
            'notes' => 'Transfer eksternal Rp50.000 sudah dilakukan.',
        ], [
            'Idempotency-Key' => 'wallet-split-admin-complete-st2',
        ])->assertOk()
            ->assertJsonPath('wallet_funded_amount', 100000)
            ->assertJsonPath('external_funded_amount', 50000);

        $refund->refresh();
        $order->refresh();
        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('paid', $refund->status);
        $this->assertSame('refunded', $order->status);
        $this->assertSame('100000.00', $wallet->current_balance);
        $this->assertSame('0.00', $wallet->reserved_balance);
        $this->assertDatabaseHas('customer_wallet_transactions', [
            'refund_id' => $refund->id,
            'type' => 'refund_credit',
            'amount' => 100000,
        ]);
        $this->assertDatabaseMissing('customer_wallet_transactions', [
            'refund_id' => $refund->id,
            'amount' => 150000,
        ]);

        $journal = FinancialJournal::query()
            ->where('event_key', "refund:{$refund->id}:paid")
            ->firstOrFail();
        $this->assertDatabaseHas('financial_ledger_entries', [
            'financial_journal_id' => $journal->id,
            'account_code' => 'refunds_payable',
            'side' => 'debit',
            'amount' => 150000,
        ]);
        $this->assertDatabaseHas('financial_ledger_entries', [
            'financial_journal_id' => $journal->id,
            'account_code' => 'customer_wallet_liability',
            'side' => 'credit',
            'amount' => 100000,
        ]);
        $this->assertDatabaseHas('financial_ledger_entries', [
            'financial_journal_id' => $journal->id,
            'account_code' => 'platform_cash',
            'side' => 'credit',
            'amount' => 50000,
        ]);
        $this->assertDatabaseMissing('financial_ledger_entries', [
            'financial_journal_id' => $journal->id,
            'account_code' => 'platform_cash',
            'amount' => 150000,
        ]);
        $this->assertSame([], app(WalletIntegrityService::class)->errors());
    }

    public function test_legacy_booking_order_is_not_supported_by_wallet_quote(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->creditWallet($student, 50000);
        $legacy = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-LEGACY-WALLET-ST2',
            'amount' => 50000,
            'status' => 'pending',
        ]);

        $quote = app(CustomerWalletService::class)->quoteForOrder($legacy, $student->id);
        $this->assertFalse($quote['supported']);
        $this->assertSame(0.0, $quote['usable_amount']);
        $this->assertSame(50000.0, $quote['external_due']);
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
            'name' => 'Paket Wallet '.$invoice,
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
