<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\Refund;
use App\Models\User;
use App\Support\XenditPayoutChannelCatalog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class XenditMoneyMovementService
{
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
        if (filled($payout->gateway_payout_id) && ! in_array($payout->gateway_status, ['FAILED', 'REJECTED', 'REVERSED'], true)) {
            return $this->payoutPayload($payout);
        }

        $reference = $payout->gateway_reference_id ?: 'BKU-PAY-'.$payout->id;
        $payout->forceFill(['gateway_reference_id' => $reference, 'gateway_status' => 'SUBMITTING'])->save();
        $names = preg_split('/\s+/', trim($teacher->name), 2) ?: ['Tutor'];
        $response = $this->client()
            ->withHeaders([
                'api-version' => (string) config('xendit.payout_api_version', '2025-09-01'),
                'idempotency-key' => $reference,
            ])
            ->post(config('xendit.base_url').'/v3/payouts', [
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
                        'street_line_1' => $teacher->address ?: 'Alamat tutor terverifikasi BimbelKu',
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
                    'source_currency' => 'IDR',
                    'source_amount' => (int) round((float) $payout->amount),
                    'destination_currency' => 'IDR',
                ],
                'source_of_fund' => 'BUSINESS_REVENUE',
                'purpose_code' => 'SALARY',
                'description' => 'Pendapatan tutor BimbelKu',
                'receipt_notification' => ['email_to' => [$teacher->email]],
                'metadata' => ['payout_id' => (string) $payout->id, 'teacher_id' => (string) $teacher->id],
            ]);
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

    private function client(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('Layanan pembayaran otomatis belum dikonfigurasi lengkap.');
        }

        return Http::acceptJson()->asJson()->withBasicAuth((string) config('xendit.secret_key'), '')
            ->connectTimeout(8)->timeout(25)->retry(2, 300, throw: false);
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
