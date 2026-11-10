<?php

namespace App\Http\Middleware;

use App\Services\PersistentLoginService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFromSessionCookie
{
    public function __construct(private readonly PersistentLoginService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $this->sessions->tokenFromCookie($request);
        $bearerToken = trim((string) $request->bearerToken());
        $usesCookie = $plainTextToken !== null
            && ($bearerToken === '' || hash_equals($this->sessions->marker(), $bearerToken));

        if ($usesCookie) {
            $this->assertSameOriginForUnsafeRequest($request);
            $request->headers->set('Authorization', 'Bearer '.$plainTextToken);
        }

        return $next($request);
    }

    private function assertSameOriginForUnsafeRequest(Request $request): void
    {
        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $origin = $this->normalizeOrigin($request->header('Origin'));
        if ($origin === null) {
            $origin = $this->normalizeOrigin($request->header('Referer'));
        }

        $allowed = array_filter(array_map(
            fn ($value) => $this->normalizeOrigin(is_string($value) ? $value : null),
            array_merge(
                (array) config('cors.allowed_origins', []),
                [(string) config('app.frontend_url'), $request->getSchemeAndHttpHost()]
            )
        ));

        abort_unless($origin !== null && in_array($origin, array_unique($allowed), true), 419, 'Sesi browser tidak dapat diverifikasi. Muat ulang halaman lalu coba lagi.');
    }

    private function normalizeOrigin(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = parse_url(trim($value));
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        return ($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
