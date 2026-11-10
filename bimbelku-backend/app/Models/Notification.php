<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'is_read',
        'type',
        'target_url',
        'unique_key',
        'attention_key',
        'entity_type',
        'entity_id',
        'read_at',
        'invalidated_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Notification $notification) {
            if (! $notification->target_url || $notification->attention_key) {
                return;
            }
            $target = (string) $notification->target_url;
            $notification->attention_key = match (true) {
                str_starts_with($target, '/student/packages') => 'student_order_status_changed',
                str_starts_with($target, '/student/history') => 'student_refund_status_changed',
                str_starts_with($target, '/guru/permintaan') => 'teacher_new_offer',
                str_starts_with($target, '/guru/kelas') => 'teacher_class_changed',
                str_starts_with($target, '/guru/dompet') => 'teacher_payout_changed',
                str_starts_with($target, '/admin/finance?tab=refunds') => 'admin_refund_exception',
                default => 'targeted_notification',
            };
            if ($notification->unique_key && preg_match('/^([a-z-]+).*?(\d+)(?::\d+)?$/', $notification->unique_key, $match)) {
                $notification->entity_type = str_replace('-', '_', $match[1]);
                $notification->entity_id = (int) $match[2];
            }
        });

        static::updating(function (Notification $notification) {
            // updateOrCreate untuk event yang sama tidak boleh membuka badge yang
            // sudah dibaca. Event baru harus memakai unique_key baru.
            if ($notification->getOriginal('is_read') && $notification->isDirty('is_read') && ! $notification->is_read) {
                $notification->is_read = true;
            }
            if ($notification->isDirty('is_read') && $notification->is_read && ! $notification->read_at) {
                $notification->read_at = now();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
