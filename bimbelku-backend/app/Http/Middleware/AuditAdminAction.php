<?php

namespace App\Http\Middleware;

use App\Services\AdminAuditService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AuditAdminAction
{
    public function __construct(private readonly AdminAuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $target = $this->audit->resolveTarget($request);
        $action = $request->method().':'.$request->path();

        try {
            return DB::transaction(function () use ($request, $next, $target, $action) {
                $response = $next($request);
                $resolvedTarget = $target;
                $override = $request->attributes->get('admin_audit_target_override');
                if ($override instanceof Model) {
                    $resolvedTarget = $this->audit->targetFromModel($override, false);
                }

                $this->audit->record(
                    $request,
                    $response->getStatusCode(),
                    $action,
                    $resolvedTarget,
                    $this->audit->snapshot($resolvedTarget['model'])
                );

                return $response;
            }, 3);
        } catch (\Throwable $exception) {
            $status = $this->statusForException($exception);

            // Percobaan yang gagal tetap dicatat di transaksi baru setelah transaksi
            // utama di-rollback. Kegagalan audit tidak boleh menyembunyikan error asli.
            try {
                $this->audit->record(
                    $request,
                    $status,
                    $action,
                    $target,
                    $this->audit->snapshot($target['model']),
                    ['exception' => class_basename($exception)]
                );
            } catch (\Throwable) {
                // Error asli tetap diteruskan; pemeriksaan log aplikasi akan menangkap
                // kegagalan infrastruktur audit secara terpisah.
            }

            throw $exception;
        }
    }

    private function statusForException(\Throwable $exception): int
    {
        if ($exception instanceof ValidationException) {
            return (int) $exception->status;
        }
        if ($exception instanceof HttpResponseException) {
            return $exception->getResponse()->getStatusCode();
        }
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }
        if ($exception instanceof AuthenticationException) {
            return 401;
        }
        if ($exception instanceof AuthorizationException) {
            return 403;
        }

        return 500;
    }
}
