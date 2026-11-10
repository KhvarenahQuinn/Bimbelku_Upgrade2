<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PersistentLoginService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RefreshPersistentSession
{
    public function __construct(private readonly PersistentLoginService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (
            $user instanceof User
            && $token instanceof PersonalAccessToken
            && $this->sessions->shouldRefresh($token, $user)
        ) {
            $plainTextToken = $this->sessions->tokenFromCookie($request);
            if ($plainTextToken !== null) {
                $expiresAt = $this->sessions->refresh($token);
                $response->headers->setCookie($this->sessions->cookie($plainTextToken, $expiresAt, true));
            }
        }

        return $response;
    }
}
