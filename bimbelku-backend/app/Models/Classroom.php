<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sessions()
    {
        return $this->hasMany(ClassSession::class);
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'class_students', 'classroom_id', 'student_id')
            ->withPivot('joined_at');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
