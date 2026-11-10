<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyRecord;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            return response()->json([
                'message' => 'Kunci transaksi tidak valid. Muat ulang halaman lalu coba lagi.',
            ], 422);
        }

        $actorId = (int) $request->user()->id;
        $action = $request->method().':'.$request->path();
        $requestHash = $this->requestHash($request);
        $record = $this->resolveRecord($actorId, $action, $key, $requestHash);

        if (! $record->wasRecentlyCreated) {
            if (! hash_equals($record->request_hash, $requestHash)) {
                return response()->json([
                    'message' => 'Kunci transaksi sudah digunakan untuk data berbeda.',
                ], 409);
            }
            if ($record->status === 'completed') {
                return response(
                    $record->response_body ?? '{}',
                    $record->response_status ?? 200,
                    ['Content-Type' => 'application/json']
                );
            }

            return response()->json([
                'message' => 'Transaksi yang sama sedang diproses. Tunggu sebentar.',
            ], 409);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $record->delete();
            throw $exception;
        }

        if ($response->getStatusCode() >= 500) {
            $record->delete();

            return $response;
        }

        $record->forceFill([
            'status' => 'completed',
            'response_status' => $response->getStatusCode(),
            'response_body' => method_exists($response, 'getContent')
                ? (string) $response->getContent()
                : '{}',
        ])->save();

        return $response;
    }

    private function resolveRecord(
        int $actorId,
        string $action,
        string $key,
        string $requestHash
    ): IdempotencyRecord {
        $existing = IdempotencyRecord::query()
            ->where('actor_id', $actorId)
            ->where('action', $action)
            ->where('idempotency_key', $key)
            ->first();
        if ($existing?->expires_at?->isPast()) {
            $existing->delete();
            $existing = null;
        }
        if ($existing) {
            return $existing;
        }

        try {
            return IdempotencyRecord::create([
                'actor_id' => $actorId,
                'action' => $action,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'expires_at' => now()->addDay(),
            ]);
        } catch (QueryException $exception) {
            $record = IdempotencyRecord::query()
                ->where('actor_id', $actorId)
                ->where('action', $action)
                ->where('idempotency_key', $key)
                ->first();
            if ($record) {
                return $record;
            }
            throw $exception;
        }
    }

    private function requestHash(Request $request): string
    {
        $payload = Arr::except($request->all(), [
            'code',
            'current_code',
            'password',
            'password_confirmation',
            'current_password',
        ]);
        ksort($payload);

        $files = [];
        foreach ($request->allFiles() as $key => $file) {
            if (is_array($file)) {
                $files[$key] = array_map(
                    fn ($item) => hash_file('sha256', $item->getRealPath()),
                    $file
                );
            } else {
                $files[$key] = hash_file('sha256', $file->getRealPath());
            }
        }
        ksort($files);

        return hash('sha256', json_encode([
            'payload' => $payload,
            'files' => $files,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
