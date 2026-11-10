<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'settings';

    // WAJIB ADA: Agar kolom key & value bisa diisi
    protected $fillable = [
        'key',
        'value',
    ];
}
