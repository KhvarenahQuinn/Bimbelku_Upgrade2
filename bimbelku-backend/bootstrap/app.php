<?php

use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\AuditAdminAction;
use App\Http\Middleware\AuditFinancialAction;
use App\Http\Middleware\AuthenticateFromSessionCookie;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\RefreshPersistentSession;
use App\Http\Middleware\VerifyTurnstile;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Di belakang Cloudflare (atau reverse proxy lain) Laravel hanya tahu
        // skema asli dan IP klien bila proxy dipercaya. Daftar diambil dari
        // TRUSTED_PROXIES (dipisah koma; "REMOTE_ADDR" berarti memercayai
        // proxy yang langsung terhubung). Kosong = tidak ada proxy dipercaya,
        // sehingga pengembangan lokal tetap memakai koneksi langsung.
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        ), fn (string $proxy): bool => $proxy !== ''));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
                    | Request::HEADER_X_FORWARDED_PREFIX,
            );
        }

        // Aplikasi ini tidak punya route bernama `login`: autentikasi memakai
        // bearer token / cookie sesi, bukan redirect ke halaman web. Tanpa ini
        // middleware `auth` memanggil route('login') untuk tamu dan melempar
        // RouteNotFoundException (500) sebelum handler JSON sempat berjalan.
        // Kembalikan null supaya tamu dapat 401 JSON, bukan redirect.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->prependToGroup('api', AuthenticateFromSessionCookie::class);
        $middleware->append(ApplySecurityHeaders::class);

        $middleware->alias([
            'active.account' => EnsureActiveAccount::class,
            'role' => EnsureUserHasRole::class,
            'idempotency' => EnforceIdempotency::class,
            'finance.audit' => AuditFinancialAction::class,
            'admin.permission' => EnsureAdminPermission::class,
            'admin.audit' => AuditAdminAction::class,
            'feature' => EnsureFeatureEnabled::class,
            'persistent.session' => RefreshPersistentSession::class,
            'turnstile' => VerifyTurnstile::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if ($request->is('api/*') && $exception->getModel() === Promotion::class) {
                return response()->json([
                    'message' => 'Promo tidak ditemukan atau sudah dihapus.',
                ], 404);
            }

            return null;
        });
    })->create();
