<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;

class PersistentLoginService
{
    public function issue(User $user, bool $rememberDevice, string $source = 'password'): array
    {
        $remembered = $user->role !== 'admin' && $rememberDevice;
        $minutes = $user->role === 'admin'
            ? max(60, (int) config('persistent_sessions.admin_minutes', 720))
            : ($remembered
                ? max(1440, (int) config('persistent_sessions.remembered_minutes', 43200))
                : max(60, (int) config('persistent_sessions.standard_minutes', 720)));

        $expiresAt = now()->addMinutes($minutes);
        $user->tokens()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        $name = sprintf(
            'auth:%s:%s',
            Str::slug($source) ?: 'login',
            $remembered ? 'remembered' : 'standard'
        );

        $accessToken = $user->createToken(
            $name,
            $remembered ? ['app:access', 'session:remembered'] : ['app:access'],
            $expiresAt
        );

        return [
            'access_token' => $accessToken->plainTextToken,
            'token' => $accessToken,
            'expires_at' => $expiresAt,
            'remembered' => $remembered,
            'cookie' => $this->cookie($accessToken->plainTextToken, $expiresAt, $remembered),
        ];
    }

    public function cookie(string $plainTextToken, CarbonInterface $expiresAt, bool $persistent): Cookie
    {
        $minutes = $persistent
            ? max(1, (int) ceil(now()->diffInMinutes($expiresAt, false)))
            : 0;

        return cookie(
            $this->cookieName(),
            Crypt::encryptString($plainTextToken),
            $minutes,
            (string) config('session.path', '/'),
            config('session.domain'),
            $this->secureCookie(),
            true,
            false,
            (string) config('session.same_site', 'lax')
        );
    }

    public function forgetCookie(): Cookie
    {
        return cookie()->forget(
            $this->cookieName(),
            (string) config('session.path', '/'),
            config('session.domain')
        );
    }

    public function tokenFromCookie(Request $request): ?string
    {
        $value = $request->cookie($this->cookieName());
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($value);

            return is_string($token) && str_contains($token, '|') ? $token : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function shouldRefresh(PersonalAccessToken $token, User $user): bool
    {
        if ($user->role === 'admin' || ! str_contains((string) $token->name, ':remembered')) {
            return false;
        }

        if (! $token->expires_at) {
            return false;
        }

        $threshold = max(60, (int) config('persistent_sessions.refresh_threshold_minutes', 10080));

        return $token->expires_at->lte(now()->addMinutes($threshold));
    }

    public function refresh(PersonalAccessToken $token): CarbonInterface
    {
        $minutes = max(1440, (int) config('persistent_sessions.remembered_minutes', 43200));
        $expiresAt = now()->addMinutes($minutes);
        $token->forceFill(['expires_at' => $expiresAt])->save();

        return $expiresAt;
    }

    public function marker(): string
    {
        return (string) config('persistent_sessions.marker', 'cookie-session');
    }

    public function cookieName(): string
    {
        return (string) config('persistent_sessions.cookie', 'bimbelku_session');
    }

    private function secureCookie(): bool
    {
        $configured = config('session.secure');

        return $configured === null ? app()->isProduction() : (bool) $configured;
    }
}
