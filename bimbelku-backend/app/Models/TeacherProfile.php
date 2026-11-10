<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherProfile extends Model
{
    use HasFactory;

    protected $table = 'teacher_profiles';

    protected $hidden = [
        'cv_file',
        'identity_document',
        'live_selfie',
        'qualification_document',
        'certification_document',
        'phone',
        'whatsapp_number',
        'latitude',
        'longitude',
        'bank_name',
        'account_number',
        'account_name',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'max_travel_km' => 'integer',
        'assignment_count' => 'integer',
        'last_assigned_at' => 'datetime',
        'is_accepting_requests' => 'boolean',
        'public_profile_enabled' => 'boolean',
        'public_profile_consent_at' => 'datetime',
        'public_directory_approved_at' => 'datetime',
        'verified_at' => 'datetime',
        'documents_checked_at' => 'datetime',
        'whatsapp_test_scheduled_at' => 'datetime',
        'whatsapp_test_passed_at' => 'datetime',
        'suspended_until' => 'datetime',
        'bank_account_changed_at' => 'datetime',
        'payout_hold_until' => 'datetime',
        'bank_details_version' => 'integer',
        'no_response_streak' => 'integer',
        'no_response_window_started_at' => 'datetime',
    ];

    // Relasi WAJIB PUBLIC
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function subjects()
    {
        return $this->hasMany(TeacherSubject::class, 'teacher_profile_id');
    }
}
