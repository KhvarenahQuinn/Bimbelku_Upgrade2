<?php

namespace App\Models;

use App\Support\PublicMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DynamicBanner extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['image_url'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }
        if (
            ! preg_match('#^https?://#i', $this->image_path)
            && ! Storage::disk('public')->exists($this->image_path)
        ) {
            return null;
        }

        return PublicMedia::url($this->image_path);
    }
}
