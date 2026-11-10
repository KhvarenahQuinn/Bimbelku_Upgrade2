<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassSession extends Model
{
    use HasFactory;

    // Guarded id artinya semua kolom lain (termasuk start_time) boleh diisi
    protected $guarded = ['id'];

    // [PENTING] Agar start_time otomatis jadi Carbon object (mudah diformat tgl/jam)
    protected $casts = [
        'start_time' => 'datetime',
        'is_completed' => 'boolean',
    ];

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }
}
