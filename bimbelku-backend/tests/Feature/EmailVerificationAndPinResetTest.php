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

class EmailVerificationAndPinResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registration_requires_email_verification_before_login(): void
    {
        Mail::fake();
        $payload = [
            'name' => 'Murid Verifikasi',
            'email' => 'verifikasi@example.com',
            'phone' => '081234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'date_of_birth' => now()->subYears(20)->toDateString(),
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ];
        $this->postJson('/api/register', $payload)
            ->assertCreated()
            ->assertJsonPath('requires_email_verification', true);
        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        $this->assertNotNull($user->email_verification_required_at);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('verification_codes', [
            'user_id' => $user->id,
            'purpose' => OneTimeCodeService::EMAIL_VERIFICATION,
        ]);
        $this->postJson('/api/login', [
            'email' => $payload['email'],
            'password' => $payload['password'],
        ])->assertForbidden()->assertJsonPath('error_code', 'email_not_verified');
    }

    public function test_valid_email_code_verifies_account_and_allows_login(): void
    {
        $user = User::factory()->create([
            'email' => 'kode@example.com',
            'email_verified_at' => null,
            'email_verification_required_at' => now(),
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('password123'),
        ]);
        $this->storeCode($user, OneTimeCodeService::EMAIL_VERIFICATION, '123456');
        $this->postJson('/api/email/verification/verify', [
            'email' => $user->email,
            'code' => '123456',
        ])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_legacy_account_without_requirement_can_still_login(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'email_verification_required_at' => null,
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('password123'),
        ]);
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_wrong_email_code_is_counted(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'email_verification_required_at' => now(),
        ]);
        $this->storeCode($user, OneTimeCodeService::EMAIL_VERIFICATION, '123456');
        $this->postJson('/api/email/verification/verify', [
            'email' => $user->email,
            'code' => '000000',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('verification_codes', [
            'user_id' => $user->id,
            'purpose' => OneTimeCodeService::EMAIL_VERIFICATION,
            'attempts' => 1,
        ]);
    }

    public function test_student_can_reset_payment_pin_with_email_code(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('111111'),
        ]);
        Sanctum::actingAs($user);
        $this->storeCode($user, OneTimeCodeService::PAYMENT_PIN_RESET, '654321');
        $this->postJson('/api/student/payment-pin/reset', [
            'code' => '654321',
            'pin' => '222222',
            'pin_confirmation' => '222222',
        ])->assertOk();
        $this->assertTrue(Hash::check('222222', $user->fresh()->payment_pin_hash));
    }

    public function test_failed_email_delivery_does_not_store_code_or_start_cooldown(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'email_verification_required_at' => now(),
        ]);

        Mail::shouldReceive('send')
            ->once()
            ->ordered()
            ->andThrow(new \RuntimeException('SMTP unavailable'));
        Mail::shouldReceive('send')
            ->once()
            ->ordered()
            ->andReturn(null);

        try {
            app(OneTimeCodeService::class)->send($user, OneTimeCodeService::EMAIL_VERIFICATION);
            $this->fail('Pengiriman pertama seharusnya gagal.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }

        $this->assertDatabaseMissing('verification_codes', [
            'user_id' => $user->id,
            'purpose' => OneTimeCodeService::EMAIL_VERIFICATION,
        ]);

        // Tidak perlu menunggu 60 detik: kegagalan tadi tidak boleh dihitung
        // sebagai pengiriman yang berhasil.
        app(OneTimeCodeService::class)->send($user, OneTimeCodeService::EMAIL_VERIFICATION);

        $this->assertDatabaseHas('verification_codes', [
            'user_id' => $user->id,
            'purpose' => OneTimeCodeService::EMAIL_VERIFICATION,
            'attempts' => 0,
        ]);
    }

    public function test_resend_otp_invalidates_previous_code(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'resend@example.com',
            'email_verified_at' => null,
            'email_verification_required_at' => now(),
            'role' => 'student',
            'status' => 'active',
        ]);

        // Store initial OTP code
        $this->storeCode($user, OneTimeCodeService::EMAIL_VERIFICATION, '111111');
        $firstCodeRecord = DB::table('verification_codes')
            ->where('user_id', $user->id)
            ->where('purpose', OneTimeCodeService::EMAIL_VERIFICATION)
            ->whereNull('used_at')
            ->latest('id')
            ->first();
        $this->assertNotNull($firstCodeRecord);
        $firstCodeId = $firstCodeRecord->id;

        // Call resend endpoint
        $this->postJson('/api/email/verification/resend', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'Jika email terdaftar dan belum diverifikasi, kode OTP telah dikirim.');

        // Verify old code is invalidated (marked as used)
        $this->assertDatabaseHas('verification_codes', [
            'id' => $firstCodeId,
            'used_at' => now()->toDateTimeString(),
        ]);

        // Verify new code was created
        $newCodeRecord = DB::table('verification_codes')
            ->where('user_id', $user->id)
            ->where('purpose', OneTimeCodeService::EMAIL_VERIFICATION)
            ->whereNull('used_at')
            ->latest('id')
            ->first();
        $this->assertNotNull($newCodeRecord);
        $this->assertNotEquals($firstCodeId, $newCodeRecord->id);
        $this->assertEquals(0, $newCodeRecord->attempts);
    }

    private function storeCode(User $user, string $purpose, string $code): void
    {
        DB::table('verification_codes')->insert([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
