<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Services\OneTimeCodeService;
use App\Services\TeacherPayoutService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class PayoutOtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.payout_bank_name_stub', true);
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_test');
        config()->set('xendit.webhook_token', 'payout-otp-token');
        config()->set('xendit.base_url', 'https://api.xendit.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_saving_a_new_account_without_an_otp_is_rejected_and_leaves_the_account_untouched(): void
    {
        $fixture = $this->earningTeacher();
        Sanctum::actingAs($fixture['teacher']);

        $this->postJson('/api/teacher/payout-requests', [
            'amount' => 20000,
            'bank_details' => $this->bankDetails(),
        ], ['Idempotency-Key' => 'payout-otp-missing'])
            ->assertStatus(422);

        $profile = TeacherProfile::query()->where('user_id', $fixture['teacher']->id)->firstOrFail();
        $this->assertNull($profile->account_number);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_valid_otp_stores_the_account_and_creates_the_payout_in_one_transaction(): void
    {
        Http::fake(['https://api.xendit.test/*' => Http::response(['id' => 'payout-1', 'status' => 'PROCESSING'], 202)]);
        $fixture = $this->earningTeacher();
        Sanctum::actingAs($fixture['teacher']);
        $code = $this->issueOtp($fixture['teacher']);

        $this->postJson('/api/teacher/payout-requests', [
            'amount' => 20000,
            'bank_details' => $this->bankDetails(),
            'verification' => ['otp' => $code],
        ], ['Idempotency-Key' => 'payout-otp-valid'])
            ->assertAccepted();

        $profile = TeacherProfile::query()->where('user_id', $fixture['teacher']->id)->firstOrFail();
        $this->assertSame('1234567890', $profile->account_number);
        $this->assertSame('BCA', $profile->payout_channel_code);
        $this->assertDatabaseCount('payouts', 1);
        $this->assertNotNull($profile->payout_hold_until);
    }

    public function test_gateway_failure_rolls_back_the_new_account(): void
    {
        Http::fake(['https://api.xendit.test/*' => Http::response(['message' => 'boom'], 500)]);
        $fixture = $this->earningTeacher();
        $code = $this->issueOtp($fixture['teacher']);

        try {
            app(TeacherPayoutService::class)->request(
                $fixture['teacher'],
                20000,
                $this->bankDetails(),
                ['otp' => $code],
            );
            $this->fail('Gateway failure must surface.');
        } catch (\Throwable $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $profile = TeacherProfile::query()->where('user_id', $fixture['teacher']->id)->firstOrFail();
        $this->assertNull($profile->account_number);
    }

    public function test_switching_method_requires_the_current_password(): void
    {
        $fixture = $this->earningTeacher();
        Sanctum::actingAs($fixture['teacher']);

        $this->putJson('/api/teacher/payout-verification/method', [
            'method' => 'password',
            'current_password' => 'wrong-password',
        ], ['Idempotency-Key' => 'payout-method-password-wrong'])
            ->assertStatus(422);

        $this->putJson('/api/teacher/payout-verification/method', [
            'method' => 'password',
            'current_password' => 'password',
        ], ['Idempotency-Key' => 'payout-method-password-ok'])
            ->assertOk();

        $this->assertSame(
            'password',
            TeacherProfile::query()->where('user_id', $fixture['teacher']->id)->value('payout_verification_method')
        );
    }

    public function test_returning_to_otp_requires_a_valid_code(): void
    {
        $fixture = $this->earningTeacher();
        TeacherProfile::query()->where('user_id', $fixture['teacher']->id)
            ->update(['payout_verification_method' => 'password']);
        Sanctum::actingAs($fixture['teacher']);

        $this->putJson('/api/teacher/payout-verification/method', [
            'method' => 'otp',
            'current_password' => 'password',
        ], ['Idempotency-Key' => 'payout-method-otp-no-code'])
            ->assertStatus(422);

        $this->assertSame(
            'password',
            TeacherProfile::query()->where('user_id', $fixture['teacher']->id)->value('payout_verification_method')
        );
    }

    public function test_overpaid_balance_blocks_a_payout_request(): void
    {
        $fixture = $this->earningTeacher();
        $booking = $fixture['booking'];
        $booking->forceFill(['teacher_paid_amount' => 60000])->save();

        $this->assertSame(-20000.0, app(TeacherPayoutService::class)->availableAmount($fixture['teacher']->id));
        $this->assertSame(20000.0, app(TeacherPayoutService::class)->overpaidAmount($fixture['teacher']->id));

        $this->expectException(RuntimeException::class);
        app(TeacherPayoutService::class)->request($fixture['teacher'], 20000);
    }

    private function earningTeacher(): array
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        $booking->update(['status' => 'completed', 'gross_amount' => 50000, 'teacher_net_amount' => 40000]);
        $booking->forceFill(['payout_status' => 'ready'])->save();

        return ['teacher' => $fixture['teacher'], 'booking' => $booking->fresh(), 'order' => $fixture['order']];
    }

    private function bankDetails(): array
    {
        return [
            'payout_channel_code' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => 'Tutor Sesi',
        ];
    }

    public function test_bank_holder_name_tolerates_punctuation_differences(): void
    {
        Http::fake(['https://api.xendit.test/*' => Http::response(['id' => 'payout-punct', 'status' => 'PROCESSING'], 202)]);
        $fixture = $this->earningTeacher();
        $fixture['teacher']->forceFill(['name' => 'Rina Kartika S.Pd.'])->save();
        Sanctum::actingAs($fixture['teacher']);

        $code = $this->issueOtp($fixture['teacher']);
        $details = $this->bankDetails();
        // A comma difference must not block the save: this is the exact drift
        // that blocked the demo tutor's payout.
        $details['account_name'] = 'Rina Kartika, S.Pd.';

        $this->postJson('/api/teacher/payout-requests', [
            'amount' => 20000,
            'bank_details' => $details,
            'verification' => ['otp' => $code],
        ], ['Idempotency-Key' => 'payout-punctuation'])->assertAccepted();
    }

    public function test_stored_bank_account_still_requires_a_valid_otp(): void
    {
        Http::fake();
        $fixture = $this->earningTeacher();
        $this->storeBank($fixture['teacher']);
        Sanctum::actingAs($fixture['teacher']);
        $this->issueOtp($fixture['teacher']);

        $this->postJson('/api/teacher/payout-requests', [
            'amount' => 20000,
            'verification' => ['otp' => '000000'],
        ], ['Idempotency-Key' => 'payout-stored-wrong-otp'])
            ->assertStatus(422);

        $this->assertDatabaseCount('payouts', 0);
        $this->assertSame(0.0, (float) $fixture['booking']->fresh()->teacher_reserved_amount);
        // Percobaan yang gagal harus tetap tercatat agar tidak bisa ditebak
        // tanpa batas.
        $this->assertSame(1, (int) \DB::table('verification_codes')->value('attempts'));
        Http::assertNothingSent();
    }

    public function test_stored_bank_account_pays_with_a_valid_otp(): void
    {
        Http::fake(['https://api.xendit.test/*' => Http::response(['id' => 'payout-stored-1', 'status' => 'PROCESSING'], 202)]);
        $fixture = $this->earningTeacher();
        $this->storeBank($fixture['teacher']);
        Sanctum::actingAs($fixture['teacher']);
        $code = $this->issueOtp($fixture['teacher']);

        $this->postJson('/api/teacher/payout-requests', [
            'amount' => 20000,
            'verification' => ['otp' => $code],
        ], ['Idempotency-Key' => 'payout-stored-valid'])->assertAccepted();

        $this->assertDatabaseCount('payouts', 1);
        $this->assertNotNull(\DB::table('verification_codes')->value('used_at'));
        $this->assertGreaterThan(0.0, (float) $fixture['booking']->fresh()->teacher_reserved_amount);
    }

    public function test_unvalidated_verification_code_alias_cannot_replace_the_otp_field(): void
    {
        Http::fake();
        $fixture = $this->earningTeacher();
        $this->storeBank($fixture['teacher']);
        Sanctum::actingAs($fixture['teacher']);
        $code = $this->issueOtp($fixture['teacher']);

        $this->postJson('/api/teacher/payout-requests', [
            'amount' => 20000,
            'verification' => ['code' => $code],
        ], ['Idempotency-Key' => 'payout-code-alias'])->assertStatus(422);

        $this->assertDatabaseCount('payouts', 0);
        $this->assertNull(\DB::table('verification_codes')->value('used_at'));
    }

    public function test_password_method_payout_verifies_the_password_without_a_class_error(): void
    {
        Http::fake(['https://api.xendit.test/*' => Http::response(['id' => 'payout-pass-1', 'status' => 'PROCESSING'], 202)]);
        $fixture = $this->earningTeacher();
        $this->storeBank($fixture['teacher'], 'password');
        Sanctum::actingAs($fixture['teacher']);

        $this->postJson('/api/teacher/payout-requests', [
            'amount' => 20000,
            'verification' => ['password' => 'wrong-password'],
        ], ['Idempotency-Key' => 'payout-password-wrong'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Masukkan kata sandi akun yang benar untuk memverifikasi pencairan.');

        $this->assertDatabaseCount('payouts', 0);

        $this->postJson('/api/teacher/payout-requests', [
            'amount' => 20000,
            'verification' => ['password' => 'password'],
        ], ['Idempotency-Key' => 'payout-password-valid'])->assertAccepted();

        $this->assertDatabaseCount('payouts', 1);
    }

    public function test_bank_update_requires_the_configured_second_factor(): void
    {
        $fixture = $this->earningTeacher();
        $profile = TeacherProfile::query()->where('user_id', $fixture['teacher']->id)->firstOrFail();
        Sanctum::actingAs($fixture['teacher']);

        $details = [
            'payout_channel_code' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => $fixture['teacher']->name,
        ];

        // Metode OTP (default): kata sandi akun saja tidak boleh mengubah rekening.
        $this->postJson('/api/teacher/bank', $details + ['current_password' => 'password'], ['Idempotency-Key' => 'bank-otp-missing'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kode verifikasi email wajib diisi sebelum mengubah rekening.');
        $this->assertNull($profile->fresh()->account_number);

        $code = $this->issueOtp($fixture['teacher']);
        $this->postJson('/api/teacher/bank', $details + ['otp' => $code], ['Idempotency-Key' => 'bank-otp-valid'])
            ->assertOk();

        $this->assertSame('1234567890', $profile->fresh()->account_number);
    }

    public function test_bank_update_for_a_password_method_tutor_requires_the_password(): void
    {
        $fixture = $this->earningTeacher();
        $this->storeBank($fixture['teacher'], 'password');
        $profile = TeacherProfile::query()->where('user_id', $fixture['teacher']->id)->firstOrFail();
        Sanctum::actingAs($fixture['teacher']);

        $details = [
            'payout_channel_code' => 'BCA',
            'account_number' => '9999999999',
            'account_name' => $fixture['teacher']->name,
        ];

        $this->postJson('/api/teacher/bank', $details + ['current_password' => 'wrong-password'], ['Idempotency-Key' => 'bank-password-wrong'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kata sandi akun tidak sesuai.');
        $this->assertSame('1234567890', $profile->fresh()->account_number);

        $this->postJson('/api/teacher/bank', $details + ['current_password' => 'password'], ['Idempotency-Key' => 'bank-password-valid'])
            ->assertOk();
        $this->assertSame('9999999999', $profile->fresh()->account_number);
    }

    private function storeBank($teacher, string $method = 'otp'): TeacherProfile
    {
        $profile = TeacherProfile::query()->where('user_id', $teacher->id)->firstOrFail();
        $profile->forceFill([
            'bank_name' => 'BCA',
            'payout_channel_code' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'payout_verification_method' => $method,
            'payout_hold_until' => null,
        ])->save();

        return $profile;
    }

    private function issueOtp($user): string
    {
        $code = '123456';
        \DB::table('verification_codes')->insert([
            'user_id' => $user->id,
            'purpose' => OneTimeCodeService::PAYOUT_BANK_CHANGE,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $code;
    }
}
