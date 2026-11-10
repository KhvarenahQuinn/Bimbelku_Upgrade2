<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherSubject extends Model
{
    use HasFactory;

    protected $table = 'teacher_subjects';

    protected $attributes = [
        'is_group_active' => false,
    ];

    protected $guarded = ['id'];

    // Pastikan JANGAN ADA baris seperti: protected $name; atau protected $teacherProfile; disini.

    protected $casts = [
        'is_active' => 'boolean',
        'levels' => 'array',
        'is_online' => 'boolean',
        'is_offline' => 'boolean',
        'is_private_active' => 'boolean',
        'is_group_active' => 'boolean',
    ];

    // Relasi WAJIB PUBLIC
    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }
}
