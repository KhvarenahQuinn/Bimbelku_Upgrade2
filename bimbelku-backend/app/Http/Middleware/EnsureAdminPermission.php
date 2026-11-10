<?php

namespace App\Http\Middleware;

use App\Support\AdminPermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();
        if (! $user || $user->role !== 'admin') {
            return response()->json(['message' => 'Akses admin diperlukan.'], 403);
        }

        $required = $permission ?: AdminPermissionCatalog::permissionFor($request);
        $request->attributes->set('required_admin_permission', $required);

        if (! $required) {
            return response()->json([
                'message' => 'Rute admin ini belum memiliki kebijakan akses.',
            ], 403);
        }

        if (! $user->hasAdminPermission($required)) {
            return response()->json([
                'message' => 'Akun admin utama tidak dapat membuka fitur ini.',
                'required_permission' => $required,
            ], 403);
        }

        return $next($request);
    }
}
