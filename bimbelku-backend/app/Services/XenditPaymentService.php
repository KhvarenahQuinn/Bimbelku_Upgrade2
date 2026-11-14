<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\PaymentWindow;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class XenditPaymentService
{
    public function configured(): bool
    {
        return (bool) config('xendit.enabled')
            && filled(config('xendit.secret_key'))
            && filled(config('xendit.webhook_token'));
    }

    public function createSession(Order $order, User $customer, string $mode = 'PAYMENT_LINK', ?string $origin = null): array
    {
        return Cache::lock('gateway-order-settlement:'.$order->id, 180)->block(15,
            fn () => $this->createLockedSession($order, $customer, $mode, $origin));
    }

    private function createLockedSession(Order $order, User $customer, string $mode, ?string $origin): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Layanan pembayaran belum siap pada server.');
        }
        if (! in_array($mode, ['PAYMENT_LINK', 'COMPONENTS'], true)) {
            throw new RuntimeException('Mode pembayaran tidak dikenal.');
        }
        if ($mode === 'COMPONENTS') {
            if ($origin === null || ! in_array($origin, config('cors.allowed_origins', []), true)) {
                throw new RuntimeException('Alamat halaman pembayaran belum diizinkan. Periksa FRONTEND_ORIGINS pada server.');
            }
            if (parse_url($origin, PHP_URL_SCHEME) !== 'https') {
                if (! app()->environment(['local', 'testing'])) {
                    throw new RuntimeException('Pembayaran di dalam halaman memerlukan alamat HTTPS.');
                }
                // Xendit Components rejects HTTP origins, including localhost.
                // Keep the same invoice and use a hosted sandbox checkout locally.
                $mode = 'PAYMENT_LINK';
            }
        }

        $prepared = DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $paymentDeadline = $locked->learningPackage?->payment_due_at
                ?? $locked->cheapClassEnrollment?->seat_expires_at
                ?? $locked->created_at->copy()->addMinutes(PaymentWindow::MINUTES);
            if (! $paymentDeadline->isFuture()) {
                throw new RuntimeException('Batas pembayaran sudah berakhir. Buat pesanan baru untuk melanjutkan.');
            }
            if (! in_array($locked->status, ['pending', 'rejected', 'partially_paid'], true)) {
                throw new RuntimeException('Tagihan ini sudah tidak dapat dibayar.');
            }
            // A session in a terminal state cannot be resumed. Fall through so
            // the identity is cleared and the reference is rotated before a
            // fresh provider session is requested.
            $terminalSession = in_array($locked->gateway_status, ['EXPIRED', 'CANCELED', 'CANCELLED', 'FAILED'], true);
            if (filled($locked->gateway_session_id) && ! $terminalSession) {
                return ['existing' => true, 'order' => $locked, 'reference' => $locked->gateway_reference_id];
            }
            if ($locked->gateway_creation_uncertain) {
                throw new RuntimeException('Status checkout sebelumnya belum dapat dipastikan. Checkout baru ditahan untuk mencegah pembayaran ganda. Periksa status pembayaran atau hubungi bantuan.');
            }
            if ($this->externalDue($locked) <= 0.009) {
                throw new RuntimeException('Tagihan ini tidak memiliki sisa pembayaran eksternal.');
            }

            $sessionDeadline = $locked->gateway_status === 'CREATING' && $locked->gateway_expires_at
                ? $locked->gateway_expires_at->copy()->min($paymentDeadline)
                : now()->addMinutes(PaymentWindow::MINUTES)->min($paymentDeadline);
            // The provider requires at least ten minutes for a new session.
            // Never extend an invoice just to satisfy that requirement.
            if ($sessionDeadline->lt(now()->addMinutes(10))) {
                throw new RuntimeException('Sisa waktu pembayaran kurang dari 10 menit sehingga checkout baru tidak dapat dibuka. Checkout yang sudah aktif tetap dapat digunakan sampai batas waktunya.');
            }

            // Persist the reference before the remote request for callback recovery.
            // Only a definitive validation/auth rejection is safe to retry.
            // Rotate its reference so a corrected retry cannot collide with the
            // provider's old idempotency/customer reference. Ambiguous in-flight
            // requests are held until provider evidence supplies a session ID.
            //
            // Pay with the LOCAL transaction code (PKG-.../INV-KM-...) as the
            // reference_id: it is the value visible in the Xendit dashboard
            // 'Reference' filter, not just a description line. Rotation after
            // a definitive rejection appends a random suffix so retries still
            // do not collide with the provider's previous reference.
            $localReference = filled($locked->order_id) ? (string) $locked->order_id : 'BKU-'.$locked->id;
            $rejectedWithoutSession = $locked->gateway_status === 'SESSION_FAILED'
                && ! $locked->gateway_creation_uncertain
                && blank($locked->gateway_session_id);
            // A session already declared expired/cancelled cannot be resumed.
            // Clear its identity and rotate the reference so a fresh provider
            // session can be created instead of colliding with the old one.
            $terminalWithoutLiveSession = $terminalSession;
            $reference = $rejectedWithoutSession || $terminalWithoutLiveSession
                ? $localReference.'-'.Str::upper(Str::random(6))
                : (filled($locked->gateway_reference_id) ? $locked->gateway_reference_id : $localReference);
            $locked->forceFill([
                'payment_provider' => (float) $locked->wallet_reserved_amount > 0 ? 'wallet_plus_xendit' : 'xendit',
                'gateway_reference_id' => $reference,
                'gateway_session_id' => $terminalWithoutLiveSession ? null : $locked->gateway_session_id,
                'gateway_checkout_url' => $terminalWithoutLiveSession ? null : $locked->gateway_checkout_url,
                'gateway_status' => 'CREATING',
                'gateway_expires_at' => $sessionDeadline,
                'gateway_expected_amount' => $this->externalDue($locked),
            ])->save();

            return ['existing' => false, 'order' => $locked->fresh(), 'reference' => $reference, 'expires_at' => $sessionDeadline];
        }, 3);

        if ($prepared['existing']) {
            $existing = $prepared['order'];
            if ($existing->gateway_status === 'ACTIVE' && $existing->gateway_expires_at?->isFuture() && filled($existing->gateway_checkout_url)) {
                return $this->sessionPayload($existing);
            }
            $session = $this->fetchSession($existing);
            $status = strtoupper((string) ($session['status'] ?? ''));
            if ($status !== 'ACTIVE') {
                throw new RuntimeException('Checkout sebelumnya sudah tidak aktif. Periksa status pembayaran; checkout baru tidak diterbitkan untuk tagihan ini.');
            }
            $link = $session['payment_link_url'] ?? $session['payment_link'] ?? null;
            if (is_string($link) && $link !== '') {
                $existing->forceFill(['gateway_checkout_url' => $link])->save();

                return $this->sessionPayload($existing->fresh());
            }
            $sdkKey = $session['components_sdk_key'] ?? null;
            if (! is_string($sdkKey) || $sdkKey === '') {
                throw new RuntimeException('Checkout yang sudah aktif belum dapat dimuat ulang. Periksa status pembayaran sebelum mencoba lagi.');
            }

            return $this->sessionPayload($existing, $sdkKey);
        }
        /** @var Order $locked */
        $locked = $prepared['order'];
        $reference = $prepared['reference'];
        $names = preg_split('/\s+/', trim((string) $customer->name), 2) ?: ['Murid BimbelKu'];
        $phone = $this->normalisePhone((string) ($customer->phone ?? ''));
        $individual = ['given_names' => $names[0] ?: 'Murid'];
        if (! empty($names[1])) {
            $individual['surname'] = $names[1];
        }
        $customerPayload = [
            'reference_id' => 'USER-'.$customer->id.'-'.$reference,
            'type' => 'INDIVIDUAL',
            'email' => $customer->email,
            'individual_detail' => $individual,
        ];
        if ($phone !== null) {
            $customerPayload['mobile_number'] = $phone;
        }

        $sessionRequest = [
            'reference_id' => $reference,
            'session_type' => 'PAY',
            'mode' => $mode,
            'expires_at' => $prepared['expires_at']->toIso8601String(),
            'amount' => (int) round($this->externalDue($locked)),
            'currency' => config('xendit.currency', 'IDR'),
            'country' => config('xendit.country', 'ID'),
            'customer' => $customerPayload,
            'description' => 'Pembayaran '.$locked->order_id.' BimbelKu',
            'metadata' => ['order_id' => (string) $locked->id, 'order_number' => $locked->order_id, 'user_id' => (string) $customer->id],
        ];
        if ($mode === 'COMPONENTS') {
            $componentsConfiguration = ['origins' => [$origin]];
            if (parse_url($origin, PHP_URL_SCHEME) === 'https') {
                $componentsConfiguration['return_url'] = $this->bindReturnUrl(rtrim($origin, '/').'/payment?checkout=resume', $locked);
            }
            $sessionRequest['components_configuration'] = $componentsConfiguration;
        } else {
            $sessionRequest = [...$sessionRequest, ...$this->returnUrls($locked)];
        }

        // Persist uncertainty BEFORE sending. The Session API does not document
        // a create-session idempotency guarantee. Never auto-retry a POST after
        // an ambiguous response, even with the same header/reference.
        $locked->forceFill(['gateway_creation_uncertain' => true])->save();
        try {
            $response = $this->client()->retry(1, 0, throw: false)
                ->withHeaders(['Idempotency-key' => 'xendit-session-'.$reference])
                ->post(config('xendit.base_url').'/sessions', $sessionRequest);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Koneksi checkout terputus dan statusnya belum pasti. Pembuatan ulang ditahan untuk mencegah pembayaran ganda. Periksa status pembayaran atau hubungi bantuan.', 0, $exception);
        }
        if (! $response->successful()) {
            $definitiveRejection = in_array($response->status(), [401, 403, 404], true)
                || (in_array($response->status(), [400, 422], true) && in_array($response->json('error_code'), [
                    'API_VALIDATION_ERROR', 'INVALID_AMOUNT', 'INVALID_PAYMENT_CHANNEL', 'INVALID_PRIMARY_PAYMENT_CHANNEL',
                    'INVALID_METADATA', 'INVALID_ITEM_METADATA', 'INVALID_CURRENCY', 'INVALID_COUNTRY',
                    'INVALID_CUSTOMER_ID', 'INVALID_URL', 'MISSING_CUSTOMER', 'DISALLOWED_CAPTURE_METHOD',
                    'INVALID_EXPIRY_DATE', 'ITEMS_NOT_SUPPORTED', 'SESSION_TYPE_NOT_SUPPORTED', 'MODE_NOT_SUPPORTED',
                ], true));
            $locked->forceFill([
                'gateway_status' => $definitiveRejection ? 'SESSION_FAILED' : 'CREATING',
                'gateway_creation_uncertain' => ! $definitiveRejection,
            ])->save();
            report(new RuntimeException('Payment session failed: '.$response->status().' '.$response->body()));
            throw new RuntimeException($this->sessionFailureMessage(
                $response->status(),
                (string) $response->json('error_code', ''),
                (string) $response->json('message', '')
            ));
        }

        $data = is_array($response->json()) ? $response->json() : [];
        $sessionId = $data['payment_session_id'] ?? $data['id'] ?? null;
        $checkoutUrl = $data['payment_link_url'] ?? $data['payment_link'] ?? null;
        $sdkKey = $data['components_sdk_key'] ?? null;
        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Komponen pembayaran belum tersedia. Periksa status tagihan sebelum mencoba lagi.');
        }
        $expiresAt = isset($data['expires_at'])
            ? Carbon::parse($data['expires_at'])->min($prepared['expires_at'])
            : $prepared['expires_at'];

        $saved = DB::transaction(function () use ($locked, $reference, $sessionId, $checkoutUrl, $data, $expiresAt) {
            $current = Order::query()->lockForUpdate()->findOrFail($locked->id);
            if ($current->gateway_reference_id !== $reference) {
                throw new RuntimeException('Referensi pembayaran berubah. Muat ulang tagihan.');
            }
            $current->forceFill([
                'gateway_session_id' => $sessionId,
                'gateway_checkout_url' => $checkoutUrl,
                'gateway_status' => strtoupper((string) ($data['status'] ?? 'ACTIVE')),
                'gateway_expires_at' => $expiresAt,
                'gateway_creation_uncertain' => false,
            ])->save();
            // Some account configurations already return the payment request
            // id at session creation; capture it because Xendit does not
            // document that field as optional/conditional.
            $requestId = data_get($data, 'payment_request_id') ?? data_get($data, 'payment_request.id');
            if (filled($requestId) && blank($current->xendit_payment_request_id)) {
                $current->forceFill(['xendit_payment_request_id' => (string) $requestId])->save();
            }

            return $current->fresh();
        }, 3);

        if (($mode === 'PAYMENT_LINK' && (! is_string($checkoutUrl) || $checkoutUrl === ''))
            || ($mode === 'COMPONENTS' && (! is_string($sdkKey) || $sdkKey === ''))) {
            throw new RuntimeException('Checkout sudah dibuat tetapi belum dapat ditampilkan. Muat ulang untuk mengambil checkout yang sama.');
        }

        return $this->sessionPayload($saved, is_string($sdkKey) ? $sdkKey : null);
    }

    public function fetchSession(Order $order): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Pembayaran otomatis belum dikonfigurasi pada server.');
        }
        if (blank($order->gateway_session_id)) {
            throw new RuntimeException('Session pembayaran belum tersedia.');
        }

        $response = $this->client()->get(
            config('xendit.base_url').'/sessions/'.rawurlencode((string) $order->gateway_session_id)
        );

        if (! $response->successful()) {
            report(new RuntimeException('Xendit get session failed: '.$response->status().' '.$response->body()));
            throw new RuntimeException('Status pembayaran belum dapat diperiksa. Coba kembali beberapa saat lagi.');
        }

        $data = $response->json();
        if (! is_array($data) || blank($data['status'] ?? null)) {
            throw new RuntimeException('Respons status pembayaran tidak valid.');
        }

        return $data;
    }

    /** Update lifecycle only for the current session, never downgrade a receipt. */
    public function recordSessionStatus(Order $order, string $status, array $data = [], bool $terminalFailure = false): bool
    {
        return Cache::lock('gateway-order-settlement:'.$order->id, 180)->block(15, function () use ($order, $status, $data, $terminalFailure) {
            return DB::transaction(function () use ($order, $status, $data, $terminalFailure) {
                $current = Order::query()->lockForUpdate()->findOrFail($order->id);
                $sessionId = $data['payment_session_id'] ?? $data['session_id'] ?? data_get($data, 'payment_session.id') ?? $order->gateway_session_id;
                $reference = $data['reference_id'] ?? data_get($data, 'payment_session.reference_id') ?? $order->gateway_reference_id;
                if ((filled($sessionId) && filled($current->gateway_session_id) && $sessionId !== $current->gateway_session_id)
                    || (filled($reference) && $reference !== $current->gateway_reference_id)) {
                    return false;
                }
                if ($current->gateway_paid_at || $current->gateway_status === 'COMPLETED'
                    || in_array($current->status, ['paid', 'refund_pending', 'refunded'], true)) {
                    return false;
                }
                // The Session API documents only ACTIVE, COMPLETED, EXPIRED and
                // CANCELED. `CANCELLED` and `FAILED` are tolerated as aliases so
                // older rows and payment-level notifications are not rejected.
                $terminalStatuses = ['ACTIVE', 'EXPIRED', 'CANCELED', 'CANCELLED'];
                if ($terminalFailure) {
                    $terminalStatuses[] = 'FAILED';
                }
                if (! in_array($status, $terminalStatuses, true)) {
                    return false;
                }
                if (in_array($current->gateway_status, ['EXPIRED', 'CANCELED', 'CANCELLED', 'FAILED'], true) && $current->gateway_status !== $status) {
                    return false;
                }
                $updates = ['gateway_status' => $status];
                if (filled($sessionId)) {
                    $updates['gateway_session_id'] = $sessionId;
                    $updates['gateway_creation_uncertain'] = false;
                }
                $current->forceFill($updates)->save();

                // Session expiry/cancellation/failure is terminal for this
                // attempt and must not leave a wallet hold stranded. The balance
                // returns to spendable funds immediately; a retry may reserve again.
                $releasesHold = in_array($status, ['EXPIRED', 'CANCELED', 'CANCELLED'], true)
                    || ($terminalFailure && $status === 'FAILED');
                if ($releasesHold) {
                    app(CustomerWalletService::class)->releaseReserved($current, null, 'gateway_status:'.$status);
                }

                return true;
            }, 3);
        });
    }

    /** Close the remote checkout before allowing any local cancellation. */
    public function cancelSession(Order $order): ?array
    {
        try {
            return $this->cancelRemoteSession($order);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Koneksi pembayaran belum dapat diperiksa. Pesanan belum dibatalkan; coba kembali beberapa saat lagi.', 0, $exception);
        }
    }

    private function cancelRemoteSession(Order $order): ?array
    {
        if (blank($order->gateway_session_id)) {
            // A definitive rejection created no session to close. A saved
            // reference alone must not trap this order in the uncertainty flow.
            if ($order->gateway_status === 'SESSION_FAILED' && ! $order->gateway_creation_uncertain) {
                return null;
            }
            if ($order->gateway_status === 'CREATING' || filled($order->gateway_reference_id)) {
                throw new RuntimeException('Checkout masih sedang dibuat. Periksa status pembayaran sebelum membatalkan pesanan.');
            }

            return null;
        }
        $session = $this->fetchSession($order);
        $status = strtoupper((string) ($session['status'] ?? ''));
        if ($status === 'ACTIVE') {
            $response = $this->client()->post(config('xendit.base_url').'/sessions/'.rawurlencode($order->gateway_session_id).'/cancel');
            // A cancellation may lose a race with a successful payment, or its
            // response may be ambiguous. Check the authoritative session again.
            $session = $response->successful() && is_array($response->json())
                ? $response->json() : $this->fetchSession($order);
            $status = strtoupper((string) ($session['status'] ?? ''));
        }
        if (in_array($status, ['COMPLETED', 'SUCCEEDED', 'PAID', 'CAPTURED'], true)) {
            return $session;
        }
        if (! in_array($status, ['CANCELED', 'CANCELLED', 'EXPIRED'], true)) {
            throw new RuntimeException('Checkout belum berhasil ditutup. Pesanan belum dibatalkan; coba kembali setelah status pembayaran dapat diperiksa.');
        }
        // Do not overwrite a receipt accepted by another worker.
        Order::query()->whereKey($order->id)->whereIn('status', ['pending', 'rejected', 'partially_paid', 'expired', 'cancelled'])
            ->update(['gateway_status' => $status]);

        return null;
    }

    private function bindReturnUrl(string $url, Order $order): string
    {
        // Replace a stale configured ID, preserving existing query parameters and fragments.
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$path, $query] = array_pad(explode('?', $base, 2), 2, '');
        parse_str($query, $parameters);
        $parameters['order_id'] = (string) $order->id;

        return $path.'?'.http_build_query($parameters).($fragment !== null ? '#'.$fragment : '');
    }

    private function returnUrls(Order $order): array
    {
        $urls = [];
        foreach (['success_return_url' => 'success_url', 'cancel_return_url' => 'cancel_url'] as $field => $key) {
            $url = (string) config('xendit.'.$key);
            if (filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https') {
                $urls[$field] = $this->bindReturnUrl($url, $order);

                continue;
            }
            // Optional return URLs cannot point to HTTP localhost. Payment status
            // is verified server-side when the customer returns to the application.
            if (! app()->environment(['local', 'testing'])) {
                throw new RuntimeException('Alamat kembali pembayaran harus menggunakan HTTPS. Hubungi pengelola BimbelKu.');
            }
        }

        return $urls;
    }

    private function sessionFailureMessage(int $status, string $errorCode = '', string $providerMessage = ''): string
    {
        if ($errorCode === 'INVALID_URL') {
            return 'Alamat kembali pembayaran belum dikonfigurasi dengan HTTPS. Hubungi pengelola BimbelKu.';
        }
        if ($errorCode === 'API_VALIDATION_ERROR' && str_contains($providerMessage, 'components_configuration.origins')) {
            return 'Pembayaran di dalam halaman memerlukan HTTPS. Pada localhost, muat ulang dan gunakan checkout aman.';
        }
        if ($errorCode === 'DUPLICATE_ERROR') {
            return 'Referensi checkout sebelumnya sudah digunakan. Checkout baru ditahan sampai status transaksi sebelumnya dapat dipastikan. Hubungi bantuan jika status tidak berubah.';
        }

        return match (true) {
            in_array($status, [401, 403], true) => 'Layanan pembayaran belum terhubung dengan benar. Hubungi pengelola BimbelKu.',
            in_array($status, [400, 409, 422], true) => 'Pilihan pembayaran belum dapat dibuat dari data tagihan ini. Coba lagi atau hubungi bantuan jika masih gagal.',
            $status === 429 => 'Layanan pembayaran sedang sibuk. Status checkout belum pasti; pembuatan ulang ditahan agar tidak ganda. Periksa status pembayaran atau hubungi bantuan.',
            $status >= 500 => 'Jaringan pembayaran sedang mengalami gangguan. Status checkout belum pasti; pembuatan ulang ditahan agar tidak ganda. Periksa status pembayaran atau hubungi bantuan.',
            default => 'Halaman pembayaran belum dapat dibuat. Periksa status tagihan atau hubungi bantuan sebelum mencoba lagi.',
        };
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withBasicAuth((string) config('xendit.secret_key'), '')
            ->connectTimeout(8)
            ->timeout(20)
            ->retry(2, 250, throw: false);
    }

    private function sessionPayload(Order $order, ?string $sdkKey = null): array
    {
        return [
            'provider' => 'xendit',
            'mode' => filled($order->gateway_checkout_url) ? 'PAYMENT_LINK' : 'COMPONENTS',
            'session_id' => $order->gateway_session_id,
            'checkout_url' => $order->gateway_checkout_url,
            'components_sdk_key' => $sdkKey,
            'status' => $order->gateway_status,
            'expires_at' => $order->gateway_expires_at,
            'amount' => $this->externalDue($order),
        ];
    }

    public function externalDue(Order $order): float
    {
        $reserved = max(0, (float) $order->wallet_reserved_amount);
        $received = max(0, (float) $order->external_received_amount);
        $storedOutstanding = max(0, (float) $order->payment_outstanding_amount);
        if ($order->status === 'partially_paid' && $storedOutstanding > 0.009) {
            return round($storedOutstanding, 2);
        }

        return round(max(0, (float) $order->amount - $reserved - $received), 2);
    }

    private function normalisePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }
        if (! str_starts_with($digits, '62') || strlen($digits) < 10 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }
}
