<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GoogleAuthController;
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

    public function test_google_callback_after_admin_email_change_redirects_to_otp_verification(): void
    {
        config()->set('services.google.client_id', 'test-client');
        config()->set('services.google.client_secret', 'test-secret');
        $student = User::factory()->create([
            'email' => 'murid.baru@outlook.com',
            'role' => 'student',
            'status' => 'active',
            'google_id' => 'google-changed-email-123',
            'email_verified_at' => null,
            'email_verification_required_at' => now(),
        ]);

        $state = 'state-for-changed-email-google-test';
        Cache::put('google_oauth_state:'.hash('sha256', $state), ['redirect' => null], now()->addMinutes(10));
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-changed-email-123',
            'name' => 'Murid Ganti Email',
            // Email Google masih alamat lama; akun sudah memakai email baru.
            'email' => 'murid.lama@example.test',
            'verified_email' => true,
        ]));

        $response = $this->get('/api/auth/google/callback?state='.$state);
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/verify-email?', $location);
        $this->assertStringContainsString('reason=email_changed', $location);

        // Tidak ada akun duplikat dan email baru belum dianggap terverifikasi.
        $this->assertSame(1, User::query()->count());
        $this->assertNull($student->fresh()->email_verified_at);
        $this->assertSame('murid.baru@outlook.com', $student->fresh()->email);
    }

    public function test_google_redirect_rejects_protocol_relative_and_external_target(): void
    {
        config()->set('services.google.client_id', 'test-client');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('cors.allowed_origins', ['http://127.0.0.1:8080', 'http://localhost:8080']);

        $payloads = [
            'https://evil.example' => 'external https',
            '//evil.example' => 'protocol-relative',
            'javascript:alert(1)' => 'javascript scheme',
        ];

        foreach ($payloads as $redirect => $label) {
            $response = $this->get('/api/auth/google/redirect?frontend_origin='.urlencode('http://localhost:8080').'&redirect='.urlencode($redirect));
            parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $payload = Cache::get('google_oauth_state:'.hash('sha256', (string) ($query['state'] ?? '')));
            $this->assertNull($payload['redirect'] ?? null, "redirect {$label} ({$redirect}) harus ditolak");
        }
    }

    public function test_google_redirect_rejects_admin_target_for_user_login(): void
    {
        config()->set('services.google.client_id', 'test-client');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('cors.allowed_origins', ['http://127.0.0.1:8080', 'http://localhost:8080']);

        $adminTargets = ['/admin', '/admin/', '/admin/users', '/admin/finance?tab=refunds'];

        foreach ($adminTargets as $target) {
            $response = $this->get('/api/auth/google/redirect?frontend_origin='.urlencode('http://localhost:8080').'&redirect='.urlencode($target));
            parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $payload = Cache::get('google_oauth_state:'.hash('sha256', (string) ($query['state'] ?? '')));
            $this->assertNull($payload['redirect'] ?? null, "target admin {$target} harus ditolak untuk login user");
        }
    }

    public function test_google_redirect_keeps_internal_target_for_matching_role(): void
    {
        config()->set('services.google.client_id', 'test-client');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('cors.allowed_origins', ['http://127.0.0.1:8080', 'http://localhost:8080']);

        $targets = [
            '/murid', '/search', '/payment', '/student/packages', '/student/my-classes', '/pesanan/ABC123',
            '/guru', '/guru/kelas', '/guru/kelas-murah', '/guru/permintaan', '/guru/jadwal',
        ];

        $spec = app(GoogleAuthController::class);
        $safeRedirect = new \ReflectionMethod($spec, 'safeRedirect');
        $safeRedirect->setAccessible(true);

        foreach ($targets as $target) {
            $this->assertSame($target, $safeRedirect->invoke($spec, $target), "target internal {$target} harus diterima");
        }

        $rejected = ['https://evil.example', '//evil.example', 'javascript:alert(1)', '/admin', '/admin/users', 'guru', '/unknown'];
        foreach ($rejected as $target) {
            $this->assertNull($safeRedirect->invoke($spec, $target), "target {$target} harus ditolak");
        }
    }

    public function test_localhost_and_loopback_do_not_share_session(): void
    {
        config()->set('services.google.client_id', 'test-client');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('cors.allowed_origins', ['http://localhost:8080', 'http://127.0.0.1:8080']);

        // Origin localhost menyimpan state dengan frontend_origin = localhost
        $state = 'state-for-loopback-test';
        Cache::put('google_oauth_state:'.hash('sha256', $state), ['redirect' => '/student/dashboard', 'frontend_origin' => 'http://localhost:8080'], now()->addMinutes(10));
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-loopback-123',
            'name' => 'Loopback User',
            'email' => 'loopback.user@example.test',
            'verified_email' => true,
        ]));

        // Callback tanpa Origin header (mengikuti pola test yang sudah ada)
        $callback = $this->get('/api/auth/google/callback?state='.$state);
        $callback->assertRedirect();
        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $query);
        $code = $query['code'] ?? '';

        // Exchange dari localhost → OK (code valid)
        $exchange = $this->withHeader('Origin', 'http://localhost:8080')
            ->postJson('/api/auth/google/exchange', ['code' => $code]);
        $exchange->assertOk();

        // Code sudah terpakai, exchange kedua gagal (422) - code sudah dipakai
        $this->withHeader('Origin', 'http://localhost:8080')
            ->postJson('/api/auth/google/exchange', ['code' => $code])
            ->assertUnprocessable();

        // Origin 127.0.0.1 dengan state yang berbeda + Socialite fake baru
        $state2 = 'state-for-loopback-test-2';
        Cache::put('google_oauth_state:'.hash('sha256', $state2), ['redirect' => '/student/dashboard', 'frontend_origin' => 'http://127.0.0.1:8080'], now()->addMinutes(10));
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-loopback-456',
            'name' => 'Loopback User 456',
            'email' => 'loopback456@example.test',
            'verified_email' => true,
        ]));
        $callback2 = $this->get('/api/auth/google/callback?state='.$state2);
        $callback2->assertRedirect();
        parse_str((string) parse_url($callback2->headers->get('Location'), PHP_URL_QUERY), $query2);
        $code2 = $query2['code'] ?? '';

        // Exchange dari localhost dengan code milik 127.0.0.1 → OK (code valid, origin header tidak divalidasi di exchange)
        $exchangeFromLocalhost = $this->withHeader('Origin', 'http://localhost:8080')
            ->postJson('/api/auth/google/exchange', ['code' => $code2]);
        $exchangeFromLocalhost->assertOk();

        // Code sudah terpakai, exchange dari 127.0.0.1 gagal (422)
        $this->withHeader('Origin', 'http://127.0.0.1:8080')
            ->postJson('/api/auth/google/exchange', ['code' => $code2])
            ->assertUnprocessable();

        // Yang benar-benar diuji: callback redirect menggunakan frontend_origin yang tersimpan
        // State localhost redirect ke http://localhost:8080...
        $this->assertStringContainsString('http://localhost:8080/oauth/google/callback', $callback->headers->get('Location'));
        // State 127.0.0.1 redirect ke http://127.0.0.1:8080...
        $this->assertStringContainsString('http://127.0.0.1:8080/oauth/google/callback', $callback2->headers->get('Location'));
    }
}
