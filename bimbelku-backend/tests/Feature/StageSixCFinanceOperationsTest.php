<?php

namespace Tests\Feature;

use App\Models\FinancialJournal;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageSixCFinanceOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_monitoring_separates_pending_and_history(): void
    {
        $admin = $this->authorizedAdmin();
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-PENDING-6C',
            'amount' => 125000,
            'status' => 'submitted',
            'payment_proof' => 'payment_proofs/pending.jpg',
            'sender_name' => 'Murid Uji',
            'bank_name' => 'Bank Uji',
            'sender_account_number' => '1234567890',
            'payment_submitted_at' => now(),
        ]);
        Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-PAID-6C',
            'amount' => 90000,
            'status' => 'paid',
            'payment_proof' => 'payment_proofs/paid.jpg',
            'verified_at' => now(),
            'verified_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/finance/payments')
            ->assertOk()
            ->assertJsonPath('summary.pending_count', 1)
            ->assertJsonPath('pending.0.order_id', 'INV-PENDING-6C')
            ->assertJsonPath('history.0.order_id', 'INV-PAID-6C');
    }

    public function test_refund_can_be_credited_to_bimbelku_balance_once(): void
    {
        $admin = $this->authorizedAdmin();
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-WALLET-6C',
            'amount' => 175000,
            'status' => 'refund_pending',
        ]);
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 175000,
            'reason' => 'Tutor tidak hadir dan kasus telah disetujui admin.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => 'refund-wallet-choice-6c-0001'])->assertOk();
        $destinationVersion = (int) $refund->fresh()->destination_selection_version;

        Sanctum::actingAs($admin);
        $headers = ['Idempotency-Key' => 'refund-wallet-6c-0001'];

        $this->postJson("/api/admin/refunds/{$refund->id}/complete", [
            'notes' => 'Dikreditkan ke saldo sesuai pilihan penyelesaian.',
            'destination_selection_version' => $destinationVersion,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('wallet_balance', '175000.00');

        $this->postJson("/api/admin/refunds/{$refund->id}/complete", [
            'notes' => 'Dikreditkan ke saldo sesuai pilihan penyelesaian.',
            'destination_selection_version' => $destinationVersion,
        ], $headers)->assertOk();

        $this->assertDatabaseCount('customer_wallet_transactions', 1);
        $this->assertDatabaseHas('customer_wallets', [
            'user_id' => $student->id,
            'current_balance' => 175000,
        ]);
        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id,
            'status' => 'paid',
            'destination_method' => 'bimbelku_balance',
        ]);
        $this->assertDatabaseHas('financial_journals', [
            'event_key' => "refund:{$refund->id}:wallet_credited",
        ]);

        Sanctum::actingAs($student);
        $this->getJson('/api/student/wallet')
            ->assertOk()
            ->assertJsonPath('balance', 175000)
            ->assertJsonCount(1, 'transactions');
    }

    public function test_paid_refund_cannot_be_processed_again_with_a_different_key(): void
    {
        $admin = $this->authorizedAdmin();
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-NO-DUPLICATE-6C',
            'amount' => 65000,
            'status' => 'refund_pending',
        ]);
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 65000,
            'reason' => 'Refund uji idempotensi ke saldo.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => 'refund-wallet-choice-no-duplicate-6c'])->assertOk();
        $destinationVersion = (int) $refund->fresh()->destination_selection_version;

        Sanctum::actingAs($admin);
        $payload = ['destination_selection_version' => $destinationVersion];

        $this->postJson("/api/admin/refunds/{$refund->id}/complete", $payload, [
            'Idempotency-Key' => 'refund-wallet-first-6c',
        ])->assertOk();

        $this->postJson("/api/admin/refunds/{$refund->id}/complete", $payload, [
            'Idempotency-Key' => 'refund-wallet-second-6c',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('customer_wallet_transactions', 1);
        $this->assertDatabaseHas('customer_wallets', [
            'user_id' => $student->id,
            'current_balance' => 65000,
        ]);
    }

    public function test_bank_refund_requires_proof_and_keeps_destination_snapshot(): void
    {
        Storage::fake('local');
        $admin = $this->authorizedAdmin();
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-BANK-6C',
            'amount' => 80000,
            'status' => 'refund_pending',
            'bank_name' => 'BCA',
            'sender_name' => 'Murid Refund',
            'sender_account_number' => '9988776655',
        ]);
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 80000,
            'reason' => 'Pencarian tutor berakhir tanpa hasil.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bank_transfer',
            'bank_name' => 'BCA',
            'account_name' => 'Murid Refund',
            'account_number' => '9988776655',
        ], ['Idempotency-Key' => 'refund-bank-choice-6c'])->assertOk();
        $destinationVersion = (int) $refund->fresh()->destination_selection_version;

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/refunds/{$refund->id}/complete", [
            'destination_selection_version' => $destinationVersion,
        ], ['Idempotency-Key' => 'refund-bank-no-proof-6c'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proof');

        $this->post("/api/admin/refunds/{$refund->id}/complete", [
            'proof' => UploadedFile::fake()->image('refund.jpg'),
            'destination_selection_version' => $destinationVersion,
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'refund-bank-proof-6c',
        ])->assertOk();

        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id,
            'destination_method' => 'bank_transfer',
            'destination_bank_name' => 'BCA',
            'destination_account_name' => 'Murid Refund',
            'destination_account_number' => '9988776655',
            'status' => 'paid',
        ]);
        $this->assertTrue(FinancialJournal::query()
            ->where('event_key', "refund:{$refund->id}:paid")
            ->exists());
    }

    private function authorizedAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        return $admin;
    }
}
