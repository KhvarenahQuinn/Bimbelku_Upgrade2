<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'maximum_discount' => 'decimal:2',
        'minimum_purchase' => 'decimal:2',
        'target_plan_ids' => 'array',
        'target_levels' => 'array',
        'target_subjects' => 'array',
        'target_modes' => 'array',
        'new_students_only' => 'boolean',
        'claim_required' => 'boolean',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function claims()
    {
        return $this->hasMany(PromotionClaim::class);
    }

    public function isAvailable(): bool
    {
        $now = now(config('app.timezone'));

        return $this->is_active
            && (! $this->starts_at || $this->starts_at->lte($now))
            && (! $this->ends_at || $this->ends_at->gte($now));
    }
}
