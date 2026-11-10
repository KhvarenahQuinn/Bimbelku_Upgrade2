<?php

namespace Tests\Feature;

use App\Models\CustomerWallet;
use App\Models\CustomerWalletTransaction;
use App\Models\FinancialJournal;
use App\Models\LearningPackage;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSubject;
use App\Models\Refund;
use App\Models\User;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Simulasi pemesanan murid baru khusus pengujian (tanpa uang sungguhan):
 * checkout lunas memakai Saldo BimbelKu dan checkout memakai Xendit.
 */
class NewStudentCheckoutSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mesin pembayaran otomatis aktif, sama seperti penyebaran aktual.
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_simulation_only');
        config()->set('xendit.webhook_token', 'simulation-callback-token');
        config()->set('xendit.base_url', 'https://api.xendit.test');
    }

    public function test_new_student_can_pay_full_amount_with_bimbelku_balance(): void
    {
        [$student, $order] = $this->newStudentOrder('sim-belajar-saldo@bimbelku.test', 80000, 'INV-SIM-BALANCE-001');
        $this->creditWallet($student, 120000);

        // Klik berulang menggunakan idempotency key yang sama tidak menarik saldo dua kali.
        $headers = ['Idempotency-Key' => 'sim-new-student-wallet-checkout'];
        Sanctum::actingAs($student, ['*']);
        $first = $this->postJson("/api/orders/{$order->id}/pay", [
            'use_wallet' => true,
            'wallet_expected_amount' => 80000,
            'payment_pin' => '987654',
        ], $headers);
        $this->assertSame(200, $first->status(), $first->getContent());
        $this->assertSame(0.0, (float) $first->json('external_due'));

        $this->postJson("/api/orders/{$order->id}/pay", [
            'use_wallet' => true,
            'wallet_expected_amount' => 80000,
            'payment_pin' => '987654',
        ], $headers)->assertOk();

        $wallet = CustomerWallet::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('40000.00', $wallet->current_balance);
        $this->assertSame('0.00', $wallet->reserved_balance);
        $this->assertSame('80000.00', $order->fresh()->wallet_applied_amount);
        $this->assertSame('bimbelku_wallet', $order->fresh()->payment_provider);

        // Mutasi Saldo BimbelKu perlu jejak yang menunjuk ke tagihan dan jurnalnya.
        $capture = CustomerWalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', 'payment_capture')
            ->firstOrFail();
        $this->assertSame("order:{$order->id}:wallet_capture", $capture->event_key);
        $this->assertSame("order:{$order->id}:payment_received", $capture->metadata['journal_event_key']);

        $journal = FinancialJournal::query()
            ->where('event_key', "order:{$order->id}:payment_received")
            ->firstOrFail();
        $this->assertSame(Order::class, $journal->reference_type);
        $this->assertSame($order->id, (int) $journal->reference_id);

        // Pemisahan saldo sebagian tidak lagi tersedia: saldo wajib menutup penuh.
        [$other, $otherOrder] = $this->newStudentOrder('sim-belajar-saldo-2@bimbelku.test', 90000, 'INV-SIM-BALANCE-002');
        $this->creditWallet($other, 50000);
        Sanctum::actingAs($other, ['*']);
        $this->postJson("/api/orders/{$otherOrder->id}/pay", [
            'use_wallet' => true,
            'wallet_expected_amount' => 50000,
            'payment_pin' => '987654',
        ], ['Idempotency-Key' => 'sim-new-student-partial-wallet'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'wallet_full_payment_required');
        $this->assertSame('pending', $otherOrder->fresh()->status);
        $this->assertSame('0.00', CustomerWallet::query()->where('user_id', $other->id)->value('reserved_balance'));

        // Jurnal pada laporan admin memuat pengguna dan kanal pembayaran.
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/admin/finance/report')
            ->assertOk()
            ->assertJsonFragment([
                'user_name' => $student->name,
                'user_email' => $student->email,
                'payment_channel' => 'Saldo BimbelKu',
                'reference_label' => 'Pesanan INV-SIM-BALANCE-001',
            ]);
    }

    public function test_new_student_can_open_xendit_checkout_and_get_matched_payment_request(): void
    {
        [$student, $order, $package] = $this->newStudentOrder('sim-belajar-xendit@bimbelku.test', 150000, 'INV-SIM-XENDIT-001');

        Http::fake(['https://api.xendit.test/sessions' => function (GatewayRequest $request) {
            $sessionId = 'ps-sim-order-'.$request['metadata']['order_id'];

            return Http::response([
                'payment_session_id' => $sessionId,
                'payment_link_url' => "https://checkout-staging.xendit.test/{$sessionId}",
                'status' => 'ACTIVE',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]);
        }]);

        $headers = ['Idempotency-Key' => 'sim-new-student-xendit-checkout'];
        Sanctum::actingAs($student, ['*']);
        $session = $this->postJson("/api/orders/{$order->id}/xendit-session", [], $headers);
        $this->assertSame(200, $session->status(), $session->getContent());
        $sessionId = $session->json('data.session_id');
        $this->assertNotEmpty($session->json('data.checkout_url'));

        // Kode transaksi lokal (INV-/PKG-) dikirim sebagai reference_id ke Xendit
        // sehingga dapat dicari pada filter 'Referensi' dashboard Xendit.
        $order->refresh();
        $this->assertSame('INV-SIM-XENDIT-001', $order->gateway_reference_id);
        Http::assertSent(fn (GatewayRequest $request) => $request['reference_id'] === 'INV-SIM-XENDIT-001');

        // Membuka ulang checkout memakai sesi tagihan yang sama, bukan tagihan baru.
        $this->postJson("/api/orders/{$order->id}/xendit-session", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.session_id', $sessionId);
        Http::assertSentCount(1);

        $this->postJson('/api/webhooks/xendit', [
            'id' => 'evt-sim-xendit-001',
            'event' => 'payment_session.completed',
            'data' => [
                'payment_session_id' => $sessionId,
                'reference_id' => $order->fresh()->gateway_reference_id,
                'status' => 'COMPLETED',
                'amount' => (int) $order->amount,
                'payment_id' => 'py-sim-001',
                'payment_request_id' => 'pr-sim-001',
            ],
        ], ['x-callback-token' => 'simulation-callback-token'])->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('pr-sim-001', $order->fresh()->gateway_payment_request_id);
        // Payment Request ID juga tersimpan pada kolom khusus untuk laporan admin.
        $this->assertSame('pr-sim-001', $order->fresh()->xendit_payment_request_id);
        // Pembayaran Xendit tidak boleh dan tidak perlu menarik Saldo BimbelKu.
        $this->assertDatabaseMissing('customer_wallet_transactions', ['order_id' => $order->id]);
        $package->refresh();
        $this->assertNotSame('awaiting_payment', $package->status);

        // Admin dapat mencocokkan jurnal langsung dengan dashboard Xendit.
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin, ['*']);
        $report = $this->getJson('/api/admin/finance/report')->assertOk();
        $row = collect($report->json('data'))->firstWhere('event_type', 'payment_received');
        $this->assertNotNull($row, 'Jurnal pembayaran tidak ditemukan pada laporan.');
        $this->assertSame($student->email, $row['user_email']);
        $this->assertSame('Xendit', $row['payment_channel']);
        $this->assertTrue(collect($row['external_references'])->contains(
            fn (array $reference) => $reference['value'] === 'pr-sim-001'
        ), 'Payment Request ID Xendit tidak tampil pada baris jurnal.');
    }

    public function test_repeated_xendit_checkout_clicks_never_create_two_sessions(): void
    {
        [$student, $order] = $this->newStudentOrder('sim-belajar-debounce@bimbelku.test', 120000, 'INV-SIM-DEBOUNCE-001');

        Http::fake(['https://api.xendit.test/sessions' => function (GatewayRequest $request) {
            $sessionId = 'ps-sim-order-'.$request['metadata']['order_id'];

            return Http::response([
                'payment_session_id' => $sessionId,
                'payment_link_url' => "https://checkout-staging.xendit.test/{$sessionId}",
                'status' => 'ACTIVE',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]);
        }]);

        Sanctum::actingAs($student, ['*']);
        // Klien mengirim idempotency key yang sama untuk klik berulang cepat.
        $headers = ['Idempotency-Key' => 'sim-repeat-click-checkout'];
        $this->postJson("/api/orders/{$order->id}/xendit-session", [], $headers)->assertOk();
        $this->postJson("/api/orders/{$order->id}/xendit-session", [], $headers)->assertOk();
        $this->postJson("/api/orders/{$order->id}/xendit-session", [], $headers)->assertOk();
        Http::assertSentCount(1);
        $this->assertSame('ps-sim-order-'.$order->id, $order->fresh()->gateway_session_id);
    }

    /**
     * Murid baru beserta tagihan paket belajarnya, tanpa pembayaran apa pun.
     * PIN pembayaran sudah terpasang agar alur checkout Saldo BimbelKu langsung
     * dapat dijalankan (fokus simulasi adalah pembayaran, bukan registrasi PIN).
     *
     * @return array{0:User,1:Order,2:LearningPackage}
     */
    private function newStudentOrder(string $email, float $amount, string $invoice): array
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'email' => $email,
            'password' => Hash::make('password123'),
            'payment_pin_hash' => Hash::make('987654'),
        ]);
        $plan = PackagePlan::create([
            'name' => 'Paket Simulasi '.$invoice,
            'slug' => strtolower(str_replace('_', '-', $invoice)),
            'session_count' => 1,
            'validity_days' => 30,
            'maximum_subjects' => 1,
            'is_active' => true,
        ]);
        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-SIM-'.substr($invoice, -6),
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
            'subject_name' => 'Matematika simulasi',
            'allocated_sessions' => 1,
            'unit_price' => $amount,
            'subtotal_amount' => $amount,
            'status' => 'awaiting_payment',
        ]);
        $order = Order::create([
            'user_id' => $student->id,
            'learning_package_id' => $package->id,
            'order_id' => $invoice,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        return [$student, $order, $package];
    }

    /** Saldo awal murid baru diisi melalui lifecycle refund yang tercatat di ledger. */
    private function creditWallet(User $student, float $amount): void
    {
        $sourceOrder = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-SIM-SEED-'.$student->id.'-'.((int) $amount),
            'amount' => $amount,
            'status' => 'refund_pending',
        ]);
        $refund = Refund::create([
            'order_id' => $sourceOrder->id,
            'user_id' => $student->id,
            'amount' => $amount,
            'reason' => 'Seed saldo murid baru untuk simulasi checkout.',
            'status' => 'pending',
            'destination_method' => 'bimbelku_balance',
            'destination_selected_at' => now(),
        ]);

        app(CustomerWalletService::class)->creditRefund($refund, $student->id);
        $refund->update(['status' => 'paid', 'processed_at' => now()]);
        $sourceOrder->update(['status' => 'refunded']);
    }
}
