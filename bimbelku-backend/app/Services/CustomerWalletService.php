<?php

namespace App\Services;

use App\Models\CustomerWallet;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CustomerWalletService
{
    public function creditOverpayment(Order $order, float $amount, ?int $actorId = null): CustomerWalletTransaction
    {
        $amount = $this->money($amount);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal kelebihan pembayaran harus lebih besar dari nol.');
        }
        $eventKey = "order:{$order->id}:overpayment_credit";
        $existing = CustomerWalletTransaction::query()->where('event_key', $eventKey)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $amount, $actorId, $eventKey) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_if($amount > (float) $lockedOrder->payment_surplus_amount + 0.009, 422, 'Nominal kelebihan pembayaran tidak valid.');
            User::query()->whereKey($lockedOrder->user_id)->lockForUpdate()->firstOrFail();
            $wallet = $this->lockedWalletForUser((int) $lockedOrder->user_id);
            $this->assertMutationBaseline($wallet);
            $before = $this->money($wallet->current_balance);
            $after = $this->money($before + $amount);
            $this->saveWallet($wallet, $after, $this->money($wallet->reserved_balance));

            return $this->createTransaction([
                'customer_wallet_id' => $wallet->id,
                'user_id' => $lockedOrder->user_id,
                'refund_id' => null,
                'order_id' => $lockedOrder->id,
                'actor_id' => $actorId,
                'event_key' => $eventKey,
                'type' => 'overpayment_credit',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'description' => "Kelebihan pembayaran {$lockedOrder->order_id} masuk ke Saldo BimbelKu",
                'metadata' => [
                    'source' => 'external_transfer',
                    'gateway_reference_id' => $lockedOrder->gateway_reference_id ?: $lockedOrder->order_id,
                ],
                'created_at' => now(),
            ]);
        }, 3);
    }

    public function creditExpiredPartialPayment(Order $order, float $amount): CustomerWalletTransaction
    {
        $amount = $this->money($amount);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal pembayaran parsial harus lebih besar dari nol.');
        }
        $eventKey = "order:{$order->id}:expired_partial_credit";
        $existing = CustomerWalletTransaction::query()->where('event_key', $eventKey)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $amount, $eventKey) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            User::query()->whereKey($lockedOrder->user_id)->lockForUpdate()->firstOrFail();
            $wallet = $this->lockedWalletForUser((int) $lockedOrder->user_id);
            $this->assertMutationBaseline($wallet);
            $before = $this->money($wallet->current_balance);
            $after = $this->money($before + $amount);
            $this->saveWallet($wallet, $after, $this->money($wallet->reserved_balance));

            return $this->createTransaction([
                'customer_wallet_id' => $wallet->id,
                'user_id' => $lockedOrder->user_id,
                'refund_id' => null,
                'order_id' => $lockedOrder->id,
                'actor_id' => null,
                'event_key' => $eventKey,
                'type' => 'expired_partial_credit',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'description' => "Dana parsial {$lockedOrder->order_id} dikembalikan ke Saldo BimbelKu",
                'metadata' => [
                    'reason' => 'top_up_expired',
                    'source' => 'external_transfer',
                    'gateway_reference_id' => $lockedOrder->gateway_reference_id ?: $lockedOrder->order_id,
                ],
                'created_at' => now(),
            ]);
        }, 3);
    }

    public function creditRefund(Refund $refund, ?int $actorId = null, ?float $creditAmount = null): CustomerWalletTransaction
    {
        $eventKey = "refund:{$refund->id}:wallet_credit";
        $existing = CustomerWalletTransaction::query()->where('event_key', $eventKey)->first();
        if ($existing) {
            return $existing;
        }

        if ((float) $refund->amount <= 0) {
            throw new InvalidArgumentException('Nominal refund harus lebih besar dari nol.');
        }
        if ($creditAmount !== null && (float) $creditAmount <= 0) {
            throw new InvalidArgumentException('Nominal kredit refund harus lebih besar dari nol.');
        }

        try {
            return DB::transaction(function () use ($refund, $actorId, $eventKey, $creditAmount) {
                $lockedRefund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
                if (! in_array($lockedRefund->status, ['pending', 'failed'], true)) {
                    $existing = CustomerWalletTransaction::query()->where('event_key', $eventKey)->first();
                    if ($existing) {
                        return $existing;
                    }
                    abort(422, 'Refund ini sudah diproses.');
                }

                User::query()->whereKey($lockedRefund->user_id)->lockForUpdate()->firstOrFail();
                $wallet = $this->lockedWalletForUser((int) $lockedRefund->user_id);
                $this->assertMutationBaseline($wallet);

                $before = $this->money($wallet->current_balance);
                $refundTotal = $this->money($lockedRefund->amount);
                $amount = $creditAmount === null ? $refundTotal : $this->money($creditAmount);
                if ($amount <= 0 || $amount > $refundTotal + 0.009) {
                    throw new InvalidArgumentException('Nominal kredit refund tidak valid.');
                }
                $after = $this->money($before + $amount);

                $this->saveWallet($wallet, $after, $this->money($wallet->reserved_balance));

                return $this->createTransaction([
                    'customer_wallet_id' => $wallet->id,
                    'user_id' => $lockedRefund->user_id,
                    'refund_id' => $lockedRefund->id,
                    'order_id' => $lockedRefund->order_id,
                    'actor_id' => $actorId,
                    'event_key' => $eventKey,
                    'type' => 'refund_credit',
                    'direction' => 'credit',
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'description' => "Refund pesanan {$lockedRefund->order_id} masuk ke Saldo BimbelKu",
                    'metadata' => [
                        'order_id' => $lockedRefund->order_id,
                        'reason' => $lockedRefund->reason,
                        'refund_total_amount' => $refundTotal,
                        'credited_amount' => $amount,
                    ],
                    'created_at' => now(),
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $existing = CustomerWalletTransaction::query()->where('event_key', $eventKey)->first();
            if ($existing) {
                return $existing;
            }
            throw $exception;
        }
    }

    /**
     * @return array{supported:bool,balance:float,reserved_balance:float,usable_amount:float,external_due:float,currency:string}
     */
    public function quoteForOrder(Order $order, int $userId): array
    {
        abort_unless((int) $order->user_id === $userId, 403);
        $wallet = CustomerWallet::query()->where('user_id', $userId)->first();
        $balance = $this->money($wallet?->current_balance ?? 0);
        $supported = $this->supportsOrder($order);
        $isTopUp = $order->status === 'partially_paid';
        $usable = $isTopUp
            ? $this->money($order->wallet_reserved_amount)
            : ($supported ? min($balance, $this->money($order->amount)) : 0.0);
        $externalDue = $isTopUp
            ? $this->money($order->payment_outstanding_amount)
            : $this->money(max(0, $this->money($order->amount) - $usable));

        return [
            'supported' => $supported,
            'balance' => $balance,
            'reserved_balance' => $this->money($wallet?->reserved_balance ?? 0),
            'usable_amount' => $usable,
            'external_due' => $externalDue,
            'currency' => $wallet?->currency ?? 'IDR',
        ];
    }

    /**
     * Reserve store credit while an external payment is being checked.
     * The client may send its displayed expected amount only as an optimistic
     * concurrency guard; the server always calculates the actual amount.
     */
    public function reserveForPayment(
        Order $order,
        int $userId,
        ?float $expectedAmount = null,
        ?int $actorId = null
    ): float {
        return DB::transaction(function () use ($order, $userId, $expectedAmount, $actorId) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless((int) $lockedOrder->user_id === $userId, 403);
            abort_unless($this->supportsOrder($lockedOrder), 422, 'Saldo BimbelKu belum tersedia untuk jenis tagihan lama ini.');
            abort_unless(in_array($lockedOrder->status, ['pending', 'rejected'], true), 422, 'Tagihan ini sudah tidak dapat menggunakan saldo.');
            abort_if($this->money($lockedOrder->wallet_applied_amount) > 0, 422, 'Saldo sudah pernah diterapkan pada tagihan ini.');

            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $wallet = $this->lockedWalletForUser($userId);
            $this->assertMutationBaseline($wallet);

            // Idempotensi pada request yang sudah memiliki hold aktif tetap
            // melewati pemeriksaan integritas. Retry tidak boleh menutupi
            // wallet/order hold yang sudah berubah di luar service.
            $existingReserved = $this->money($lockedOrder->wallet_reserved_amount);
            if ($existingReserved > 0) {
                if ($expectedAmount !== null && abs($existingReserved - $this->money($expectedAmount)) > 0.009) {
                    abort(409, 'Saldo yang tersedia berubah. Muat ulang pembayaran sebelum melanjutkan.');
                }

                return $existingReserved;
            }

            $available = $this->money($wallet->current_balance);
            $amount = min($available, $this->money($lockedOrder->amount));

            if ($expectedAmount !== null && abs($amount - $this->money($expectedAmount)) > 0.009) {
                abort(409, 'Saldo yang tersedia berubah. Muat ulang pembayaran sebelum melanjutkan.');
            }
            if ($amount <= 0) {
                return 0.0;
            }

            $before = $available;
            $after = $this->money($before - $amount);
            $reservedAfter = $this->money($wallet->reserved_balance + $amount);
            $version = ((int) $lockedOrder->wallet_reservation_version) + 1;
            $eventKey = "order:{$lockedOrder->id}:wallet_reserve:{$version}";

            $this->saveWallet($wallet, $after, $reservedAfter);
            $lockedOrder->forceFill([
                'wallet_reserved_amount' => $amount,
                'wallet_reservation_version' => $version,
                'payment_provider' => $amount >= $this->money($lockedOrder->amount)
                    ? 'bimbelku_wallet'
                    : 'wallet_plus_manual_transfer',
            ])->save();

            $this->createTransaction([
                'customer_wallet_id' => $wallet->id,
                'user_id' => $userId,
                'refund_id' => null,
                'order_id' => $lockedOrder->id,
                'actor_id' => $actorId ?? $userId,
                'event_key' => $eventKey,
                'type' => 'payment_reserve',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'description' => "Saldo dialokasikan untuk tagihan {$lockedOrder->order_id}",
                'metadata' => [
                    'reservation_version' => $version,
                    'order_amount' => $this->money($lockedOrder->amount),
                ],
                'created_at' => now(),
            ]);

            return $amount;
        }, 3);
    }

    /** Release a pending hold when the payment is rejected/cancelled/expired. */
    public function releaseReserved(Order $order, ?int $actorId = null, string $reason = 'payment_released'): float
    {
        return DB::transaction(function () use ($order, $actorId, $reason) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $amount = $this->money($lockedOrder->wallet_reserved_amount);
            if ($amount <= 0) {
                return 0.0;
            }

            User::query()->whereKey($lockedOrder->user_id)->lockForUpdate()->firstOrFail();
            $wallet = $this->lockedWalletForUser((int) $lockedOrder->user_id);
            $this->assertMutationBaseline($wallet);
            abort_if($this->money($wallet->reserved_balance) + 0.009 < $amount, 500, 'Cadangan Saldo BimbelKu tidak konsisten. Jalankan pemeriksaan wallet.');

            $before = $this->money($wallet->current_balance);
            $after = $this->money($before + $amount);
            $reservedAfter = $this->money($wallet->reserved_balance - $amount);
            $version = max(1, (int) $lockedOrder->wallet_reservation_version);
            $eventKey = "order:{$lockedOrder->id}:wallet_release:{$version}";
            $existing = CustomerWalletTransaction::query()->where('event_key', $eventKey)->first();
            if ($existing) {
                return $amount;
            }

            $this->saveWallet($wallet, $after, $reservedAfter);
            $lockedOrder->forceFill([
                'wallet_reserved_amount' => 0,
                'payment_provider' => $this->money($lockedOrder->wallet_applied_amount) > 0
                    ? $lockedOrder->payment_provider
                    : 'manual_transfer',
            ])->save();

            $this->createTransaction([
                'customer_wallet_id' => $wallet->id,
                'user_id' => $lockedOrder->user_id,
                'refund_id' => null,
                'order_id' => $lockedOrder->id,
                'actor_id' => $actorId,
                'event_key' => $eventKey,
                'type' => 'payment_release',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'description' => "Cadangan saldo untuk tagihan {$lockedOrder->order_id} dikembalikan",
                'metadata' => [
                    'reservation_version' => $version,
                    'reason' => $reason,
                ],
                'created_at' => now(),
            ]);

            return $amount;
        }, 3);
    }

    /**
     * Capture the hold when an order becomes paid/refund_pending. Available
     * balance was already reduced at reserve time; capture only reduces the
     * reserved bucket and records the amount on the order for the ledger.
     */
    public function captureReserved(Order $order): float
    {
        return DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $amount = $this->money($lockedOrder->wallet_reserved_amount);
            if ($amount <= 0) {
                return $this->money($lockedOrder->wallet_applied_amount);
            }

            User::query()->whereKey($lockedOrder->user_id)->lockForUpdate()->firstOrFail();
            $wallet = $this->lockedWalletForUser((int) $lockedOrder->user_id);
            $this->assertMutationBaseline($wallet);
            abort_if($this->money($wallet->reserved_balance) + 0.009 < $amount, 500, 'Cadangan Saldo BimbelKu tidak konsisten. Jalankan pemeriksaan wallet.');

            $this->saveWallet(
                $wallet,
                $this->money($wallet->current_balance),
                $this->money($wallet->reserved_balance - $amount)
            );
            $lockedOrder->forceFill([
                'wallet_reserved_amount' => 0,
                'wallet_applied_amount' => $this->money($lockedOrder->wallet_applied_amount + $amount),
            ])->save();

            $version = max(1, (int) $lockedOrder->wallet_reservation_version);
            $eventKey = "order:{$lockedOrder->id}:wallet_capture";
            $existing = CustomerWalletTransaction::query()->where('event_key', $eventKey)->first();
            if (! $existing) {
                $this->createTransaction([
                    'customer_wallet_id' => $wallet->id,
                    'user_id' => $lockedOrder->user_id,
                    'refund_id' => null,
                    'order_id' => $lockedOrder->id,
                    'actor_id' => $lockedOrder->user_id,
                    'event_key' => $eventKey,
                    'type' => 'payment_capture',
                    'direction' => 'debit',
                    'amount' => $amount,
                    'balance_before' => $this->money($wallet->current_balance),
                    'balance_after' => $this->money($wallet->current_balance),
                    'description' => "Saldo BimbelKu diterapkan ke tagihan {$lockedOrder->order_id}",
                    'metadata' => [
                        'reservation_version' => $version,
                        'journal_event_key' => "order:{$lockedOrder->id}:payment_received",
                        'reference_type' => Order::class,
                        'reference_id' => $lockedOrder->id,
                    ],
                    'created_at' => now(),
                ]);
            }

            return $amount;
        }, 3);
    }

    public function snapshot(int $userId, int $limit = 100): array
    {
        $wallet = CustomerWallet::query()->where('user_id', $userId)->first();
        $balance = $this->money($wallet?->current_balance ?? 0);
        $reserved = $this->money($wallet?->reserved_balance ?? 0);

        return [
            'balance' => $balance,
            'reserved_balance' => $reserved,
            'total_credit' => $this->money($balance + $reserved),
            'currency' => $wallet?->currency ?? 'IDR',
            'withdrawable' => false,
            'usage' => 'learning_payments',
            'transactions' => $wallet
                ? $wallet->transactions()
                    ->latest('id')
                    ->with(['refund:id,order_id,reason', 'order:id,order_id,amount,wallet_applied_amount,wallet_reserved_amount,status'])
                    ->limit(max(1, min($limit, 200)))
                    ->get()
                    ->map(fn (CustomerWalletTransaction $transaction) => [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'direction' => $transaction->direction,
                        'amount' => (float) $transaction->amount,
                        'balance_after' => (float) $transaction->balance_after,
                        'description' => $transaction->description,
                        'order' => $transaction->order ? [
                            'id' => $transaction->order->id,
                            'order_id' => $transaction->order->order_id,
                            'status' => $transaction->order->status,
                        ] : null,
                        'refund' => $transaction->refund ? [
                            'id' => $transaction->refund->id,
                            'order_id' => $transaction->refund->order_id,
                            'reason' => $transaction->refund->reason,
                        ] : null,
                        'created_at' => $transaction->created_at,
                    ])
                    ->values()
                : collect(),
        ];
    }

    public function supportsOrder(Order $order): bool
    {
        // Booking legacy tetap transfer manual; pembuatan booking legacy sudah
        // retired, sehingga store credit hanya diaktifkan pada dua flow aktif.
        return $order->learning_package_id !== null || $order->cheap_class_enrollment_id !== null;
    }

    private function lockedWalletForUser(int $userId): CustomerWallet
    {
        $wallet = CustomerWallet::query()->where('user_id', $userId)->lockForUpdate()->first();
        if ($wallet) {
            return $wallet;
        }

        CustomerWallet::unguarded(function () use ($userId) {
            CustomerWallet::query()->firstOrCreate(
                ['user_id' => $userId],
                ['current_balance' => 0, 'reserved_balance' => 0, 'currency' => 'IDR']
            );
        });

        return CustomerWallet::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Fail closed sebelum mutasi uang. current_balance adalah cache saldo
     * tersedia yang harus sama dengan mutasi terakhir, sedangkan
     * reserved_balance harus sama dengan total hold aktif pada order.
     *
     * Pemeriksaan ini sengaja dijalankan setelah row wallet dikunci agar
     * pembayaran tidak dapat memakai saldo yang diubah langsung di DB atau
     * state hold yang korup sebelum verifier terjadwal sempat berjalan.
     */
    private function assertMutationBaseline(CustomerWallet $wallet): void
    {
        $latestBalance = CustomerWalletTransaction::query()
            ->where('customer_wallet_id', $wallet->id)
            ->latest('id')
            ->value('balance_after');
        $expectedAvailable = $this->money($latestBalance ?? 0);
        $actualAvailable = $this->money($wallet->current_balance);

        $expectedReserved = $this->money(Order::query()
            ->where('user_id', $wallet->user_id)
            ->sum('wallet_reserved_amount'));
        $actualReserved = $this->money($wallet->reserved_balance);

        abort_if(
            abs($expectedAvailable - $actualAvailable) > 0.009
                || abs($expectedReserved - $actualReserved) > 0.009,
            503,
            'Saldo BimbelKu sementara dikunci karena pemeriksaan integritas diperlukan. Hubungi admin jika masalah berlanjut.'
        );

        // Rows injected outside the service cannot produce a valid APP_KEY
        // signature. Refuse to move money on top of a tampered ledger.
        $signature = app(\App\Support\WalletLedgerSignature::class);
        CustomerWalletTransaction::query()
            ->where('customer_wallet_id', $wallet->id)
            ->orderBy('id')
            ->get()
            ->each(function (CustomerWalletTransaction $transaction) use ($signature): void {
                abort_if(
                    blank($transaction->getAttributes()['checksum'] ?? null) || ! $signature->verify($transaction),
                    503,
                    'Ledger Saldo BimbelKu gagal diverifikasi. Hubungi admin sebelum melakukan pembayaran.'
                );
            });
    }

    private function saveWallet(CustomerWallet $wallet, float $available, float $reserved): void
    {
        abort_if($available < -0.009 || $reserved < -0.009, 500, 'Saldo BimbelKu tidak boleh negatif.');
        $wallet->forceFill([
            'current_balance' => $this->money(max(0, $available)),
            'reserved_balance' => $this->money(max(0, $reserved)),
            'currency' => 'IDR',
        ])->save();
    }

    private function createTransaction(array $attributes): CustomerWalletTransaction
    {
        return CustomerWalletTransaction::unguarded(
            fn () => CustomerWalletTransaction::create($attributes)
        );
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
