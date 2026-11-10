<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_redirect_remembers_only_an_allowed_frontend_origin(): void
    {
        config()->set('services.google.client_id', 'test-client');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('cors.allowed_origins', ['http://127.0.0.1:8080', 'http://localhost:8080']);

        $response = $this->get('/api/auth/google/redirect?frontend_origin='.urlencode('http://localhost:8080'));
        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $payload = Cache::get('google_oauth_state:'.hash('sha256', (string) ($query['state'] ?? '')));
        $this->assertSame('http://localhost:8080', $payload['frontend_origin'] ?? null);

        $rejected = $this->get('/api/auth/google/redirect?frontend_origin='.urlencode('https://evil.example'));
        parse_str((string) parse_url($rejected->headers->get('Location'), PHP_URL_QUERY), $rejectedQuery);
        $rejectedPayload = Cache::get('google_oauth_state:'.hash('sha256', (string) ($rejectedQuery['state'] ?? '')));
        $this->assertNull($rejectedPayload['frontend_origin'] ?? null);

        // Tujuan guru diizinkan, tujuan admin tetap ditolak.
        $teacher = $this->get('/api/auth/google/redirect?frontend_origin='.urlencode('http://localhost:8080').'&redirect='.urlencode('/guru/kelas?tab=1'));
        parse_str((string) parse_url($teacher->headers->get('Location'), PHP_URL_QUERY), $teacherQuery);
        $teacherPayload = Cache::get('google_oauth_state:'.hash('sha256', (string) ($teacherQuery['state'] ?? '')));
        $this->assertSame('/guru/kelas?tab=1', $teacherPayload['redirect'] ?? null);

        $admin = $this->get('/api/auth/google/redirect?frontend_origin='.urlencode('http://localhost:8080').'&redirect='.urlencode('/admin/users'));
        parse_str((string) parse_url($admin->headers->get('Location'), PHP_URL_QUERY), $adminQuery);
        $adminPayload = Cache::get('google_oauth_state:'.hash('sha256', (string) ($adminQuery['state'] ?? '')));
        $this->assertNull($adminPayload['redirect'] ?? null);
    }

    public function test_google_callback_creates_incomplete_student_and_code_can_only_be_exchanged_once(): void
    {
        config()->set('services.google.client_id', 'test-client');
        config()->set('services.google.client_secret', 'test-secret');

        $state = 'state-for-google-authentication-test';
        Cache::put('google_oauth_state:'.hash('sha256', $state), ['redirect' => '/student/dashboard'], now()->addMinutes(10));
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-student-123',
            'name' => 'Murid Google',
            'email' => 'murid.google@example.test',
            'verified_email' => true,
        ]));

        $callback = $this->get('/api/auth/google/callback?state='.$state);
        $callback->assertRedirect();
        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $query);

        $exchange = $this->postJson('/api/auth/google/exchange', ['code' => $query['code'] ?? '']);
        $exchange->assertOk()
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.status', 'pending')
            ->assertJsonPath('requires_profile_completion', true)
            ->assertJsonPath('redirect', '/student/dashboard');
        $this->assertNotEmpty($exchange->json('access_token'));
        $this->assertSame('Bearer', $exchange->json('token_type'));

        $this->postJson('/api/auth/google/exchange', ['code' => $query['code'] ?? ''])->assertUnprocessable();
        $this->assertDatabaseHas('users', [
            'email' => 'murid.google@example.test',
            'google_id' => 'google-student-123',
            'role' => 'student',
            'status' => 'pending',
        ]);
    }

    public function test_google_student_must_complete_required_profile_before_activation(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'pending',
            'google_onboarding_required_at' => now(),
        ]);
        $token = $user->createToken('test-google-auth')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/google/complete-profile', [
                'phone' => '081234567890',
                'date_of_birth' => '2000-01-01',
                'school_name' => 'Sekolah Contoh',
                'grade' => 'SMA',
                'terms_accepted' => true,
                'privacy_accepted' => true,
            ]);

        $response->assertOk()->assertJsonPath('user.status', 'active');
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'active',
            'phone' => '081234567890',
            'google_onboarding_required_at' => null,
        ]);
    }

    public function test_google_student_can_accept_policies_and_complete_optional_profile_later(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'pending',
            'phone' => null,
            'date_of_birth' => null,
            'google_onboarding_required_at' => now(),
        ]);
        $token = $user->createToken('test-google-auth-minimal')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/google/complete-profile', [
                'terms_accepted' => true,
                'privacy_accepted' => true,
            ]);

        $response->assertOk()->assertJsonPath('user.status', 'active');
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'active',
            'phone' => null,
            'date_of_birth' => null,
            'google_onboarding_required_at' => null,
        ]);
    }

    public function test_verified_teacher_can_login_with_existing_google_email(): void
    {
        config()->set('services.google.client_id', 'test-client');
        config()->set('services.google.client_secret', 'test-secret');
        $teacher = User::factory()->create(['email' => 'tutor@example.test', 'role' => 'teacher', 'status' => 'active']);
        TeacherProfile::create([
            'user_id' => $teacher->id,
            'verified_at' => now(),
        ]);

        $state = 'state-for-teacher-google-test';
        Cache::put('google_oauth_state:'.hash('sha256', $state), ['redirect' => null], now()->addMinutes(10));
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-teacher-123',
            'email' => 'tutor@example.test',
            'verified_email' => true,
        ]));

        $callback = $this->get('/api/auth/google/callback?state='.$state);
        $callback->assertRedirect();
        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->postJson('/api/auth/google/exchange', ['code' => $query['code'] ?? ''])
            ->assertOk()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('requires_profile_completion', false);
        $this->assertDatabaseHas('users', ['email' => 'tutor@example.test', 'google_id' => 'google-teacher-123']);
    }

    public function test_google_login_refuses_existing_admin_email(): void
    {
        config()->set('services.google.client_id', 'test-client');
        config()->set('services.google.client_secret', 'test-secret');
        User::factory()->create(['email' => 'admin@example.test', 'role' => 'admin', 'status' => 'active']);

        $state = 'state-for-admin-conflict-test';
        Cache::put('google_oauth_state:'.hash('sha256', $state), ['redirect' => null], now()->addMinutes(10));
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-admin-123',
            'email' => 'admin@example.test',
            'verified_email' => true,
        ]));

        $response = $this->get('/api/auth/google/callback?state='.$state);
        $response->assertRedirect();
        $this->assertStringContainsString('google_error=', $response->headers->get('Location'));
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test', 'google_id' => 'google-admin-123']);
    }
}
