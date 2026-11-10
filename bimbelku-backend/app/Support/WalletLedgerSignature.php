<?php

namespace App\Support;

use App\Models\CustomerWalletTransaction;
use Illuminate\Support\Str;

/**
 * HMAC-SHA256 signature for the append-only customer wallet ledger.
 *
 * Every row is signed with APP_KEY so a value injected directly through the
 * database (bypassing CustomerWalletService) fails verification. The signature
 * covers the wallet/user/order/refund identity, event, type, direction, money
 * movements, description, provider reference, and metadata.
 */
final class WalletLedgerSignature
{
    /** @param array<string, mixed> $attributes */
    public function sign(array $attributes): string
    {
        $payload = [
            'reference_type' => (string) ($attributes['reference_type'] ?? ''),
            'reference_id' => (string) ($attributes['reference_id'] ?? ''),
            'wallet_id' => (string) ($attributes['customer_wallet_id'] ?? ''),
            'user_id' => (string) ($attributes['user_id'] ?? ''),
            'refund_id' => $this->nullableString($attributes['refund_id'] ?? null),
            'order_id' => $this->nullableString($attributes['order_id'] ?? null),
            'actor_id' => $this->nullableString($attributes['actor_id'] ?? null),
            'event_key' => (string) ($attributes['event_key'] ?? ''),
            'type' => (string) ($attributes['type'] ?? ''),
            'direction' => (string) ($attributes['direction'] ?? ''),
            'amount' => $this->money($attributes['amount'] ?? 0),
            'balance_before' => $this->money($attributes['balance_before'] ?? 0),
            'balance_after' => $this->money($attributes['balance_after'] ?? 0),
            'description' => (string) ($attributes['description'] ?? ''),
            'metadata' => $this->canonicalMetadata($attributes['metadata'] ?? null),
        ];

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->key()
        );
    }

    public function verify(CustomerWalletTransaction $transaction): bool
    {
        $checksum = (string) ($transaction->getAttributes()['checksum'] ?? '');
        if ($checksum === '') {
            return false;
        }

        return hash_equals($checksum, $this->sign($transaction->getAttributes()));
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{0:string,1:string}
     */
    public function referenceFor(array $attributes): array
    {
        if (filled($attributes['refund_id'] ?? null)) {
            return ['refund', (string) $attributes['refund_id']];
        }

        $type = (string) ($attributes['type'] ?? 'wallet_event');
        $metadata = $this->metadataArray($attributes['metadata'] ?? null);
        $orderId = (string) ($attributes['order_id'] ?? '');

        if (in_array($type, ['payment_reserve', 'payment_release', 'payment_capture'], true)) {
            $version = (string) ($metadata['reservation_version'] ?? '1');

            return [$type, ($orderId !== '' ? $orderId : 'wallet-'.$attributes['customer_wallet_id']).':'.$version];
        }

        if (in_array($type, ['overpayment_credit', 'expired_partial_credit'], true)) {
            $reference = $metadata['gateway_reference_id']
                ?? $metadata['xendit_invoice_id']
                ?? ($orderId !== '' ? $orderId : null);

            return [$type, (string) ($reference ?? $attributes['event_key'] ?? Str::uuid())];
        }

        return [$type, (string) ($attributes['event_key'] ?? Str::uuid())];
    }

    private function key(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY belum dikonfigurasi untuk menandatangani ledger wallet.');
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    private function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @return array<string, mixed> */
    private function metadataArray(mixed $metadata): array
    {
        if (is_string($metadata)) {
            if ($metadata === '') {
                return [];
            }
            $decoded = json_decode($metadata, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return is_array($metadata) ? $metadata : [];
    }

    /** @return array<string, mixed> */
    private function canonicalMetadata(mixed $metadata): array
    {
        return $this->canonical($this->metadataArray($metadata));
    }

    /** @param array<string, mixed> $value */
    private function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
