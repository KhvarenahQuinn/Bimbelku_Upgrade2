<?php

namespace App\Models;

use App\Support\PublicMedia;
use Illuminate\Database\Eloquent\Model;

class TutorialStep extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['image_url'];

    protected $casts = [
        'callout' => 'array',
        'sort_order' => 'integer',
    ];

    public function tutorial()
    {
        return $this->belongsTo(Tutorial::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return PublicMedia::url($this->image_path);
    }
}
