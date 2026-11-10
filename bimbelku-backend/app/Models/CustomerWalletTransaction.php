<?php

namespace App\Models;

use App\Models\Concerns\ImmutableFinancialRecord;
use App\Support\WalletLedgerSignature;
use Illuminate\Database\Eloquent\Model;

class CustomerWalletTransaction extends Model
{
    use ImmutableFinancialRecord;

    public $timestamps = false;

    /** Mutasi permanen hanya dibuat melalui CustomerWalletService. */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $signature = app(WalletLedgerSignature::class);

            if (blank($transaction->reference_type) || blank($transaction->reference_id)) {
                [$referenceType, $referenceId] = $signature->referenceFor($transaction->getAttributes());
                $transaction->reference_type = $referenceType;
                $transaction->reference_id = $referenceId;
            }
            if (blank($transaction->checksum)) {
                $transaction->checksum = $signature->sign($transaction->getAttributes());
            }
        });
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->belongsTo(CustomerWallet::class, 'customer_wallet_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
