<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_email_contains_canonical_frontend_link_and_both_bodies(): void
    {
        Mail::fake();
        config(['app.frontend_url' => 'https://bimbelku.example']);
        $user = User::factory()->create(['email' => 'murid@example.com']);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'Jika email terdaftar, link reset akan dikirim.');

        Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use ($user): bool {
            $this->assertTrue($mail->hasTo($user->email));
            $this->assertStringStartsWith(
                'https://bimbelku.example/reset-password?',
                $mail->resetUrl
            );
            $mail->assertSeeInHtml('Reset Password');
            $mail->assertSeeInHtml($mail->resetUrl);
            $mail->assertSeeInText($mail->resetUrl);

            $query = (string) parse_url($mail->resetUrl, PHP_URL_QUERY);
            parse_str($query, $parameters);
            $this->assertSame($user->email, $parameters['email'] ?? null);
            $this->assertNotEmpty($parameters['token'] ?? null);

            $record = DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->first();
            $this->assertNotNull($record);
            $this->assertTrue(Hash::check((string) $parameters['token'], $record->token));
            $this->assertNotSame((string) $parameters['token'], $record->token);

            return true;
        });
    }

    public function test_reset_email_has_a_sixty_second_server_cooldown(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'cooldown@example.com']);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk();
        $firstTokenHash = DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->value('token');

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'password_reset_cooldown')
            ->assertJsonPath('retry_after_seconds', 60)
            ->assertHeader('Retry-After', '60');

        Mail::assertSentCount(1);
        $this->assertSame(
            $firstTokenHash,
            DB::table('password_reset_tokens')->where('email', $user->email)->value('token')
        );

        $this->travel(61)->seconds();

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk();

        Mail::assertSentCount(2);
        $this->assertNotSame(
            $firstTokenHash,
            DB::table('password_reset_tokens')->where('email', $user->email)->value('token')
        );
    }

    public function test_unknown_email_gets_the_same_generic_response_and_cooldown(): void
    {
        Mail::fake();
        $email = 'tidak-ada@example.com';

        $this->postJson('/api/forgot-password', ['email' => $email])
            ->assertOk()
            ->assertJsonPath('message', 'Jika email terdaftar, link reset akan dikirim.');

        $this->postJson('/api/forgot-password', ['email' => $email])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'password_reset_cooldown');

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
    }

    public function test_valid_token_resets_the_password_and_revokes_sessions(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-ok@example.com',
            'password' => Hash::make('old-password'),
            'has_password' => true,
        ]);
        $user->createToken('device');
        $token = 'valid-reset-token';
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('new-password-123', $fresh->password));
        $this->assertTrue((bool) $fresh->has_password);
        $this->assertNotNull($fresh->password_updated_at);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reset_password_rejects_wrong_or_expired_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-bad@example.com',
            'password' => Hash::make('old-password'),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('valid-token'),
            'created_at' => now()->subMinutes(61),
        ]);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => 'wrong-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(400);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => 'valid-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(400);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }
}
