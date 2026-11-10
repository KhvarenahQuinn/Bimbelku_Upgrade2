<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Sesi tidak ditemukan. Silakan masuk kembali.',
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Sesi tidak berlaku karena akun sedang tidak aktif.',
            ], $user->role === 'admin' ? 403 : 401);
        }

        return $next($request);
    }
}
