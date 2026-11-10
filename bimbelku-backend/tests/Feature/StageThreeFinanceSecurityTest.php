<?php

namespace Tests\Feature;

use App\Models\FinancialJournal;
use App\Models\Order;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\EducationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageThreeFinanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bimbelku.primary_admin_email', '');
    }

    public function test_higher_education_is_removed_from_supported_levels(): void
    {
        $this->assertSame(['SD', 'SMP', 'SMA', 'Umum'], EducationCatalog::LEVELS);
        $this->assertArrayNotHasKey('Perguruan Tinggi', EducationCatalog::GRADES_BY_LEVEL);
    }

    public function test_single_admin_can_use_finance_without_authenticator(): void
    {
        $admin = $this->activeAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/commission-setting', [
            'admin_fee' => 20,
        ], ['Idempotency-Key' => 'single-admin-finance-0001'])
            ->assertOk();

        $this->getJson('/api/admin/finance/payments')->assertOk();
        $this->getJson('/api/admin/finance/refunds')->assertOk();
        $this->getJson('/api/admin/finance')->assertOk();
        $this->getJson('/api/admin/finance-security')->assertNotFound();
    }

    public function test_idempotency_key_replays_one_finance_mutation(): void
    {
        $admin = $this->activeAdmin();
        Sanctum::actingAs($admin);
        $headers = ['Idempotency-Key' => 'commission-change-0001'];

        $first = $this->postJson('/api/admin/commission-setting', ['admin_fee' => 20], $headers)
            ->assertOk();
        $second = $this->postJson('/api/admin/commission-setting', ['admin_fee' => 20], $headers)
            ->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertDatabaseCount('idempotency_records', 1);
        $this->assertDatabaseHas('settings', ['key' => 'admin_fee', 'value' => '20']);
    }

    public function test_paid_order_creates_balanced_immutable_journal(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-LEDGER-001',
            'amount' => 125000,
            'status' => 'submitted',
            'payment_proof' => 'payment_proofs/example.jpg',
        ]);

        $order->update(['status' => 'paid']);

        $journal = FinancialJournal::query()
            ->where('event_key', "order:{$order->id}:payment_received")
            ->with('entries')
            ->firstOrFail();
        $debit = $journal->entries->where('side', 'debit')->sum('amount');
        $credit = $journal->entries->where('side', 'credit')->sum('amount');

        $this->assertSame((float) $debit, (float) $credit);
        $this->assertSame(125000.0, (float) $debit);
        $this->expectException(\LogicException::class);
        $journal->update(['description' => 'Tidak boleh diubah']);
    }

    public function test_teacher_bank_change_requires_password_and_holds_payout(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
            'password' => Hash::make('TeacherPass123!'),
        ]);
        TeacherProfile::create(['user_id' => $teacher->id]);
        Sanctum::actingAs($teacher);

        $this->postJson('/api/teacher/bank', [
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'current_password' => 'TeacherPass123!',
        ], ['Idempotency-Key' => 'teacher-bank-change-0001'])
            ->assertOk()
            ->assertJsonStructure(['payout_hold_until']);

        $profile = $teacher->teacherProfile()->firstOrFail();
        $this->assertTrue($profile->payout_hold_until->isFuture());
        $this->assertSame(1, $profile->bank_details_version);
        $this->assertNotNull($profile->bank_account_fingerprint);
    }

    public function test_teacher_cannot_save_bank_holder_name_different_from_account_name(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher', 'status' => 'active', 'password' => Hash::make('TeacherPass123!'),
        ]);
        TeacherProfile::create(['user_id' => $teacher->id]);
        Sanctum::actingAs($teacher);

        $this->postJson('/api/teacher/bank', [
            'bank_name' => 'BCA', 'account_number' => '1234567890',
            'account_name' => 'Nama Berbeda', 'current_password' => 'TeacherPass123!',
        ], ['Idempotency-Key' => 'bank-name-mismatch-0001'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nama pemilik rekening harus sama dengan nama akun tutor. Pastikan nama akun sesuai KTP sebelum melanjutkan.');
        $this->assertNull($teacher->teacherProfile()->firstOrFail()->account_number);
    }

    public function test_admin_cannot_manually_trigger_teacher_payout(): void
    {
        Sanctum::actingAs($this->activeAdmin());

        $this->postJson('/api/admin/payout', [
            'teacher_id' => 1,
            'booking_ids' => [1],
        ], ['Idempotency-Key' => 'manual-admin-payout-disabled'])
            ->assertNotFound();

        $this->assertDatabaseCount('payouts', 0);
    }

    private function activeAdmin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'admin_type' => 'single_admin',
            'admin_permissions' => null,
        ]);
    }
}
