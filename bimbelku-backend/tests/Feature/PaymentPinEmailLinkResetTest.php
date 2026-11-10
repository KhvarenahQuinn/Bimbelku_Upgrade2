<?php

namespace Tests\Feature;

use App\Mail\ResetPaymentPinMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentPinEmailLinkResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_email_sends_a_link_with_a_hashed_token(): void
    {
        Mail::fake();
        config(['app.frontend_url' => 'https://bimbelku.example']);
        $user = User::factory()->create([
            'email' => 'pin-link@example.com',
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('111111'),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/student/payment-pin/forgot-email')
            ->assertOk()
            ->assertJsonPath('verification_method', 'email_link')
            ->assertJsonPath('requires_email_link', true)
            ->assertJsonPath('resend_after_seconds', 60);

        Mail::assertSent(ResetPaymentPinMail::class, function (ResetPaymentPinMail $mail) use ($user): bool {
            $this->assertTrue($mail->hasTo($user->email));
            $this->assertStringStartsWith('https://bimbelku.example/reset-pin?', $mail->resetUrl);
            $mail->assertSeeInHtml($mail->resetUrl);
            $mail->assertSeeInText($mail->resetUrl);

            $query = (string) parse_url($mail->resetUrl, PHP_URL_QUERY);
            parse_str($query, $parameters);
            $this->assertSame($user->email, $parameters['email'] ?? null);
            $this->assertNotEmpty($parameters['token'] ?? null);

            $record = DB::table('payment_pin_reset_tokens')->where('email', $user->email)->first();
            $this->assertNotNull($record);
            $this->assertTrue(Hash::check((string) $parameters['token'], $record->token));
            $this->assertNotSame((string) $parameters['token'], $record->token);

            return true;
        });
    }

    public function test_email_token_resets_the_payment_pin_and_is_single_use(): void
    {
        $user = User::factory()->create([
            'email' => 'pin-reset@example.com',
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('111111'),
        ]);
        $token = 'pin-reset-plain-token';
        $this->storeToken($user->email, $token, now());

        $this->postJson('/api/payment-pin/reset-with-token', [
            'email' => $user->email,
            'token' => $token,
            'pin' => '222222',
            'pin_confirmation' => '222222',
        ])->assertOk()->assertJsonPath('verified_with', 'email_link');

        $this->assertTrue(Hash::check('222222', $user->fresh()->payment_pin_hash));
        $this->assertDatabaseMissing('payment_pin_reset_tokens', ['email' => $user->email]);

        // The same link cannot be redeemed twice.
        $this->postJson('/api/payment-pin/reset-with-token', [
            'email' => $user->email,
            'token' => $token,
            'pin' => '333333',
            'pin_confirmation' => '333333',
        ])->assertStatus(422)->assertJsonPath('error_code', 'payment_pin_reset_token_invalid');

        $this->assertTrue(Hash::check('222222', $user->fresh()->payment_pin_hash));
    }

    public function test_expired_email_token_is_rejected_and_deleted(): void
    {
        $user = User::factory()->create([
            'email' => 'pin-expired@example.com',
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('111111'),
        ]);
        $this->storeToken($user->email, 'expired-token', now()->subMinutes(61));

        $this->postJson('/api/payment-pin/reset-with-token', [
            'email' => $user->email,
            'token' => 'expired-token',
            'pin' => '222222',
            'pin_confirmation' => '222222',
        ])->assertStatus(422)->assertJsonPath('error_code', 'payment_pin_reset_token_expired');

        $this->assertDatabaseMissing('payment_pin_reset_tokens', ['email' => $user->email]);
        $this->assertTrue(Hash::check('111111', $user->fresh()->payment_pin_hash));
    }

    public function test_invalid_email_token_does_not_change_the_pin(): void
    {
        $user = User::factory()->create([
            'email' => 'pin-invalid@example.com',
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('111111'),
        ]);
        $this->storeToken($user->email, 'correct-token', now());

        $this->postJson('/api/payment-pin/reset-with-token', [
            'email' => $user->email,
            'token' => 'wrong-token',
            'pin' => '222222',
            'pin_confirmation' => '222222',
        ])->assertStatus(422)->assertJsonPath('error_code', 'payment_pin_reset_token_invalid');

        $this->assertTrue(Hash::check('111111', $user->fresh()->payment_pin_hash));
        $this->assertDatabaseHas('payment_pin_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_email_has_a_sixty_second_cooldown(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'pin-cooldown@example.com',
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => Hash::make('111111'),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/student/payment-pin/forgot-email')->assertOk();
        $firstTokenHash = DB::table('payment_pin_reset_tokens')
            ->where('email', $user->email)
            ->value('token');

        $this->postJson('/api/student/payment-pin/forgot-email')
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'payment_pin_reset_cooldown')
            ->assertJsonPath('resend_after_seconds', 60)
            ->assertHeader('Retry-After', '60');

        Mail::assertSentCount(1);
        $this->assertSame(
            $firstTokenHash,
            DB::table('payment_pin_reset_tokens')->where('email', $user->email)->value('token')
        );

        $this->travel(61)->seconds();

        $this->postJson('/api/student/payment-pin/forgot-email')->assertOk();
        Mail::assertSentCount(2);
        $this->assertNotSame(
            $firstTokenHash,
            DB::table('payment_pin_reset_tokens')->where('email', $user->email)->value('token')
        );
    }

    public function test_forgot_email_requires_a_configured_pin(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'pin-none@example.com',
            'role' => 'student',
            'status' => 'active',
            'payment_pin_hash' => null,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/student/payment-pin/forgot-email')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'payment_pin_not_configured');

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('payment_pin_reset_tokens', ['email' => $user->email]);
    }

    private function storeToken(string $email, string $token, \DateTimeInterface $createdAt): void
    {
        DB::table('payment_pin_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make($token),
            'created_at' => $createdAt,
        ]);
    }
}
