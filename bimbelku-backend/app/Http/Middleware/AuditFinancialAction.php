<?php

namespace App\Http\Middleware;

use App\Services\FinancialAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditFinancialAction
{
    public function __construct(
        private readonly FinancialAuditService $audit
    ) {}

    public function handle(Request $request, Closure $next, ?string $action = null): Response
    {
        $resolvedAction = $action ?: $request->method().':'.$request->path();

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $status = method_exists($exception, 'getStatusCode')
                ? (int) $exception->getStatusCode()
                : 500;
            $this->audit->record($request, $status, $resolvedAction);
            throw $exception;
        }

        $this->audit->record($request, $response->getStatusCode(), $resolvedAction);

        return $response;
    }
}
