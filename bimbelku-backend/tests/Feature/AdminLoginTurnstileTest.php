<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminLoginTurnstileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('auth-login-admin:127.0.0.1:admin@example.test');
    }

    public function test_admin_login_remains_available_when_turnstile_is_disabled(): void
    {
        config()->set('turnstile.enabled', false);
        $this->admin();

        $this->postJson('/api/login/admin', [
            'email' => 'admin@example.test',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('user.role', 'admin');
    }

    public function test_admin_login_rejects_a_missing_turnstile_token_when_enabled(): void
    {
        $this->enableTurnstile();
        $this->admin();

        $this->postJson('/api/login/admin', [
            'email' => 'admin@example.test',
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'turnstile_failed')
            ->assertJsonValidationErrors('cf-turnstile-response');

        Http::assertNothingSent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_login_accepts_a_valid_turnstile_token(): void
    {
        $this->enableTurnstile();
        $this->admin();
        Http::fake(['turnstile.test/*' => Http::response(['success' => true])]);

        $this->postJson('/api/login/admin', [
            'email' => 'admin@example.test',
            'password' => 'password123',
            'turnstile_token' => 'valid-token',
        ])->assertOk()->assertJsonPath('user.role', 'admin');

        Http::assertSent(fn ($request) => $request['response'] === 'valid-token');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'admin@example.test',
            'role' => 'admin',
            'status' => 'active',
            'password' => Hash::make('password123'),
        ]);
    }

    private function enableTurnstile(): void
    {
        config()->set('turnstile.enabled', true);
        config()->set('turnstile.secret_key', 'turnstile-test-secret');
        config()->set('turnstile.verify_url', 'https://turnstile.test/siteverify');
    }
}
