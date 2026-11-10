<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /** Cache request-scoped untuk menghindari query admin utama berulang pada satu instance user. */
    private ?bool $primaryAdminCache = null;

    protected $fillable = [
        'name',
        'email',
        'google_id',
        'google_avatar_url',
        'google_onboarding_required_at',
        'email_verified_at',
        'email_verification_required_at',
        'phone',
        'password',
        'has_password',
        'payment_pin_hash',
        'profile_cover',
        'profile_cover_use_default',
        'password_updated_at',
        'role',
        'admin_type',
        'admin_permissions',
        'admin_permissions_updated_by',
        'admin_permissions_updated_at',
        'status',
        'school_name',
        'student_education_level',
        'grade',
        'learning_needs',
        'date_of_birth',
        'guardian_name',
        'guardian_phone',
        'guardian_relationship',
        'guardian_consent_at',
        // [BARU] Tambahan kolom alamat
        'address',
        'maps_link',
        'latitude',
        'longitude',
        'location_consent_at',
        'terms_accepted_at',
        'privacy_accepted_at',
        'policy_version',
        'consent_ip',
        'consent_user_agent',
        'teacher_rejection_streak',
        'last_teacher_rejection_at',
        'search_cooldown_until',
    ];

    protected $hidden = [
        'password',
        'payment_pin_hash',
        'remember_token',
        'google_id',
        'phone',
        'address',
        'maps_link',
        'latitude',
        'longitude',
        'date_of_birth',
        'guardian_name',
        'guardian_phone',
        'guardian_relationship',
        'guardian_consent_at',
        'consent_ip',
        'consent_user_agent',
    ];

    protected function dateOfBirth(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => ($value === null || $value === '')
                ? null
                : Carbon::parse($value)->toDateString(),
        );
    }

    protected $casts = [
        'profile_cover_use_default' => 'boolean',
        'email_verified_at' => 'datetime',
        'email_verification_required_at' => 'datetime',
        'google_onboarding_required_at' => 'datetime',
        'password' => 'hashed',
        'has_password' => 'boolean',
        'password_updated_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'privacy_accepted_at' => 'datetime',
        'date_of_birth' => 'date',
        'guardian_consent_at' => 'datetime',
        'location_consent_at' => 'datetime',
        'last_teacher_rejection_at' => 'datetime',
        'search_cooldown_until' => 'datetime',
        'teacher_rejection_streak' => 'integer',
        'admin_permissions' => 'array',
        'admin_permissions_updated_at' => 'datetime',
    ];

    public function isPrimaryAdmin(): bool
    {
        if ($this->primaryAdminCache !== null) {
            return $this->primaryAdminCache;
        }

        if ($this->role !== 'admin' || $this->status !== 'active') {
            return $this->primaryAdminCache = false;
        }

        $configuredEmail = (string) config('bimbelku.primary_admin_email', '');
        if ($configuredEmail !== '') {
            $configuredAdminId = static::query()
                ->where('role', 'admin')
                ->where('status', 'active')
                ->whereRaw('LOWER(email) = ?', [$configuredEmail])
                ->value('id');

            if ($configuredAdminId !== null) {
                return $this->primaryAdminCache = ((int) $this->getKey() === (int) $configuredAdminId);
            }
        }

        $primaryId = static::query()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->min('id');

        return $this->primaryAdminCache = ($primaryId !== null && (int) $this->getKey() === (int) $primaryId);
    }

    public function isSuperAdmin(): bool
    {
        // Nama method dipertahankan agar kode lama tetap kompatibel.
        return $this->isPrimaryAdmin();
    }

    public function hasAdminPermission(string $permission): bool
    {
        // Parameter tetap diterima untuk kompatibilitas middleware dan audit,
        // tetapi tidak ada lagi pembagian admin terbatas per modul.
        return $this->role === 'admin' && $this->status === 'active';
    }

    public function adminPermissionsUpdatedBy()
    {
        return $this->belongsTo(self::class, 'admin_permissions_updated_by');
    }

    // Relasi ke Profil Guru
    public function teacherProfile()
    {
        return $this->hasOne(TeacherProfile::class);
    }

    // Relasi ke Kelas yang dibuat (sebagai Guru)
    public function classrooms()
    {
        return $this->hasMany(Classroom::class, 'user_id');
    }

    // Relasi Murid ke Kelas (Many-to-Many)
    public function enrolledClasses()
    {
        return $this->belongsToMany(Classroom::class, 'class_students', 'student_id', 'classroom_id')
            ->withPivot('joined_at');
    }

    // Relasi Ketersediaan Jadwal
    public function availabilities()
    {
        return $this->hasMany(TeacherAvailability::class, 'user_id');
    }

    // Relasi Mapel (Lewat Profile)
    public function availabilityExceptions()
    {
        return $this->hasMany(TeacherAvailabilityException::class);
    }

    public function subjects()
    {
        return $this->hasManyThrough(
            TeacherSubject::class,
            TeacherProfile::class,
            'user_id',
            'teacher_profile_id',
            'id',
            'id'
        );
    }

    public function bookingRequests()
    {
        return $this->hasMany(BookingRequest::class, 'student_id');
    }

    public function teacherOffers()
    {
        return $this->hasMany(TeacherOffer::class, 'teacher_id');
    }

    public function teacherBookings()
    {
        return $this->hasMany(Booking::class, 'teacher_id');
    }

    public function teacherCheapClasses()
    {
        return $this->hasMany(CheapClass::class, 'teacher_id');
    }

    public function studentBookings()
    {
        return $this->hasMany(Booking::class, 'student_id');
    }

    public function bookingParticipations()
    {
        return $this->hasMany(BookingParticipant::class, 'student_id');
    }

    public function learningPackages()
    {
        return $this->hasMany(LearningPackage::class, 'student_id');
    }

    public function promotionClaims()
    {
        return $this->hasMany(PromotionClaim::class);
    }

    // Relasi Rating
    public function ratings()
    {
        return $this->hasMany(Rating::class, 'teacher_id');
    }
}
