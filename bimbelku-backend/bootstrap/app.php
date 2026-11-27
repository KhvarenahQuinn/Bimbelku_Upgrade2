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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare is the public reverse proxy. Restrict direct origin access
        // to Cloudflare IP ranges at the firewall before trusting forwarded IPs.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

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
        $isApiOrJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $isApiOrJson($request)
        );

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) use ($isApiOrJson) {
            if (! $isApiOrJson($request)) {
                return null;
            }

            if ($request->is('api/*') && $exception->getModel() === Promotion::class) {
                return response()->json([
                    'message' => 'Promo tidak ditemukan atau sudah dihapus.',
                ], 404);
            }

            return response()->json([
                'message' => 'Data tidak ditemukan.',
            ], 404);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($isApiOrJson) {
            if (! $isApiOrJson($request)) {
                return null;
            }

            return response()->json([
                'message' => trim($exception->getMessage()) ?: 'Anda tidak memiliki akses untuk melakukan aksi ini.',
            ], 403);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($isApiOrJson) {
            if (! $isApiOrJson($request)) {
                return null;
            }

            $status = $exception->getStatusCode();
            $message = trim($exception->getMessage());
            if ($message === '') {
                $message = Response::$statusTexts[$status] ?? 'Terjadi kesalahan.';
            }

            return response()->json([
                'message' => $message,
            ], $status, $exception->getHeaders());
        });

        $exceptions->render(function (Throwable $exception, Request $request) use ($isApiOrJson) {
            if (
                ! $isApiOrJson($request)
                || $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof HttpResponseException
            ) {
                return null;
            }

            return response()->json([
                'message' => 'Terjadi kesalahan pada server.',
            ], 500);
        });
    })->create();
