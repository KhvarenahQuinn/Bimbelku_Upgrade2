<?php

namespace App\Services;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class AdminActivityLogService
{
    private const SENSITIVE_KEY = '/password|passwd|pin|token|secret|authorization|cookie|credential|otp|one.?time.?code/i';

    public function record(
        ?Request $request,
        ?User $actor,
        string $module,
        string $action,
        string $summary,
        ?string $targetType = null,
        int|string|null $targetId = null,
        array $metadata = [],
        string $outcome = 'success'
    ): AdminActivityLog {
        return AdminActivityLog::create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'Admin tidak dikenal',
            'module' => $module,
            'action' => $action,
            'summary' => $summary,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $this->sanitize($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'outcome' => $outcome,
            'created_at' => now(),
        ]);
    }

    public function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY, (string) $key) === 1) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = '[omitted]';
            }
        }

        return $clean;
    }
}
