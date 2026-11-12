<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'booking_request_id', 'replacement_of_booking_id', 'student_id', 'teacher_id', 'order_id', 'start_at', 'end_at',
        'duration_hours', 'learning_mode', 'class_type', 'hourly_rate', 'total_amount', 'status',
        'session_flow_version', 'tutor_ready_at', 'student_confirmed_at', 'session_focus_note',
        'tutor_ready_latitude', 'tutor_ready_longitude', 'tutor_ready_accuracy_meters', 'tutor_ready_ip_hash',
        'payment_due_at', 'address', 'maps_link',
        'commission_percent', 'gross_amount', 'teacher_net_amount', 'teacher_paid_amount', 'teacher_reserved_amount',
        'meeting_link', 'completion_notes', 'completion_submitted_at',
        'objection_deadline', 'student_approved_at', 'admin_review_required_at',
        'completed_at', 'payout_status',
        'session_started_at', 'session_ended_at', 'payout_request_id',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'duration_hours' => 'integer',
        'hourly_rate' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'payment_due_at' => 'datetime',
        'commission_percent' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'teacher_net_amount' => 'decimal:2',
        'teacher_paid_amount' => 'decimal:2',
        'teacher_reserved_amount' => 'decimal:2',
        'completion_submitted_at' => 'datetime',
        'objection_deadline' => 'datetime',
        'student_approved_at' => 'datetime',
        'admin_review_required_at' => 'datetime',
        'completed_at' => 'datetime',
        'session_started_at' => 'datetime',
        'tutor_ready_at' => 'datetime',
        'student_confirmed_at' => 'datetime',
        'tutor_ready_latitude' => 'float',
        'tutor_ready_longitude' => 'float',
        'tutor_ready_accuracy_meters' => 'integer',
        'session_ended_at' => 'datetime',
    ];

    public function bookingRequest()
    {
        return $this->belongsTo(BookingRequest::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function participants()
    {
        return $this->hasMany(BookingParticipant::class);
    }

    public function reports()
    {
        return $this->hasMany(SessionReport::class);
    }

    public function disputes()
    {
        return $this->hasMany(BookingDispute::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function classroomMessages()
    {
        return $this->hasMany(ClassroomMessage::class);
    }

    public function latestClassroomMessage()
    {
        return $this->hasOne(ClassroomMessage::class)->latestOfMany();
    }

    public function learningProgressReports()
    {
        return $this->hasMany(LearningProgressReport::class);
    }

    public function latestLearningProgressReport()
    {
        return $this->hasOne(LearningProgressReport::class)->latestOfMany('published_at');
    }

    public function sessionAttendances()
    {
        return $this->hasMany(SessionAttendance::class);
    }

    public function participantAttendances()
    {
        return $this->hasMany(ParticipantAttendance::class);
    }

    public function scheduleChangeRequests()
    {
        return $this->hasMany(ScheduleChangeRequest::class);
    }

    public function packageSession()
    {
        return $this->hasOne(PackageSession::class);
    }

    public function payoutRequest()
    {
        return $this->belongsTo(TeacherPayoutRequest::class, 'payout_request_id');
    }

    public function replacementOf()
    {
        return $this->belongsTo(self::class, 'replacement_of_booking_id');
    }

    public function replacementBookings()
    {
        return $this->hasMany(self::class, 'replacement_of_booking_id');
    }

    /**
     * Nilai sesi yang masih boleh dikembalikan ke murid. Refund yang sudah
     * diputuskan (pending/paid) mengurangi gross supaya satu sesi tidak bisa
     * dikembalikan dua kali oleh jalur yang berbeda.
     */
    public function refundableRemaining(): float
    {
        $gross = round((float) $this->gross_amount, 2);
        if ($gross <= 0) {
            return 0.0;
        }

        $refunded = round((float) Refund::query()
            ->where('booking_id', $this->id)
            ->where('cost_bearer', 'tutor_share')
            ->whereIn('status', ['pending', 'paid'])
            ->sum('amount'), 2);

        return round(max(0, $gross - $refunded), 2);
    }

    public function assertRefundable(float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new DomainException('Nominal refund sesi harus lebih dari nol.');
        }
        $remaining = $this->refundableRemaining();
        if ($amount > $remaining + 0.009) {
            throw new DomainException(round((float) $this->gross_amount, 2) > 0 && $remaining <= 0.009
                ? 'Sesi ini sudah dikembalikan seluruhnya.'
                : 'Nominal refund melebihi nilai sesi yang masih dapat dikembalikan.');
        }
    }
}
