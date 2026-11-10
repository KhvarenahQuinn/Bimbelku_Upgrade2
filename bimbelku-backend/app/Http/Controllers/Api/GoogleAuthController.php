<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GuestPackageLinkService;
use App\Services\PersistentLoginService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $frontendOrigin = $this->allowedFrontendOrigin($request->query('frontend_origin'));
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return $this->frontendError('Login Google belum dikonfigurasi oleh admin.', $frontendOrigin);
        }

        $state = Str::random(64);
        Cache::put($this->stateKey($state), [
            'redirect' => $this->safeRedirect($request->query('redirect')),
            'frontend_origin' => $frontendOrigin,
            'remember_device' => $request->boolean('remember_device', true),
        ], now()->addMinutes(10));

        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => $state, 'prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $attempt = $state !== '' ? Cache::pull($this->stateKey($state)) : null;
        if (! is_array($attempt)) {
            return $this->frontendError('Sesi login Google kedaluwarsa. Silakan coba lagi.');
        }
        $frontendOrigin = $this->allowedFrontendOrigin($attempt['frontend_origin'] ?? null);

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            $email = mb_strtolower(trim((string) $googleUser->getEmail()));
            $raw = is_array($googleUser->user ?? null) ? $googleUser->user : [];
            if ($email === '' || (array_key_exists('verified_email', $raw) && ! $raw['verified_email'])) {
                return $this->frontendError('Google tidak memberikan email yang sudah terverifikasi.', $frontendOrigin);
            }

            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user && ! in_array($user->role, ['student', 'teacher'], true)) {
                return $this->frontendError('Gunakan email dan kata sandi untuk masuk dengan akun ini.', $frontendOrigin);
            }
            if ($user && in_array($user->status, ['banned', 'rejected'], true)) {
                return $this->frontendError('Akun ini sedang tidak dapat digunakan. Hubungi admin BimbelKu.', $frontendOrigin);
            }

            if (! $user) {
                $user = User::create([
                    'name' => trim((string) $googleUser->getName()) ?: Str::before($email, '@'),
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(48)),
                    'has_password' => false,
                    'role' => 'student',
                    'status' => 'pending',
                    'google_id' => (string) $googleUser->getId(),
                    'google_avatar_url' => $googleUser->getAvatar(),
                    'google_onboarding_required_at' => now(),
                ]);
            } elseif ($user->role === 'teacher') {
                if ($user->status !== 'active' || ! $user->teacherProfile?->verified_at) {
                    return $this->frontendError('Akun tutor belum aktif atau belum selesai diverifikasi.', $frontendOrigin);
                }

                $user->forceFill([
                    'email_verified_at' => $user->email_verified_at ?: now(),
                    'google_id' => (string) $googleUser->getId(),
                    'google_avatar_url' => $googleUser->getAvatar(),
                ])->save();
            } else {
                $updates = [
                    'email_verified_at' => $user->email_verified_at ?: now(),
                    'google_id' => (string) $googleUser->getId(),
                    'google_avatar_url' => $googleUser->getAvatar(),
                ];

                // Samakan perilaku akun murid lama dengan login email biasa. Akun
                // Google baru tetap pending sampai data wajibnya selesai diisi.
                if ($user->status === 'pending' && ! $user->google_onboarding_required_at) {
                    $updates['status'] = 'active';
                } elseif ($user->status !== 'active' && ! $user->google_onboarding_required_at) {
                    return $this->frontendError('Akun ini sedang tidak aktif. Hubungi admin BimbelKu.', $frontendOrigin);
                }

                $user->forceFill($updates)->save();
            }

            $code = Str::random(72);
            Cache::put($this->codeKey($code), [
                'user_id' => $user->id,
                'redirect' => $attempt['redirect'] ?? null,
                'remember_device' => (bool) ($attempt['remember_device'] ?? true),
            ], now()->addMinutes(3));

            return redirect()->away($this->frontendUrl('/oauth/google/callback?code='.urlencode($code), $frontendOrigin));
        } catch (Throwable $exception) {
            report($exception);

            return $this->frontendError('Login Google gagal diproses. Silakan coba lagi.', $frontendOrigin);
        }
    }

    public function exchange(Request $request, PersistentLoginService $sessions, GuestPackageLinkService $guestOrders): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'min:40', 'max:160']]);
        $payload = Cache::pull($this->codeKey($data['code']));
        $user = is_array($payload) ? User::find($payload['user_id'] ?? null) : null;

        if (! $user || ! in_array($user->role, ['student', 'teacher'], true)) {
            return response()->json(['message' => 'Kode login Google tidak valid atau sudah digunakan.'], 422);
        }
        if ($user->role === 'teacher' && ($user->status !== 'active' || ! $user->teacherProfile?->verified_at)) {
            return response()->json(['message' => 'Akun tutor belum aktif atau belum selesai diverifikasi.'], 403);
        }

        $session = $sessions->issue(
            $user,
            (bool) ($payload['remember_device'] ?? true),
            'google'
        );

        return response()->json([
            'message' => 'Login Google berhasil.',
            'access_token' => $session['access_token'],
            'token_type' => 'Bearer',
            'session_transport' => 'http_only_cookie',
            'session_expires_at' => $session['expires_at']->toIso8601String(),
            'remember_device' => $session['remembered'],
            'pending_guest_order' => $guestOrders->pendingCodeFor($user),
            'user' => $user,
            'requires_profile_completion' => $user->role === 'student' && $user->google_onboarding_required_at !== null,
            'redirect' => $payload['redirect'] ?? null,
        ])->withCookie($session['cookie']);
    }

    public function completeProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'student' && $user->google_onboarding_required_at, 403, 'Profil ini tidak memerlukan onboarding Google.');

        $data = $request->validate([
            'phone' => ['nullable', 'regex:/^[0-9+() .-]{8,20}$/'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'school_name' => ['nullable', 'string', 'max:150'],
            'grade' => ['nullable', 'string', 'max:100'],
            'terms_accepted' => ['accepted'],
            'privacy_accepted' => ['accepted'],
        ]);

        $birthDate = ! empty($data['date_of_birth']) ? Carbon::parse($data['date_of_birth']) : null;
        if ($birthDate && $birthDate->age < 18) {
            $data += $request->validate([
                'guardian_name' => ['required', 'string', 'max:150'],
                'guardian_phone' => ['required', 'regex:/^[0-9+() .-]{8,20}$/'],
                'guardian_relationship' => ['required', 'in:orang_tua,wali_keluarga,wali_resmi'],
                'guardian_consent' => ['accepted'],
            ]);
        }

        $user->forceFill([
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'school_name' => $data['school_name'] ?? null,
            'grade' => $data['grade'] ?? null,
            'guardian_name' => $data['guardian_name'] ?? null,
            'guardian_phone' => $data['guardian_phone'] ?? null,
            'guardian_relationship' => $data['guardian_relationship'] ?? null,
            'guardian_consent_at' => isset($data['guardian_consent']) ? now() : null,
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'policy_version' => config('bimbelku.policy_version'),
            'consent_ip' => $request->ip(),
            'consent_user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'google_onboarding_required_at' => null,
            'status' => 'active',
        ])->save();

        return response()->json(['message' => 'Profil murid berhasil dilengkapi.', 'user' => $user->fresh()]);
    }

    private function safeRedirect(mixed $value): ?string
    {
        if (! is_string($value) || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }
        $path = strtok($value, '?#') ?: '';

        return $path === '/murid'
            || $path === '/search'
            || $path === '/payment'
            || str_starts_with($path, '/student/')
            || str_starts_with($path, '/pesanan/')
            || $path === '/guru'
            || str_starts_with($path, '/guru/')
            ? $value
            : null;
    }

    private function allowedFrontendOrigin(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $candidate = rtrim(trim($value), '/');
        $parts = parse_url($candidate);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '') {
            return null;
        }

        $origin = ($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $allowed = array_map(static fn ($item) => rtrim((string) $item, '/'), (array) config('cors.allowed_origins', []));
        $allowed[] = rtrim((string) config('app.frontend_url'), '/');

        return in_array($origin, array_unique($allowed), true) ? $origin : null;
    }

    private function frontendError(string $message, ?string $frontendOrigin = null): RedirectResponse
    {
        return redirect()->away($this->frontendUrl('/login?google_error='.urlencode($message), $frontendOrigin));
    }

    private function frontendUrl(string $path, ?string $frontendOrigin = null): string
    {
        return rtrim($frontendOrigin ?: (string) config('app.frontend_url'), '/').$path;
    }

    private function stateKey(string $state): string
    {
        return 'google_oauth_state:'.hash('sha256', $state);
    }

    private function codeKey(string $code): string
    {
        return 'google_oauth_code:'.hash('sha256', $code);
    }
}
