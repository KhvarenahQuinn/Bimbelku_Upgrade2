<?php

namespace App\Services;

use App\Models\CustomerWallet;
use App\Models\FinancialLedgerEntry;
use App\Models\Order;
use App\Support\WalletLedgerSignature;

class WalletIntegrityService
{
    /** @return list<string> */
    public function errors(): array
    {
        $errors = [];
        $signature = app(WalletLedgerSignature::class);

        CustomerWallet::query()
            ->with(['transactions' => fn ($query) => $query->orderBy('id')])
            ->orderBy('id')
            ->each(function (CustomerWallet $wallet) use (&$errors, $signature) {
                $available = $this->money($wallet->current_balance);
                $reserved = $this->money($wallet->reserved_balance);
                if ($available < 0 || $reserved < 0) {
                    $errors[] = "Wallet {$wallet->id} memiliki saldo negatif.";
                }

                $running = 0.0;
                foreach ($wallet->transactions as $transaction) {
                    $before = $this->money($transaction->balance_before);
                    $after = $this->money($transaction->balance_after);
                    $amount = $this->money($transaction->amount);
                    if (abs($running - $before) > 0.009) {
                        $errors[] = "Mutasi wallet {$wallet->id} terputus pada transaksi {$transaction->id}.";
                    }

                    // `payment_capture` menggeser cadangan ke nilai terpakai;
                    // saldo tersedia tidak berubah karena sudah dikurangi saat reserve.
                    $expectedAfter = match ($transaction->type) {
                        'payment_capture' => $before,
                        default => match ($transaction->direction) {
                            'credit' => $this->money($before + $amount),
                            'debit' => $this->money($before - $amount),
                            default => null,
                        },
                    };
                    if ($expectedAfter === null || abs($expectedAfter - $after) > 0.009) {
                        $errors[] = "Perhitungan transaksi wallet {$transaction->id} tidak valid.";
                    }
                    if ($after < -0.009) {
                        $errors[] = "Transaksi wallet {$transaction->id} menghasilkan saldo negatif.";
                    }
                    if (blank($transaction->reference_type) || blank($transaction->reference_id)) {
                        $errors[] = "Mutasi wallet {$transaction->id} tidak memiliki referensi ledger.";
                    }
                    if (blank($transaction->getAttributes()['checksum'] ?? null)) {
                        $errors[] = "Mutasi wallet {$transaction->id} tidak memiliki checksum.";
                    } elseif (! $signature->verify($transaction)) {
                        $errors[] = "Checksum mutasi wallet {$transaction->id} tidak valid (kemungkinan injeksi manual).";
                    }
                    $running = $after;
                }

                if (abs($running - $available) > 0.009) {
                    $errors[] = "Saldo tersedia wallet {$wallet->id} tidak sama dengan riwayat mutasi.";
                }

                $reservedFromOrders = $this->money(Order::query()
                    ->where('user_id', $wallet->user_id)
                    ->sum('wallet_reserved_amount'));
                if (abs($reservedFromOrders - $reserved) > 0.009) {
                    $errors[] = "Saldo tertahan wallet {$wallet->id} tidak sama dengan cadangan pada tagihan.";
                }
            });

        $walletLiability = $this->money(CustomerWallet::query()
            ->selectRaw('COALESCE(SUM(current_balance + reserved_balance), 0) AS total')
            ->value('total'));
        $ledgerCredit = $this->money(FinancialLedgerEntry::query()
            ->where('account_code', 'customer_wallet_liability')
            ->where('side', 'credit')
            ->sum('amount'));
        $ledgerDebit = $this->money(FinancialLedgerEntry::query()
            ->where('account_code', 'customer_wallet_liability')
            ->where('side', 'debit')
            ->sum('amount'));
        $ledgerLiability = $this->money($ledgerCredit - $ledgerDebit);
        if (abs($walletLiability - $ledgerLiability) > 0.009) {
            $errors[] = sprintf(
                'Total kewajiban wallet %.2f tidak sama dengan ledger %.2f.',
                $walletLiability,
                $ledgerLiability
            );
        }

        return $errors;
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
