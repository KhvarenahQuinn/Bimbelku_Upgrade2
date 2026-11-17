<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifikasi Cloudflare Turnstile untuk endpoint publik yang rawan bot.
 *
 * Dua mode:
 * - `TURNSTILE_SECRET_KEY` kosong → middleware ini tembus (tidak ada friksi di
 *   pengembangan lokal dan suite test).
 * - `TURNSTILE_SECRET_KEY` terisi → permintaan wajib membawa token valid dari
 *   `cf-turnstile-response` (body) atau header `X-Turnstile-Token`.
 *
 * Cloudflare tidak dapat dihubungi diperlakukan sebagai gagal (fail-closed)
 * agar gangguan jaringan tidak diam-diam mematikan perlindungan anti-bot.
 */
class VerifyTurnstile
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('turnstile.enabled')) {
            return $next($request);
        }

        $token = trim((string) (
            $request->input('cf-turnstile-response')
            ?: $request->header('X-Turnstile-Token', '')
        ));

        if ($token === '') {
            return $this->reject('Verifikasi keamanan belum lengkap. Muat ulang halaman lalu coba lagi.');
        }

        try {
            $response = Http::asForm()
                ->timeout(max(1, (int) config('turnstile.timeout_seconds', 5)))
                ->post((string) config('turnstile.verify_url'), [
                    'secret' => (string) config('turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (\Throwable $exception) {
            Log::channel('security')->warning('turnstile_verify_unreachable', [
                'error' => $exception::class,
            ]);

            return $this->reject('Verifikasi keamanan sedang tidak tersedia. Coba lagi beberapa saat lagi.');
        }

        if (! $response->successful() || ($response->json('success') !== true)) {
            Log::channel('security')->notice('turnstile_verify_rejected', [
                'status' => $response->status(),
                'codes' => (array) $response->json('error-codes', []),
            ]);

            return $this->reject('Verifikasi keamanan gagal. Muat ulang halaman lalu coba lagi.');
        }

        return $next($request);
    }

    private function reject(string $message): Response
    {
        return response()->json([
            'message' => $message,
            'error_code' => 'turnstile_failed',
            'errors' => ['cf-turnstile-response' => [$message]],
        ], 422);
    }
}
