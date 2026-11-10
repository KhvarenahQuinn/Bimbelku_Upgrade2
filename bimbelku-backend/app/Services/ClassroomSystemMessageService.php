<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassroomMessage;
use App\Models\Order;
use Illuminate\Support\Collection;

class ClassroomSystemMessageService
{
    public function ensureOrderConnected(Booking $booking, ?Order $paidOrder = null): ?ClassroomMessage
    {
        $booking->loadMissing([
            'teacher:id,name',
            'bookingRequest:id,subject_name,education_level,grade,chapter,learning_goal',
            'participants.student:id,name',
            'participants.bookingRequest:id,subject_name,education_level,grade,chapter,learning_goal',
            'participants.order:id,order_id,status,amount,learning_package_id',
            'order:id,order_id,status,amount,learning_package_id',
        ]);

        if (! $booking->teacher_id || ! $booking->teacher) {
            return null;
        }

        $order = $paidOrder;
        if (! $order || $order->status !== 'paid') {
            $order = $booking->participants
                ->pluck('order')
                ->filter(fn (?Order $item) => $item?->status === 'paid')
                ->first()
                ?? ($booking->order?->status === 'paid' ? $booking->order : null);
        }

        if (! $order) {
            return null;
        }

        $request = $booking->bookingRequest
            ?? $booking->participants->first()?->bookingRequest;
        $subject = $request?->subject_name ?: 'Bimbingan belajar';
        $topic = $request?->chapter ?: $request?->learning_goal;
        $studentNames = $booking->participants
            ->filter(fn ($participant) => $participant->order?->status === 'paid')
            ->pluck('student.name')
            ->filter()
            ->values();

        $durationMinutes = $booking->start_at && $booking->end_at
            ? max(1, $booking->start_at->diffInMinutes($booking->end_at))
            : max(60, (int) $booking->duration_hours * 60);

        $metadata = [
            'title' => 'Pesanan belajar terhubung',
            'order_code' => $order->order_id,
            'subject' => $subject,
            'topic' => $topic,
            'learning_goal' => $request?->learning_goal,
            'education_level' => $request?->education_level,
            'grade' => $request?->grade,
            'teacher_name' => $booking->teacher->name,
            'student_name' => $studentNames->count() > 1
                ? $studentNames->count().' murid'
                : $studentNames->first(),
            'scheduled_at' => $booking->start_at?->toIso8601String(),
            'duration_minutes' => $durationMinutes,
            'learning_mode' => $booking->learning_mode,
            'class_type' => $booking->class_type,
            'booking_status' => $booking->status,
            'amount' => (float) ($order->amount ?? $booking->total_amount ?? 0),
            'action_label' => 'Lihat detail kelas',
            'action_url_student' => "/student/my-classes?booking={$booking->id}",
            'action_url_teacher' => "/guru/kelas?booking={$booking->id}",
        ];

        $message = ClassroomMessage::firstOrCreate(
            [
                'booking_id' => $booking->id,
                'system_event_key' => 'order-connected',
            ],
            [
                // sender_id tetap memakai tutor sebagai pemilik teknis relasi lama,
                // tetapi message_type memastikan pesan tidak pernah ditampilkan seolah diketik tutor.
                'sender_id' => $booking->teacher_id,
                'body' => "Pesanan {$subject} sudah terhubung. Gunakan chat ini untuk membahas materi, persiapan, dan kebutuhan sesi melalui BimbelKu.",
                'message_type' => 'system',
                'metadata' => $metadata,
            ],
        );

        // Ringkasan dapat berubah saat data transaksi diperbarui, tetapi pesan tetap idempoten.
        if (! $message->wasRecentlyCreated && $message->metadata !== $metadata) {
            $message->update(['metadata' => $metadata]);
        }

        return $message;
    }

    public function ensureForBookings(Collection $bookings): void
    {
        $bookings->each(fn (Booking $booking) => $this->ensureOrderConnected($booking));
    }
}
