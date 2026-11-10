<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialMedia extends Model
{
    use HasFactory;

    // [PENTING] Kita paksa Laravel baca tabel 'social_medias'
    protected $table = 'social_medias';

    protected $fillable = ['name', 'link', 'icon', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];
}
