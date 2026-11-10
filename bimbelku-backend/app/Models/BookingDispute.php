<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingDispute extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['evidence'];

    protected $appends = ['evidence_url', 'has_evidence'];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function getEvidenceUrlAttribute(): ?string
    {
        return $this->evidence ? "disputes/{$this->id}/evidence" : null;
    }

    public function getHasEvidenceAttribute(): bool
    {
        return ! empty($this->evidence);
    }
}
