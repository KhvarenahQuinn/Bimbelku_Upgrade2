<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_user_role(): void
    {
        $student = User::factory()->create([
            'email' => 'murid@example.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => 'password123',
            'login_portal' => 'user',
            'remember_device' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login berhasil')
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.email', $student->email)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('session_transport', 'http_only_cookie')
            ->assertJsonPath('remember_device', true)
            ->assertJsonStructure(['access_token', 'session_expires_at'])
            ->assertCookie('bimbelku_session');

        $token = $student->tokens()->latest('id')->firstOrFail();
        $this->assertStringContainsString(':remembered', $token->name);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_authenticated_request_works_after_token_reuse(): void
    {
        $student = User::factory()->create([
            'email' => 'reuse@example.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $login = $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => 'password123',
            'login_portal' => 'user',
        ]);
        $login->assertOk();
        $accessToken = $login->json('access_token');

        $first = $this->withToken($accessToken)->getJson('/api/user');
        $first->assertOk()->assertJsonPath('email', $student->email);

        $second = $this->withToken($accessToken)->getJson('/api/user');
        $second->assertOk()->assertJsonPath('email', $student->email);

        $this->assertSame(1, $student->tokens()->count());
    }

    public function test_logout_revokes_current_bearer_token(): void
    {
        $student = User::factory()->create([
            'email' => 'logout.bearer@example.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $login = $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => 'password123',
            'login_portal' => 'user',
        ]);
        $login->assertOk();
        $accessToken = $login->json('access_token');

        $this->withToken($accessToken)->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Sesi berhasil ditutup.')
            ->assertCookieExpired('bimbelku_session');

        $this->withToken($accessToken)->getJson('/api/user')
            ->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_revoked_token_returns_401(): void
    {
        $student = User::factory()->create([
            'email' => 'revoked@example.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $login = $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => 'password123',
            'login_portal' => 'user',
        ]);
        $login->assertOk();
        $accessToken = $login->json('access_token');

        $token = PersonalAccessToken::findToken($accessToken);
        $token->delete();

        $this->withToken($accessToken)->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_expired_token_returns_401(): void
    {
        $student = User::factory()->create([
            'email' => 'expired@example.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $token = $student->createToken('auth:expired', ['app:access'], Carbon::parse('-1 minute'));
        $plainText = $token->plainTextToken;

        $this->withToken($plainText)->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_student_and_teacher_login_use_same_endpoint(): void
    {
        $student = User::factory()->create([
            'email' => 'student.shared@example.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $teacher = User::factory()->create([
            'email' => 'teacher.shared@example.test',
            'password' => Hash::make('password123'),
            'role' => 'teacher',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        tap(TeacherProfile::create([
            'user_id' => $teacher->id,
            'expertise' => 'Matematika',
        ]), fn ($profile) => $profile->forceFill(['verified_at' => now()])->save());

        $studentLogin = $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => 'password123',
            'login_portal' => 'user',
        ]);
        $studentLogin->assertOk()
            ->assertJsonPath('user.role', 'student');

        $teacherLogin = $this->postJson('/api/login', [
            'email' => $teacher->email,
            'password' => 'password123',
            'login_portal' => 'user',
        ]);
        $teacherLogin->assertOk()
            ->assertJsonPath('user.role', 'teacher');
    }
}
