<?php

namespace App\Services;

use App\Models\PaymentGatewayEvent;
use App\Models\Payout;
use App\Models\Refund;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class XenditWebhookService
{
    public function __construct(
        private readonly RefundSettlementService $refunds,
        private readonly TeacherPayoutService $payouts,
        private readonly XenditMoneyMovementService $gateways,
    ) {}

    public function handle(string $eventType, string $eventId, array $payload, array $data): void
    {
        if (str_starts_with($eventType, 'refund.')) {
            DB::transaction(fn () => $this->handleRefund($eventType, $eventId, $payload, $data), 3);

            return;
        }
        if (str_starts_with($eventType, 'v3_payout.')) {
            DB::transaction(fn () => $this->handlePayout($eventType, $eventId, $payload, $data), 3);

            return;
        }
        abort(422, 'Jenis notifikasi pembayaran tidak didukung oleh handler arus dana.');
    }

    private function handleRefund(string $eventType, string $eventId, array $payload, array $data): void
    {
        $id = Arr::get($data, 'id');
        $reference = Arr::get($data, 'reference_id');
        $valid = $this->validIdentity($id) && $this->validIdentity($reference) && (filled($id) || filled($reference));
        $refund = $valid ? Refund::query()
            ->when($reference, fn ($q) => $q->where('gateway_reference_id', $reference))
            ->when($id, fn ($q) => $q->where(function ($ids) use ($id, $reference) {
                $ids->where('gateway_refund_id', $id);
                if ($reference) {
                    $ids->orWhereNull('gateway_refund_id');
                }
            }))
            ->lockForUpdate()->first() : null;
        $event = $this->event($eventId, $eventType, $payload, ['refund_id' => $refund?->id]);
        if (! $event->wasRecentlyCreated && $event->status === 'processed') {
            return;
        }
        if (! $refund) {
            $event->update(['status' => 'ignored', 'error' => 'Refund tidak ditemukan', 'processed_at' => now()]);

            return;
        }

        $incomingStatus = strtoupper((string) (Arr::get($data, 'status') ?: Str::afterLast($eventType, '.')));
        if ($refund->status === 'paid' || ($refund->gateway_status === 'SUCCEEDED' && $incomingStatus !== 'SUCCEEDED')) {
            $event->update(['status' => 'ignored', 'error' => 'Refund berhasil tidak boleh ditimpa notifikasi lama.', 'processed_at' => now()]);

            return;
        }

        try {
            $status = strtoupper((string) (Arr::get($data, 'status') ?: Str::afterLast($eventType, '.')));
            $refund->forceFill([
                'payment_provider' => 'xendit',
                'gateway_refund_id' => Arr::get($data, 'id') ?: $refund->gateway_refund_id,
                'gateway_status' => $status,
                'gateway_failure_code' => Arr::get($data, 'failure_code'),
                'gateway_failure_message' => Arr::get($data, 'failure_message') ?: Arr::get($data, 'message'),
                'gateway_processed_at' => now(),
            ])->save();
            if ($eventType === 'refund.succeeded' || $status === 'SUCCEEDED') {
                $this->refunds->settle($refund->fresh(), 'xendit_original');
            } elseif (in_array($status, ['FAILED', 'REJECTED'], true)) {
                $this->refunds->markGatewayFailure($refund->fresh());
            }
            $event->update(['status' => 'processed', 'error' => null, 'processed_at' => now()]);
        } catch (Throwable $exception) {
            $event->update(['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 1500)]);
            throw $exception;
        }
    }

    private function handlePayout(string $eventType, string $eventId, array $payload, array $data): void
    {
        $payoutId = Arr::get($data, 'payout_id');
        $reference = Arr::get($data, 'reference_id');
        $valid = $this->validIdentity($payoutId) && $this->validIdentity($reference) && (filled($payoutId) || filled($reference));
        $payout = $valid ? Payout::query()
            ->when($reference, fn ($q) => $q->where('gateway_reference_id', $reference))
            ->when($payoutId, fn ($q) => $q->where(function ($ids) use ($payoutId, $reference) {
                $ids->where('gateway_payout_id', $payoutId);
                if ($reference) {
                    $ids->orWhereNull('gateway_payout_id');
                }
            }))->lockForUpdate()->first() : null;
        $event = $this->event($eventId, $eventType, $payload, ['payout_id' => $payout?->id]);
        if (! $event->wasRecentlyCreated && $event->status === 'processed') {
            return;
        }
        if (! $payout) {
            $event->update(['status' => 'ignored', 'error' => 'Payout tidak ditemukan', 'processed_at' => now()]);

            return;
        }

        // A settled payout is not final for the bank: it can still be REVERSED
        // after the money was sent. Only `failed` and `reversed` are terminal
        // here; a completed payout must still reach applyGatewayStatus().
        $incomingStatus = strtoupper((string) (Arr::get($data, 'status') ?: Str::afterLast($eventType, '.')));
        $needsReversal = $payout->status === 'completed' && $incomingStatus === 'REVERSED';
        if (! $needsReversal && in_array($payout->status, ['completed', 'failed', 'reversed'], true)) {
            $event->update(['status' => 'processed', 'processed_at' => now()]);

            return;
        }
        try {
            $status = $incomingStatus;
            // All state transitions, allocations, journals and notifications
            // for gateway-driven payout events live in applyGatewayStatus().
            $this->gateways->applyGatewayStatus($payout, $status, $data);
            $event->update(['status' => 'processed', 'error' => null, 'processed_at' => now()]);
        } catch (Throwable $exception) {
            $event->update(['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 1500)]);
            throw $exception;
        }
    }

    private function event(string $eventId, string $eventType, array $payload, array $links): PaymentGatewayEvent
    {
        return PaymentGatewayEvent::firstOrCreate(['event_id' => 'xendit:'.$eventId], array_merge([
            'provider' => 'xendit', 'event_type' => $eventType, 'payload' => $payload, 'status' => 'received',
        ], $links));
    }

    private function validIdentity(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) !== '' && strlen($value) <= 128);
    }
}
