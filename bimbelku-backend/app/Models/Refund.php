<?php

namespace App\Models;

use App\Models\Concerns\HasAutomaticPublicCode;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use HasAutomaticPublicCode;

    protected $guarded = ['id'];

    protected $hidden = ['proof'];

    protected $appends = ['proof_url'];

    protected $casts = [
        'amount' => 'decimal:2',
        'wallet_refund_amount' => 'decimal:2',
        'external_refund_amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'destination_selected_at' => 'datetime',
        'destination_selection_version' => 'integer',
        'gateway_submitted_at' => 'datetime',
        'gateway_processed_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'decision_actor_id' => 'integer',
    ];

    /**
     * Refunds caused by an objective condition never wait for admin approval.
     * Keep this rule in one place so the observer, settlement service, and
     * scheduler cannot drift apart.
     */
    public function isAutomatic(): bool
    {
        if (in_array($this->source_type, ['teacher_replacement', 'late_gateway_payment', 'no_teacher', 'tutor_exit'], true)) {
            return true;
        }

        if ($this->order?->learning_package_id && $this->destination_method === 'bimbelku_balance') {
            return true;
        }

        return in_array(mb_strtolower(trim((string) $this->reason)), [
            'tutor tidak ditemukan',
            'paket dibatalkan murid saat pencarian tutor',
        ], true);
    }

    public function gatewayFailed(): bool
    {
        return in_array((string) $this->gateway_status, ['FAILED', 'REJECTED'], true);
    }

    /**
     * Only refunds that never left the admin queue (rejected/cancelled) release
     * their reserved value. Everything else still consumes the cap.
     */
    public function reservesFunds(): bool
    {
        return ! in_array((string) $this->status, ['cancelled', 'rejected'], true);
    }

    protected function automaticPublicCodeColumn(): string
    {
        return 'refund_code';
    }

    protected function buildAutomaticPublicCode(): string
    {
        $isCheapClass = (bool) $this->order()->whereNotNull('cheap_class_enrollment_id')->exists();

        return ($isCheapClass ? 'RFD-KM-' : 'RFD-').$this->paddedPublicId();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function walletTransaction()
    {
        return $this->hasOne(CustomerWalletTransaction::class, 'refund_id');
    }

    /**
     * Preserve the original payment tender on refund. Store credit is
     * non-withdrawable, so the portion originally paid from Saldo BimbelKu
     * must always return to Saldo BimbelKu.
     *
     * @return array{total_amount:float,wallet_funded_amount:float,external_funded_amount:float}
     */
    public function tenderBreakdown(): array
    {
        if ($this->wallet_refund_amount !== null && $this->external_refund_amount !== null) {
            return [
                'total_amount' => round((float) $this->amount, 2),
                'wallet_funded_amount' => round((float) $this->wallet_refund_amount, 2),
                'external_funded_amount' => round((float) $this->external_refund_amount, 2),
            ];
        }
        $order = $this->relationLoaded('order') ? $this->getRelation('order') : $this->order()->first();
        $total = round(max(0, (float) $this->amount), 2);
        $orderAmount = round(max(0, (float) ($order?->amount ?? $total)), 2);
        $walletApplied = min(
            $orderAmount,
            round(max(0, (float) ($order?->wallet_applied_amount ?? 0)), 2)
        );

        // Production refunds are currently full-order refunds. The ratio also
        // keeps a future partial refund proportional instead of cashing out
        // store credit by accident.
        $walletFunded = $orderAmount > 0
            ? round($total * ($walletApplied / $orderAmount), 2)
            : 0.0;
        $walletFunded = min($total, $walletApplied, max(0, $walletFunded));
        $externalFunded = round(max(0, $total - $walletFunded), 2);

        return [
            'total_amount' => $total,
            'wallet_funded_amount' => $walletFunded,
            'external_funded_amount' => $externalFunded,
        ];
    }

    public function getProofUrlAttribute(): ?string
    {
        return $this->proof ? "refunds/{$this->id}/proof" : null;
    }
}
