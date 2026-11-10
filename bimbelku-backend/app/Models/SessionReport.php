<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionReport extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['evidence'];

    protected $appends = ['evidence_url', 'has_evidence'];

    protected $casts = [
        'incident_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reportedStudent()
    {
        return $this->belongsTo(User::class, 'reported_student_id');
    }

    public function getEvidenceUrlAttribute(): ?string
    {
        return $this->evidence ? "session-reports/{$this->id}/evidence" : null;
    }

    public function getHasEvidenceAttribute(): bool
    {
        return ! empty($this->evidence);
    }
}
