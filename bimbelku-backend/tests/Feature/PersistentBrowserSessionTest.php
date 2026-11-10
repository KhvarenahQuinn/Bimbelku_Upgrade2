<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersistentBrowserSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cors.allowed_origins', ['http://localhost:8080']);
        config()->set('app.frontend_url', 'http://localhost:8080');
        config()->set('session.secure', false);
    }

    public function test_student_can_use_remembered_http_only_cookie_without_bearer_header(): void
    {
        $user = User::factory()->create([
            'email' => 'murid.session@example.test',
            'password' => bcrypt('rahasia-session'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $login = $this->withHeader('Origin', 'http://localhost:8080')
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'rahasia-session',
                'remember_device' => true,
            ]);

        $login->assertOk()
            ->assertJsonPath('session_transport', 'http_only_cookie')
            ->assertJsonPath('remember_device', true)
            ->assertCookie('bimbelku_session');

        $token = $user->tokens()->latest('id')->firstOrFail();
        $this->assertStringContainsString(':remembered', $token->name);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $cookie = collect($login->headers->getCookies())
            ->first(fn ($item) => $item->getName() === 'bimbelku_session');

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());

        $this->withCredentials()->withUnencryptedCookie('bimbelku_session', $cookie->getValue())
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    public function test_admin_session_ignores_remember_device_and_expires_within_twelve_hours(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin.session@example.test',
            'password' => bcrypt('rahasia-admin'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $login = $this->withHeader('Origin', 'http://localhost:8080')
            ->postJson('/api/login', [
                'email' => $admin->email,
                'password' => 'rahasia-admin',
                'login_portal' => 'admin',
                'remember_device' => true,
            ]);

        $login->assertOk()->assertJsonPath('remember_device', false);

        $token = $admin->tokens()->latest('id')->firstOrFail();
        $this->assertStringContainsString(':standard', $token->name);
        $this->assertTrue($token->expires_at->between(now()->addHours(11), now()->addHours(13)));
    }

    public function test_cookie_session_rejects_foreign_origin_for_mutating_request(): void
    {
        $user = User::factory()->create([
            'email' => 'csrf.session@example.test',
            'password' => bcrypt('rahasia-session'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $login = $this->withHeader('Origin', 'http://localhost:8080')
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'rahasia-session',
                'remember_device' => true,
            ]);
        $cookie = collect($login->headers->getCookies())
            ->first(fn ($item) => $item->getName() === 'bimbelku_session');

        $this->withCredentials()->withUnencryptedCookie('bimbelku_session', $cookie->getValue())
            ->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/logout')
            ->assertStatus(419);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_logout_revokes_cookie_token_and_expires_browser_cookie(): void
    {
        $user = User::factory()->create([
            'email' => 'logout.session@example.test',
            'password' => bcrypt('rahasia-session'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $login = $this->withHeader('Origin', 'http://localhost:8080')
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'rahasia-session',
                'remember_device' => true,
            ]);
        $cookie = collect($login->headers->getCookies())
            ->first(fn ($item) => $item->getName() === 'bimbelku_session');

        $this->withCredentials()->withUnencryptedCookie('bimbelku_session', $cookie->getValue())
            ->withHeader('Origin', 'http://localhost:8080')
            ->postJson('/api/logout')
            ->assertOk()
            ->assertCookieExpired('bimbelku_session');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_all_revokes_every_session_for_the_account(): void
    {
        $user = User::factory()->create([
            'email' => 'logout.all@example.test',
            'password' => bcrypt('rahasia-session'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $first = $this->withHeader('Origin', 'http://localhost:8080')
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'rahasia-session',
                'remember_device' => true,
            ]);
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'rahasia-session',
            'remember_device' => false,
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $cookie = collect($first->headers->getCookies())
            ->first(fn ($item) => $item->getName() === 'bimbelku_session');

        $this->withCredentials()
            ->withUnencryptedCookie('bimbelku_session', $cookie->getValue())
            ->withHeader('Origin', 'http://localhost:8080')
            ->postJson('/api/logout-all')
            ->assertOk()
            ->assertCookieExpired('bimbelku_session');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
