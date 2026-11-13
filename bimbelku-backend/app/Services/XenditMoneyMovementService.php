<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\TeacherPayoutRequest;
use App\Models\User;
use App\Support\XenditPayoutChannelCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class XenditMoneyMovementService
{
    /**
     * TeacherPayoutService already injects this service, so the reverse
     * dependency is resolved lazily per call. Injecting it in the constructor
     * creates a container cycle that recurses until PHP runs out of memory.
     */
    private function payouts(): TeacherPayoutService
    {
        return app(TeacherPayoutService::class);
    }

    public function configured(): bool
    {
        return (bool) config('xendit.enabled') && filled(config('xendit.secret_key')) && filled(config('xendit.webhook_token'));
    }

    public function createRefund(Refund $refund): array
    {
        $refund->refresh()->loadMissing('order');
        if ($refund->status === 'paid' || $refund->gateway_status === 'SUCCEEDED') {
            return $this->refundPayload($refund);
        }
        $breakdown = $refund->tenderBreakdown();
        if ($breakdown['external_funded_amount'] <= 0.009) {
            throw new RuntimeException('Refund ini tidak memiliki bagian pembayaran eksternal.');
        }
        if (blank($refund->order?->gateway_payment_request_id)) {
            throw new RuntimeException('Referensi pembayaran asal tidak tersedia pada transaksi ini.');
        }
        if (filled($refund->gateway_refund_id) && ! in_array($refund->gateway_status, ['FAILED', 'REJECTED'], true)) {
            return $this->refundPayload($refund);
        }

        $reference = $refund->gateway_reference_id ?: 'BKU-RFD-'.$refund->id;
        $refund = $this->storeRefundResponse($refund, [
            'payment_provider' => 'xendit',
            'destination_method' => 'xendit_original',
            'destination_selected_at' => $refund->destination_selected_at ?: now(),
            'gateway_reference_id' => $reference,
            'gateway_status' => 'SUBMITTING',
            'gateway_failure_code' => null,
            'gateway_failure_message' => null,
        ]);
        if ($refund->status === 'paid' || $refund->gateway_status === 'SUCCEEDED') {
            return $this->refundPayload($refund);
        }
        $refund->loadMissing('order');
        $response = $this->client()->withHeader('idempotency-key', $reference)->post(config('xendit.base_url').'/refunds', [
            'reference_id' => $reference,
            'payment_request_id' => $refund->order->gateway_payment_request_id,
            'currency' => config('xendit.currency', 'IDR'),
            'amount' => (int) round($breakdown['external_funded_amount']),
            'reason' => 'REQUESTED_BY_CUSTOMER',
            'metadata' => ['refund_id' => (string) $refund->id, 'order_id' => (string) $refund->order_id],
        ]);
        if (! $response->successful()) {
            $body = is_array($response->json()) ? $response->json() : [];
            $definitive = in_array($response->status(), [400, 401, 403, 404, 422], true);
            $refund = $this->storeRefundResponse($refund, array_filter([
                'gateway_status' => $definitive ? 'FAILED' : null,
                'gateway_failure_code' => $body['error_code'] ?? $body['failure_code'] ?? null,
                'gateway_failure_message' => $body['message'] ?? null,
                'last_reconciled_at' => now(),
            ], fn ($value) => $value !== null));
            if ($refund->status === 'paid' || $refund->gateway_status === 'SUCCEEDED') {
                return $this->refundPayload($refund);
            }
            report(new RuntimeException('Xendit refund failed: '.$response->status().' '.$response->body()));
            throw new RuntimeException($definitive
                ? 'Pengembalian dana ke metode pembayaran asal ditolak. Pilih Saldo BimbelKu atau hubungi bantuan.'
                : 'Refund otomatis belum dapat dibuat. Periksa saldo operasional dan coba kembali.');
        }
        $data = $response->json();
        $refund = $this->storeRefundResponse($refund, [
            'payment_provider' => 'xendit',
            'destination_method' => 'xendit_original',
            'destination_selected_at' => $refund->destination_selected_at ?: now(),
            'gateway_refund_id' => $data['id'] ?? null,
            'gateway_status' => strtoupper((string) ($data['status'] ?? 'PENDING')),
            'gateway_failure_code' => $data['failure_code'] ?? null,
            'gateway_failure_message' => $data['failure_message'] ?? $data['message'] ?? null,
            'gateway_submitted_at' => now(),
            'last_reconciled_at' => now(),
        ]);

        return $this->refundPayload($refund->fresh());
    }

    public function retrieveRefund(Refund $refund): array
    {
        $refund->refresh();
        if ($refund->status === 'paid' || $refund->gateway_status === 'SUCCEEDED') {
            return $this->refundPayload($refund);
        }
        if (blank($refund->gateway_refund_id)) {
            return $this->createRefund($refund);
        }

        $response = $this->client()->get(config('xendit.base_url').'/refunds/'.$refund->gateway_refund_id);
        if (! $response->successful()) {
            throw new RuntimeException('Status refund belum dapat diperiksa.');
        }
        $data = $response->json();
        $refund = $this->storeRefundResponse($refund, [
            'gateway_status' => strtoupper((string) ($data['status'] ?? $refund->gateway_status ?? 'PENDING')),
            'gateway_failure_code' => $data['failure_code'] ?? null,
            'gateway_failure_message' => $data['failure_message'] ?? $data['message'] ?? null,
            'gateway_submitted_at' => $refund->gateway_submitted_at ?: now(),
            'last_reconciled_at' => now(),
        ]);

        return $this->refundPayload($refund->fresh());
    }

    /** Apply a remote response against current state, not a pre-request model. */
    private function storeRefundResponse(Refund $refund, array $updates): Refund
    {
        return DB::transaction(function () use ($refund, $updates) {
            $current = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($current->status === 'paid' || $current->gateway_status === 'SUCCEEDED') {
                // A webhook may settle the refund while the HTTP request runs.
                // Never undo its success, identifiers, or cleared failure code.
                return $current;
            }
            if (filled($refund->gateway_refund_id) && $current->gateway_refund_id !== $refund->gateway_refund_id) {
                return $current;
            }
            if ($updates !== []) {
                $current->forceFill($updates)->save();
            }

            return $current->fresh();
        }, 3);
    }

    public function createPayout(Payout $payout, User $teacher): array
    {
        $profile = $teacher->teacherProfile;
        $channel = XenditPayoutChannelCatalog::get((string) $payout->payout_channel_code);
        if (! $channel) {
            throw new RuntimeException('Bank tujuan belum mendukung pencairan otomatis.');
        }
        // A REVERSED payout is terminal: retrying it here would silently send
        // the money a second time for an allocation that was already refunded.
        if (in_array($payout->status, ['reversed'], true)) {
            return $this->payoutPayload($payout);
        }
        if (filled($payout->gateway_payout_id) && ! in_array($payout->gateway_status, ['FAILED', 'REJECTED'], true)) {
            return $this->payoutPayload($payout);
        }

        $reference = $payout->gateway_reference_id ?: 'BKU-PAY-'.$payout->id;
        // Creating the payout is the only place that writes gateway_status
        // directly; every later transition goes through applyGatewayStatus().
        $payout->forceFill(['gateway_reference_id' => $reference, 'gateway_status' => 'SUBMITTING'])->save();
        $names = preg_split('/\s+/', trim($teacher->name), 2) ?: ['Tutor'];
        $amount = (int) round((float) $payout->amount);
        $body = [
            'reference_id' => $reference,
            'recipient' => [
                'type' => 'INDIVIDUAL',
                'given_name' => $names[0] ?: 'Tutor',
                'surname' => $names[1] ?? null,
                'relationship' => 'EMPLOYEE',
                'details' => array_filter([
                    'personal_email' => $teacher->email,
                    'personal_mobile_number' => $this->normalisePhone((string) ($profile?->phone ?? $teacher->phone ?? '')),
                ]),
                'address' => [
                    'country' => 'ID',
                    // Never substitute a placeholder: an invented street line
                    // would be sent to the bank as the recipient's address.
                    'street_line_1' => $teacher->address,
                ],
                'account_details' => [
                    'currency' => 'IDR',
                    'account_country' => 'ID',
                    'account_holder_name' => $payout->account_name,
                    'account_number' => preg_replace('/\D+/', '', (string) $payout->account_number),
                    'routing_type_1' => $channel['routing_type'],
                    'routing_value_1' => $channel['routing_value'],
                ],
            ],
            'payout_details' => [
                'destination_currency' => 'IDR',
                'destination_amount' => $amount,
            ],
            'source_of_fund' => 'BUSINESS_REVENUE',
            'purpose_code' => 'SALARY',
            'description' => 'Pendapatan tutor BimbelKu',
            'metadata' => ['payout_id' => (string) $payout->id, 'teacher_id' => (string) $teacher->id],
        ];
        $send = function (array $payload) use ($reference) {
            // A validation rejection must reach the caller so it can decide on
            // the documented fallback; retrying the same body only burns time.
            return $this->client(static fn (RequestException $exception): bool => $exception instanceof ConnectionException)
                ->withHeaders([
                    'api-version' => (string) config('xendit.payout_api_version', '2025-09-01'),
                    'idempotency-key' => $reference,
                ])
                ->post(config('xendit.base_url').'/v3/payouts', $payload);
        };
        $response = $send($body);
        if ($response->status() === 422 || $response->status() === 400) {
            // Documented fallback: only when validation complains about the
            // destination amount, send both fields with the same value.
            $errorBody = $response->json();
            $message = strtolower((string) (is_array($errorBody) ? ($errorBody['message'] ?? '') : ''));
            if (str_contains($message, 'destination_amount') || str_contains($message, 'source_amount')) {
                $body['payout_details']['source_currency'] = 'IDR';
                $body['payout_details']['source_amount'] = $amount;
                $response = $send($body);
            }
        }
        if (! $response->successful()) {
            // Only a definitive validation/auth rejection releases the reservation.
            // A timeout, conflict, rate limit or server error needs reconciliation.
            if (in_array($response->status(), [400, 401, 403, 404, 422], true)) {
                $payout->forceFill(['gateway_status' => 'FAILED'])->save();
            }
            report(new RuntimeException('Xendit payout failed: '.$response->status().' '.$response->body()));
            throw new RuntimeException('Pencairan otomatis belum dapat dibuat. Periksa saldo operasional dan rekening tutor.');
        }
        $data = $response->json();
        $payout->forceFill([
            'payment_provider' => 'xendit',
            'gateway_payout_id' => $data['payout_id'] ?? null,
            'gateway_reference_id' => $reference,
            'gateway_status' => strtoupper((string) ($data['status'] ?? 'ACCEPTED')),
            'gateway_failure_code' => null,
        ])->save();

        return $this->payoutPayload($payout->fresh());
    }

    public function retrievePayout(Payout $payout): array
    {
        $payout->refresh();
        if ($payout->status === 'completed') {
            return $this->payoutPayload($payout);
        }
        if (blank($payout->gateway_payout_id)) {
            throw new RuntimeException('Payout belum memiliki ID gateway; periksa antrean pengiriman.');
        }

        try {
            $response = $this->client()->get(config('xendit.base_url').'/v3/payouts/'.$payout->gateway_payout_id);
            if (! $response->successful()) {
                throw new RuntimeException('Status pencairan belum dapat diperiksa dari penyedia pembayaran.');
            }
            $data = $response->json();
            if (filled($data['payout_id'] ?? null) && $data['payout_id'] !== $payout->gateway_payout_id) {
                throw new RuntimeException('Identitas pencairan dari penyedia tidak cocok.');
            }

            return $this->payload(
                $this->applyGatewayStatus(
                    $payout,
                    strtoupper((string) ($data['status'] ?? '')),
                    is_array($data) ? $data : null
                )
            );
        } finally {
            DB::table('payouts')->where('id', $payout->id)->update(['gateway_last_checked_at' => now()]);
        }
    }

    /**
     * Single writer for gateway-driven payout transitions after creation.
     *
     * REVERSED on a settled payout must undo the allocation before releasing,
     * otherwise the tutor permanently loses the money while the ledger still
     * balances. `on_hold` (PENDING_COMPLIANCE) keeps the reservation locked.
     */
    public function applyGatewayStatus(Payout $payout, string $gatewayStatus, ?array $data = null): Payout
    {
        $status = strtoupper(trim($gatewayStatus));
        $result = DB::transaction(function () use ($payout, $status, $data) {
            $current = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $previousStatus = (string) $current->status;
            // `reversed` is terminal: a late failure/delivery notice must never
            // reopen it or post a second journal.
            if ($previousStatus === 'reversed' && $status !== 'REVERSED') {
                Log::info('Ignored payout transition after reversal', ['payout_id' => $current->id, 'gateway_status' => $status]);

                return $current;
            }
            // A settled payout can only move to `reversed`.
            if ($previousStatus === 'completed' && ! in_array($status, ['SUCCEEDED', 'REVERSED'], true)) {
                Log::info('Ignored payout transition after completion', ['payout_id' => $current->id, 'gateway_status' => $status]);

                return $current;
            }
            if ($previousStatus === 'completed' && $status === 'SUCCEEDED') {
                return $current;
            }
            if ($previousStatus === 'failed' && ! in_array($status, ['REVERSED'], true)) {
                return $current;
            }

            $current->forceFill([
                'gateway_payout_id' => ($data['payout_id'] ?? null) ?: $current->gateway_payout_id,
                'gateway_status' => $status,
                'gateway_failure_code' => $data['failure_code'] ?? $current->gateway_failure_code,
                'gateway_processed_at' => now(),
            ]);
            $current->status = match ($status) {
                // `reversed` is written last, after release() has moved the
                // reservation back; release() refuses terminal statuses.
                'REVERSED' => 'processing',
                'PENDING_COMPLIANCE' => 'on_hold',
                'SUCCEEDED' => 'processing',
                default => $previousStatus,
            };
            $current->save();

            if ($status === 'SUCCEEDED') {
                $this->payouts()->complete($current);
            } elseif (in_array($status, ['FAILED', 'REJECTED', 'CANCELLED', 'EXPIRED', 'REVERSED'], true)) {
                if ($status === 'REVERSED' && $previousStatus === 'completed') {
                    $this->payouts()->revertCompletedAllocation($current);
                }
                $this->payouts()->release($current, 'Penyedia pembayaran: '.($current->gateway_failure_code ?: $status));
                if ($status === 'REVERSED') {
                    // release() stores the local failure state; this final change
                    // is what makes PayoutObserver post the reversing journal.
                    $current->forceFill(['status' => 'reversed', 'gateway_status' => 'REVERSED'])->save();
                    TeacherPayoutRequest::query()->where('payout_id', $current->id)->update(['status' => 'reversed']);
                }
            } elseif ($status === 'PENDING_COMPLIANCE') {
                TeacherPayoutRequest::query()->where('payout_id', $current->id)->update(['status' => 'on_hold']);
            }

            return $current->fresh();
        }, 3);

        $this->notifyPayoutStatus($result, $status);

        return $result;
    }

    private function notifyPayoutStatus(Payout $payout, string $status): void
    {
        $amount = 'Rp'.number_format((float) $payout->amount, 0, ',', '.');
        match (true) {
            $status === 'SUCCEEDED' => Notification::firstOrCreate(['unique_key' => "payout-completed:{$payout->id}"], [
                'user_id' => $payout->user_id,
                'title' => 'Pendapatan berhasil dikirim',
                'message' => "Pencairan sebesar {$amount} berhasil dikirim ke rekening terdaftar.",
                'type' => 'success', 'target_url' => '/guru/dompet',
            ]),
            $status === 'REVERSED' => Notification::firstOrCreate(['unique_key' => "payout-reversed:{$payout->id}"], [
                'user_id' => $payout->user_id,
                'title' => 'Pencairan dikembalikan bank',
                'message' => "Dana {$amount} dikembalikan oleh bank. Perbarui rekening lalu ajukan pencairan kembali.",
                'type' => 'warning', 'target_url' => '/guru/dompet#rekening',
            ]),
            $status === 'PENDING_COMPLIANCE' => $this->notifyComplianceHold($payout, $amount),
            in_array($status, ['FAILED', 'REJECTED', 'CANCELLED', 'EXPIRED'], true) => Notification::firstOrCreate(['unique_key' => "payout-failed:{$payout->id}:{$status}"], [
                'user_id' => $payout->user_id,
                'title' => 'Pencairan belum berhasil',
                'message' => 'Dana belum terkirim dan otomatis kembali menjadi saldo tersedia. Periksa kembali rekening tujuan.',
                'type' => 'warning', 'target_url' => '/guru/dompet#rekening',
            ]),
            default => null,
        };
    }

    private function notifyComplianceHold(Payout $payout, string $amount): void
    {
        Notification::firstOrCreate(['unique_key' => "payout-on-hold:{$payout->id}"], [
            'user_id' => $payout->user_id,
            'title' => 'Pencairan menunggu pemeriksaan',
            'message' => "Pencairan {$amount} sedang diperiksa oleh penyedia pembayaran. Saldo tetap aman.",
            'type' => 'info', 'target_url' => '/guru/dompet',
        ]);
        User::query()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->pluck('id')
            ->each(fn ($adminId) => Notification::firstOrCreate(
                ['unique_key' => "payout-compliance:{$payout->id}:{$adminId}"],
                [
                    'user_id' => $adminId,
                    'title' => 'Pencairan menunggu dokumen Xendit',
                    'message' => "Pencairan tutor #{$payout->user_id} sebesar {$amount} menunggu kelengkapan dokumen di Xendit.",
                    'type' => 'warning',
                    'target_url' => '/admin/finance?tab=payouts',
                ]
            ));
    }

    private function payload(Payout $payout): array
    {
        return [
            'provider' => 'xendit',
            'payout_id' => $payout->gateway_payout_id,
            'status' => $payout->gateway_status,
            'payout_status' => $payout->status,
            'failure_code' => $payout->gateway_failure_code,
        ];
    }

    private function client(?callable $shouldRetry = null): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('Layanan pembayaran otomatis belum dikonfigurasi lengkap.');
        }

        return Http::acceptJson()->asJson()->withBasicAuth((string) config('xendit.secret_key'), '')
            ->connectTimeout(8)->timeout(25)->retry(2, 300, $shouldRetry ?? static function (RequestException $exception) {
                // Only transient failures are retried. A 4xx is a definitive
                // answer and must be surfaced (payout fallback, refund failure).
                $status = $exception->response?->status();
                if ($status !== null && $status >= 400 && $status < 500) {
                    return false;
                }

                return $exception instanceof ConnectionException;
            }, throw: false);
    }

    private function normalisePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        return str_starts_with($digits, '62') && strlen($digits) >= 10 ? '+'.$digits : null;
    }

    private function refundPayload(Refund $refund): array
    {
        return ['provider' => 'xendit', 'refund_id' => $refund->gateway_refund_id, 'status' => $refund->gateway_status];
    }

    private function payoutPayload(Payout $payout): array
    {
        return ['provider' => 'xendit', 'payout_id' => $payout->gateway_payout_id, 'status' => $payout->gateway_status];
    }
}
