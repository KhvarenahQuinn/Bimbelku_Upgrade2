<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplySecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // More permissive CSP for auth routes that need Cloudflare Turnstile
        $isAuthRoute = $request->is('api/register')
            || $request->is('api/login')
            || $request->is('api/forgot-password')
            || $request->is('api/verify-email*')
            || $request->is('api/resend-verification*');

        if ($isAuthRoute) {
            $response->headers->set('Content-Security-Policy',
                "default-src 'self'; ".
                "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; ".
                "script-src-elem 'self' 'unsafe-inline' https://challenges.cloudflare.com; ".
                'frame-src https://challenges.cloudflare.com; '.
                "style-src 'self' 'unsafe-inline'; ".
                "img-src 'self' data: https:; ".
                "font-src 'self' data:; ".
                "connect-src 'self' https://challenges.cloudflare.com; ".
                "base-uri 'self'; ".
                "frame-ancestors 'none'; ".
                "form-action 'self'"
            );
        } else {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
        }

        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('X-DNS-Prefetch-Control', 'off');

        $response->headers->set(
            'Permissions-Policy',
            'camera=(self), geolocation=(self), microphone=()'
        );

        if ($request->bearerToken() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
