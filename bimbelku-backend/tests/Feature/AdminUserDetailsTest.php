<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OneTimeCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bimbelku.primary_admin_email', '');
    }

    public function test_admin_can_update_user_and_email_change_requires_reverification(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'admin_type' => 'single_admin']);
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'email' => 'murid@gmail.com',
            'email_verified_at' => now(),
        ]);
        $student->createToken('old-session');
        $originalName = $student->name;
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$student->id}/details", [
            'name' => 'Murid Baru',
            'email' => 'murid.baru@outlook.com',
            'phone' => '+62 812-3456-7890',
            'guardian_name' => 'Wali Murid',
            'guardian_phone' => '081234567890',
            'guardian_relationship' => 'wali_keluarga',
        ])->assertOk()
            ->assertJsonPath('data.email_verification_required', true)
            ->assertJsonPath('data.email', 'murid.baru@outlook.com');

        $student->refresh();
        $this->assertSame('Murid Baru', $student->name);
        $this->assertSame('6281234567890', $student->phone);
        $this->assertNull($student->email_verified_at);
        $this->assertNotNull($student->email_verification_required_at);
        $this->assertSame(0, $student->tokens()->count());
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'target_type' => 'User',
            'target_id' => $student->id,
            'response_status' => 200,
        ]);
    }

    public function test_admin_user_update_rejects_duplicate_or_disallowed_email_without_mutation(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'admin_type' => 'single_admin']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active', 'email' => 'murid@gmail.com']);
        $originalName = $student->name;
        User::factory()->create(['email' => 'existing@yahoo.com']);
        Sanctum::actingAs($admin);

        $payload = [
            'name' => 'Murid Diperbarui',
            'phone' => '081234567890',
        ];
        $this->patchJson("/api/admin/users/{$student->id}/details", $payload + ['email' => 'existing@yahoo.com'])->assertUnprocessable();
        $this->patchJson("/api/admin/users/{$student->id}/details", $payload + ['email' => 'person@unknown.test'])->assertUnprocessable();

        $this->assertSame('murid@gmail.com', $student->fresh()->email);
        $this->assertSame($originalName, $student->fresh()->name);
    }

    public function test_email_change_flow_requires_otp_on_the_new_address_before_login(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'admin_type' => 'single_admin']);
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'email' => 'murid@gmail.com',
            'password' => Hash::make('password123'),
            'email_verified_at' => now()->subDay(),
            'google_id' => 'google-murid-123',
        ]);
        $student->createToken('old-session');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$student->id}/details", [
            'name' => $student->name,
            'email' => 'murid.baru@outlook.com',
            'phone' => '081234567890',
        ])->assertOk()
            ->assertJsonPath('data.email_verification_required', true)
            ->assertJsonPath('data.email_verification_otp_sent', true);

        $student->refresh();
        $this->assertNull($student->email_verified_at);
        $this->assertNotNull($student->email_verification_required_at);
        $this->assertSame(0, $student->tokens()->count());
        // Identitas Google tetap terhubung supaya login Google tidak membuat akun duplikat.
        $this->assertSame('google-murid-123', $student->google_id);
        $this->assertDatabaseHas('verification_codes', [
            'user_id' => $student->id,
            'purpose' => OneTimeCodeService::EMAIL_VERIFICATION,
        ]);

        // Password lama tetap berlaku, tetapi email baru harus diverifikasi dulu.
        $blocked = $this->postJson('/api/login', [
            'email' => 'murid.baru@outlook.com',
            'password' => 'password123',
        ])->assertForbidden()
            ->assertJsonPath('error_code', 'email_not_verified')
            ->assertJsonPath('email', 'murid.baru@outlook.com');
        $this->assertIsInt($blocked->json('resend_after_seconds'));

        // Alamat lama sudah tidak dikenali lagi.
        $this->postJson('/api/login', [
            'email' => 'murid@gmail.com',
            'password' => 'password123',
        ])->assertUnauthorized();

        // Setelah cooldown, pengguna dapat meminta kode baru ke email baru.
        $this->travel(61)->seconds();
        $this->postJson('/api/email/verification/resend', ['email' => 'murid.baru@outlook.com'])
            ->assertOk()
            ->assertJsonPath('resend_after_seconds', OneTimeCodeService::RESEND_SECONDS);

        $this->storeEmailCode($student, '123456');
        $this->postJson('/api/email/verification/verify', [
            'email' => 'murid.baru@outlook.com',
            'code' => '123456',
        ])->assertOk();

        $this->assertNotNull($student->fresh()->email_verified_at);
        $this->postJson('/api/login', [
            'email' => 'murid.baru@outlook.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    private function storeEmailCode(User $user, string $code): void
    {
        DB::table('verification_codes')->insert([
            'user_id' => $user->id,
            'purpose' => OneTimeCodeService::EMAIL_VERIFICATION,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'sent_at' => now()->subSeconds(61),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
