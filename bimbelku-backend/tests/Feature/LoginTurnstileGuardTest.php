<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginTurnstileGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('auth-login:ip:127.0.0.1');
    }

    public function test_login_still_works_without_a_token_when_turnstile_is_not_configured(): void
    {
        $this->student('tanpa-token@gmail.com');

        $this->postJson('/api/login', [
            'email' => 'tanpa-token@gmail.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_login_rejects_a_missing_turnstile_token_when_the_challenge_is_enabled(): void
    {
        $this->enableTurnstile();
        $this->student('butuh-token@gmail.com');

        $this->postJson('/api/login', [
            'email' => 'butuh-token@gmail.com',
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'turnstile_failed');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_accepts_a_valid_turnstile_token(): void
    {
        $this->enableTurnstile();
        $this->student('dengan-token@gmail.com');

        $this->postJson('/api/login', [
            'email' => 'dengan-token@gmail.com',
            'password' => 'password123',
            'cf-turnstile-response' => 'valid-token',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_repeated_failures_on_one_account_are_throttled_per_ip_and_email(): void
    {
        $this->student('ditebak@gmail.com');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'ditebak@gmail.com',
                'password' => 'salah-terus',
            ])->assertUnauthorized();
        }

        // Bucket IP+email habis lebih dulu daripada bucket IP, jadi akun yang
        // sama tidak bisa terus ditebak dari satu alamat.
        $this->postJson('/api/login', [
            'email' => 'ditebak@gmail.com',
            'password' => 'salah-terus',
        ])->assertStatus(429)->assertJsonPath('error_code', 'auth_login_rate_limited');
    }

    private function student(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => Hash::make('password123'),
        ]);
    }

    private function enableTurnstile(): void
    {
        config()->set('turnstile.enabled', true);
        config()->set('turnstile.secret_key', 'turnstile-test-secret');
        config()->set('turnstile.verify_url', 'https://turnstile.test/siteverify');

        Http::fake([
            'turnstile.test/*' => Http::response(['success' => true]),
        ]);
    }
}
