<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    use HasFactory;

    protected $hidden = ['proof_url'];

    protected $fillable = [
        'user_id',
        'amount',
        'period',
        'total_classes',
        'proof_url', // Pastikan kolom ini ada di fillable
        'status',
        'booking_id',
        'gross_amount',
        'commission_amount',
        'requested_amount',
        'tax_amount',
        'processed_by',
        'processed_at',
        'booking_ids',
        'allocation_breakdown',
        'bank_name',
        'account_number',
        'account_name',
        'payout_approval_id',
        'payment_provider',
        'gateway_payout_id',
        'gateway_reference_id',
        'gateway_status',
        'gateway_failure_code',
        'gateway_processed_at',
        'gateway_last_checked_at',
        'payout_channel_code',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'requested_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'booking_ids' => 'array',
        'allocation_breakdown' => 'array',
        'gateway_processed_at' => 'datetime',
        'gateway_last_checked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payoutRequest()
    {
        return $this->hasOne(TeacherPayoutRequest::class);
    }
}
