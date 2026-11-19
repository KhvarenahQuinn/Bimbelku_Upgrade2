<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Turnstile bersifat opsional: tanpa kunci ia tembus agar pengembangan lokal dan
 * suite test berjalan tanpa friksi. Saat kunci diisi, registrasi wajib membawa
 * token valid dan kegagalan jaringan tidak boleh meloloskan permintaan.
 */
class TurnstileRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'name' => 'Murid Turnstile',
            'email' => 'murid.turnstile@gmail.com',
            'phone' => '081234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
            'date_of_birth' => '2005-05-05',
        ];
    }

    public function test_registration_works_when_turnstile_is_not_configured(): void
    {
        config()->set('turnstile.secret_key', null);
        config()->set('turnstile.enabled', false);
        Http::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();

        Http::assertNothingSent();
        $this->assertDatabaseHas('users', ['email' => 'murid.turnstile@gmail.com']);
    }

    public function test_registration_is_rejected_without_token_when_turnstile_enabled(): void
    {
        config()->set('turnstile.secret_key', 'test-secret');
        config()->set('turnstile.enabled', true);
        Http::fake();

        $response = $this->postJson('/api/register', $this->payload());

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'turnstile_failed')
            ->assertJsonValidationErrors('cf-turnstile-response');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('users', ['email' => 'murid.turnstile@gmail.com']);
    }

    public function test_registration_succeeds_with_a_valid_token(): void
    {
        config()->set('turnstile.secret_key', 'test-secret');
        config()->set('turnstile.enabled', true);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);

        $this->postJson('/api/register', array_merge($this->payload(), [
            'cf-turnstile-response' => 'valid-token',
        ]))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'murid.turnstile@gmail.com']);
    }

    public function test_registration_is_rejected_when_turnstile_returns_failure(): void
    {
        config()->set('turnstile.secret_key', 'test-secret');
        config()->set('turnstile.enabled', true);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ], 200),
        ]);

        $this->postJson('/api/register', array_merge($this->payload(), [
            'cf-turnstile-response' => 'bad-token',
        ]))->assertStatus(422)->assertJsonPath('error_code', 'turnstile_failed');

        $this->assertDatabaseMissing('users', ['email' => 'murid.turnstile@example.test']);
    }

    public function test_registration_fails_closed_when_turnstile_is_unreachable(): void
    {
        config()->set('turnstile.secret_key', 'test-secret');
        config()->set('turnstile.enabled', true);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::failedConnection(),
        ]);

        $this->postJson('/api/register', array_merge($this->payload(), [
            'cf-turnstile-response' => 'any-token',
        ]))->assertStatus(422)->assertJsonPath('error_code', 'turnstile_failed');

        $this->assertDatabaseMissing('users', ['email' => 'murid.turnstile@example.test']);
    }

    public function test_forgot_password_is_protected_too(): void
    {
        config()->set('turnstile.secret_key', 'test-secret');
        config()->set('turnstile.enabled', true);
        Http::fake();

        $this->postJson('/api/forgot-password', ['email' => 'murid.turnstile@gmail.com'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'turnstile_failed');
    }
}
