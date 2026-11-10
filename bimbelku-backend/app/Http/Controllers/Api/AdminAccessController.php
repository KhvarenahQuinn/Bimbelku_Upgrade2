<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Support\AdminPermissionCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminAccessController extends Controller
{
    /**
     * Riwayat perubahan tetap tersedia walaupun project memakai satu admin utama.
     * Endpoint pengelolaan akun admin sengaja tidak disediakan.
     */
    public function audit(Request $request)
    {
        $validated = $request->validate([
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'category' => ['nullable', 'string', 'max:60'],
            'permission' => ['nullable', 'string', Rule::in(AdminPermissionCatalog::allCodes())],
            'status' => ['nullable', Rule::in(['success', 'failed'])],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ]);

        $query = AdminAuditLog::query()
            ->select([
                'id', 'actor_id', 'actor_name', 'actor_email', 'action', 'category',
                'permission_code', 'route_name', 'method', 'response_status',
                'target_type', 'target_id', 'reason', 'created_at',
            ])
            ->with('actor:id,name,email');
        if (! empty($validated['actor_id'])) {
            $query->where('actor_id', $validated['actor_id']);
        }
        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }
        if (! empty($validated['permission'])) {
            $query->where('permission_code', $validated['permission']);
        }
        if (($validated['status'] ?? null) === 'success') {
            $query->whereBetween('response_status', [200, 399]);
        } elseif (($validated['status'] ?? null) === 'failed') {
            $query->where('response_status', '>=', 400);
        }
        if (! empty($validated['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($validated['search'])).'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('action', 'like', $term)
                    ->orWhere('actor_name', 'like', $term)
                    ->orWhere('actor_email', 'like', $term)
                    ->orWhere('target_type', 'like', $term)
                    ->orWhere('target_id', 'like', $term)
                    ->orWhere('reason', 'like', $term);
            });
        }
        if (! empty($validated['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['date_from'])->startOfDay());
        }
        if (! empty($validated['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['date_to'])->endOfDay());
        }

        $page = $query->latest('id')->paginate($validated['per_page'] ?? 20);
        $items = collect($page->items());
        $targetRoles = User::query()
            ->whereIn('id', $items
                ->where('target_type', 'User')
                ->pluck('target_id')
                ->filter()
                ->unique()
                ->values())
            ->pluck('role', 'id');

        return response()->json([
            'data' => $items->map(fn (AdminAuditLog $log) => $this->formatAuditSummary(
                $log,
                $targetRoles->get((int) $log->target_id)
            )),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'permission_groups' => AdminPermissionCatalog::groups(),
                'actors' => User::query()
                    ->where('role', 'admin')
                    ->orderBy('name')
                    ->get(['id', 'name', 'email']),
            ],
        ]);
    }

    public function auditDetail(AdminAuditLog $adminAuditLog)
    {
        return response()->json([
            'id' => $adminAuditLog->id,
            'request_payload' => $adminAuditLog->request_payload,
            'before_state' => $adminAuditLog->before_state,
            'after_state' => $adminAuditLog->after_state,
        ]);
    }

    public function auditIntegrity(AdminAuditService $auditService)
    {
        return response()->json(
            $auditService->verifyChain(AdminAuditLog::query()->oldest('id')->get())
        );
    }

    private function formatAuditSummary(AdminAuditLog $log, ?string $targetRole = null): array
    {
        return [
            'id' => $log->id,
            'request_id' => $log->request_id,
            'actor' => [
                'id' => $log->actor_id,
                'name' => $log->actor?->name ?? $log->actor_name ?? 'Admin terhapus',
                'email' => $log->actor?->email ?? $log->actor_email,
            ],
            'action' => $log->action,
            'category' => $log->category,
            'permission_code' => $log->permission_code,
            'route_name' => $log->route_name,
            'method' => $log->method,
            'response_status' => (int) $log->response_status,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'target_role' => $targetRole,
            'reason' => $log->reason,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
