<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminPortalLoginTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_ERROR = 'Email atau kata sandi salah.';

    public function test_student_cannot_use_the_admin_portal_and_gets_the_generic_message(): void
    {
        User::factory()->create([
            'email' => 'student@example.test',
            'password' => Hash::make('password'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->postJson('/api/login/admin', [
            'email' => 'student@example.test',
            'password' => 'password',
        ])->assertStatus(401)->assertJsonPath('message', self::GENERIC_ERROR);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_teacher_cannot_use_the_admin_portal_and_gets_the_generic_message(): void
    {
        User::factory()->create([
            'email' => 'teacher@example.test',
            'password' => Hash::make('password'),
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this->postJson('/api/login/admin', [
            'email' => 'teacher@example.test',
            'password' => 'password',
        ])->assertStatus(401)->assertJsonPath('message', self::GENERIC_ERROR);
    }

    public function test_message_is_identical_for_unknown_email_and_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $unknown = $this->postJson('/api/login/admin', [
            'email' => 'nobody@example.test',
            'password' => 'password',
        ]);
        $wrongPassword = $this->postJson('/api/login/admin', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ]);

        // Identical status and body: the portal must not reveal whether the
        // email exists, let alone which role it holds.
        $this->assertSame(401, $unknown->status());
        $this->assertSame(401, $wrongPassword->status());
        $this->assertSame($unknown->json('message'), $wrongPassword->json('message'));
        $this->assertSame(self::GENERIC_ERROR, $unknown->json('message'));
    }

    public function test_inactive_admin_is_rejected_with_the_generic_message(): void
    {
        User::factory()->create([
            'email' => 'inactive-admin@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'status' => 'banned',
        ]);

        $this->postJson('/api/login/admin', [
            'email' => 'inactive-admin@example.test',
            'password' => 'password',
        ])->assertStatus(401)->assertJsonPath('message', self::GENERIC_ERROR);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_valid_admin_logs_in_and_receives_permissions(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/login/admin', [
            'email' => 'admin@example.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.role', 'admin');

        $permissions = $response->json('user.admin_permissions');
        $this->assertIsArray($permissions);
        $this->assertContains('finance.payouts', $permissions);
        $this->assertContains('cases.manage', $permissions);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($response->json('access_token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('role', 'admin');
    }

    public function test_admin_portal_is_rate_limited_per_ip_and_email(): void
    {
        RateLimiter::clear('auth-login-admin:127.0.0.1:admin@example.test');
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login/admin', [
                'email' => 'admin@example.test',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/login/admin', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(429)->assertJsonPath('error_code', 'admin_rate_limited');

        // A different email from the same IP has its own bucket, so one
        // targeted account cannot lock out every admin behind a shared IP.
        $this->postJson('/api/login/admin', [
            'email' => 'other-admin@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_old_login_endpoint_still_refuses_admin_without_the_admin_portal(): void
    {
        User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
            'login_portal' => 'user',
        ])->assertForbidden()->assertJsonPath('error_code', 'admin_portal_required');
    }
}
