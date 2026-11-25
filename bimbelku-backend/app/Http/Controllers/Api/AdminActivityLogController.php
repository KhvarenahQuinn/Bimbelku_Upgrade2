<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'module' => ['nullable', 'string', 'max:60'],
            'outcome' => ['nullable', Rule::in(['success', 'failed'])],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ]);

        $query = AdminActivityLog::query()->with('actor:id,name');
        if (! empty($validated['actor_id'])) {
            $query->where('actor_id', $validated['actor_id']);
        }
        if (! empty($validated['module'])) {
            $query->where('module', $validated['module']);
        }
        if (! empty($validated['outcome'])) {
            $query->where('outcome', $validated['outcome']);
        }
        if (! empty($validated['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($validated['search'])).'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('summary', 'like', $term)
                    ->orWhere('action', 'like', $term)
                    ->orWhere('module', 'like', $term)
                    ->orWhere('actor_name', 'like', $term)
                    ->orWhere('target_type', 'like', $term)
                    ->orWhere('target_id', 'like', $term);
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

        return response()->json([
            'data' => $items->map(fn (AdminActivityLog $log) => [
                'id' => $log->id,
                'actor' => [
                    'id' => $log->actor_id,
                    'name' => $log->actor?->name ?? $log->actor_name ?? 'Admin dihapus',
                ],
                'module' => $log->module,
                'action' => $log->action,
                'summary' => $log->summary,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'metadata' => $log->metadata,
                'outcome' => $log->outcome,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'actors' => AdminActivityLog::query()
                    ->whereNotNull('actor_id')
                    ->select(['actor_id', 'actor_name'])
                    ->distinct()
                    ->orderBy('actor_name')
                    ->get()
                    ->map(fn (AdminActivityLog $log) => [
                        'id' => $log->actor_id,
                        'name' => $log->actor_name ?? 'Admin dihapus',
                    ])
                    ->values(),
                'modules' => AdminActivityLog::query()->select('module')->distinct()->orderBy('module')->pluck('module'),
            ],
        ]);
    }
}
