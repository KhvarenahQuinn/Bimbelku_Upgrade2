<?php

namespace App\Models;

use App\Services\TeacherMatchingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BookingRequest extends Model
{
    protected $hidden = ['latitude', 'longitude'];

    protected $fillable = [
        'student_id', 'matched_teacher_id', 'subject_name', 'curriculum_subject_id', 'education_level', 'grade',
        'learning_mode', 'class_type', 'scheduled_date', 'start_time', 'end_time', 'duration_hours',
        'address', 'maps_link', 'latitude', 'longitude', 'status', 'matching_attempts', 'hourly_rate',
        'total_amount', 'teacher_response_deadline', 'next_matching_at', 'payment_due_at', 'chapter',
        'learning_goal', 'search_radius_km', 'search_started_at',
        'search_expires_at', 'teacher_decision_deadline', 'teacher_rejection_reason', 'booking_id',
        'package_subject_id', 'teacher_replacement_request_id',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'duration_hours' => 'integer',
        'matching_attempts' => 'integer',
        'hourly_rate' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'teacher_response_deadline' => 'datetime',
        'next_matching_at' => 'datetime',
        'payment_due_at' => 'datetime',
        'search_radius_km' => 'integer',
        'search_started_at' => 'datetime',
        'search_expires_at' => 'datetime',
        'teacher_decision_deadline' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function scopeMatchingAnchors(Builder $query): Builder
    {
        return $query;
    }

    public function scopeMatchingNeedsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $attention) {
            $attention
                ->where(function (Builder $unresolved) {
                    $unresolved->whereIn('status', ['no_teacher', 'expired'])
                        ->whereDate('scheduled_date', '>=', today());
                })
                ->orWhere(function (Builder $expiring) {
                    // A live 60-minute window can still dispatch another offer
                    // wave, so only a truly expired window needs admin action.
                    // Individual offer deadlines may lapse mid-window without
                    // meaning the search is stuck.
                    $expiring->whereIn('status', ['matching', 'teacher_pending'])
                        ->where(function (Builder $window) {
                            $window
                                ->where('search_expires_at', '<=', now())
                                // Legacy rows may still store a 24-hour expiry.
                                // The effective one-hour window is the source of truth.
                                ->orWhereRaw('COALESCE(search_started_at, created_at) <= ?', [
                                    now()->subMinutes(TeacherMatchingService::SEARCH_WINDOW_MINUTES),
                                ]);
                        });
                });
        });
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function matchedTeacher()
    {
        return $this->belongsTo(User::class, 'matched_teacher_id');
    }

    public function offers()
    {
        return $this->hasMany(TeacherOffer::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function primaryBooking()
    {
        return $this->hasOne(Booking::class);
    }

    public function participant()
    {
        return $this->hasOne(BookingParticipant::class);
    }

    public function curriculumSubject()
    {
        return $this->belongsTo(CurriculumSubject::class);
    }

    public function packageSubject()
    {
        return $this->belongsTo(PackageSubject::class);
    }

    public function teacherReplacement()
    {
        return $this->belongsTo(TeacherReplacementRequest::class, 'teacher_replacement_request_id');
    }

    public function matchingOperationLogs()
    {
        return $this->hasMany(MatchingOperationLog::class);
    }

    public function latestMatchingExhaustion()
    {
        return $this->hasOne(MatchingOperationLog::class)
            ->where('action', 'matching_exhausted')
            ->latestOfMany('created_at');
    }
}
