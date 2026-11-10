<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    // Relasi ke Guru (Menggunakan tabel pivot 'location_teacher')
    public function teachers()
    {
        return $this->belongsToMany(TeacherProfile::class, 'location_teacher', 'location_id', 'teacher_profile_id');
    }
}
