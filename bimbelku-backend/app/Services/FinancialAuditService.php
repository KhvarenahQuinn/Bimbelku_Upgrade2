<?php

namespace App\Services;

use App\Models\FinancialAuditLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancialAuditService
{
    public function record(Request $request, int $responseStatus, string $action): FinancialAuditLog
    {
        $payload = $this->sanitize($request->except([
            'password',
            'password_confirmation',
            'current_password',
            'code',
            'current_code',
        ]));
        $requestId = (string) Str::uuid();

        return DB::transaction(function () use (
            $request,
            $responseStatus,
            $action,
            $payload,
            $requestId
        ) {
            Setting::query()
                ->where('key', 'finance_audit_chain_lock')
                ->lockForUpdate()
                ->firstOrFail();
            $previousHash = FinancialAuditLog::query()
                ->latest('id')
                ->value('entry_hash');
            $routeName = $request->route()?->getName() ?? $request->path();
            $createdAt = now()->startOfSecond();
            $entryHash = hash('sha256', json_encode([
                'previous_hash' => $previousHash,
                'request_id' => $requestId,
                'actor_id' => $request->user()?->id,
                'action' => $action,
                'method' => $request->method(),
                'path' => $routeName,
                'status' => $responseStatus,
                'payload' => $payload,
                'created_at' => $createdAt->toISOString(),
            ], JSON_UNESCAPED_UNICODE));

            return FinancialAuditLog::create([
                'actor_id' => $request->user()?->id,
                'request_id' => $requestId,
                'action' => $action,
                'route_name' => $routeName,
                'method' => $request->method(),
                'response_status' => $responseStatus,
                'target_type' => $this->targetType($request),
                'target_id' => $this->targetId($request),
                'payload' => $payload,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'previous_hash' => $previousHash,
                'entry_hash' => $entryHash,
                'created_at' => $createdAt,
            ]);
        }, 3);
    }

    private function sanitize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($value instanceof UploadedFile) {
                $payload[$key] = [
                    'file_name' => $value->getClientOriginalName(),
                    'size' => $value->getSize(),
                    'mime' => $value->getMimeType(),
                ];

                continue;
            }
            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value);

                continue;
            }
            if (in_array($key, ['account_number', 'sender_account_number'], true)) {
                $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
                $payload[$key] = str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
            }
        }

        return $payload;
    }

    private function targetType(Request $request): ?string
    {
        foreach (['order', 'refund', 'payoutApproval', 'booking'] as $parameter) {
            if ($request->route($parameter)) {
                return $parameter;
            }
        }

        return null;
    }

    private function targetId(Request $request): ?int
    {
        foreach (['order', 'refund', 'payoutApproval', 'booking'] as $parameter) {
            $value = $request->route($parameter);
            if ($value) {
                return (int) ($value->id ?? $value);
            }
        }

        return null;
    }
}
