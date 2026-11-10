<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\ClassroomMessage;
use App\Models\ClassroomMessageRead;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckpointThreeCommunicationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_chat_marks_all_unread_messages_even_above_one_hundred(): void
    {
        [$teacher, $students, $booking] = $this->makePaidBooking();
        $student = $students[0];
        $now = now();
        ClassroomMessage::insert(collect(range(1, 120))->map(fn ($number) => [
            'booking_id' => $booking->id,
            'sender_id' => $teacher->id,
            'body' => "Pesan {$number}",
            'message_type' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        Sanctum::actingAs($student);
        $this->getJson("/api/bookings/{$booking->id}/learning-session")->assertOk();

        $this->assertSame(
            $booking->classroomMessages()->count(),
            ClassroomMessageRead::query()->where('user_id', $student->id)->count()
        );
    }

    public function test_paid_chat_restores_one_idempotent_system_message(): void
    {
        [, $students, $booking] = $this->makePaidBooking();
        Sanctum::actingAs($students[0]);

        $this->getJson('/api/conversations')->assertOk();
        $this->getJson("/api/bookings/{$booking->id}/learning-session")
            ->assertOk()
            ->assertJsonPath('messages.0.sender_role', 'system')
            ->assertJsonPath('messages.0.message_type', 'system');

        $this->getJson('/api/conversations')->assertOk();
        $this->assertDatabaseCount('classroom_messages', 1);
        $this->assertDatabaseHas('classroom_messages', [
            'booking_id' => $booking->id,
            'system_event_key' => 'order-connected',
        ]);
    }

    public function test_admin_notification_recipients_are_searchable_paginated_and_minimal(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => 'admin-notification@example.test',
        ]);
        config(['bimbelku.primary_admin_email' => $admin->email]);
        $recipient = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'name' => 'Murid Penerima Khusus',
            'email' => 'penerima-khusus@example.test',
            'phone' => '081234567890',
            'address' => 'Data privat tidak boleh ikut',
        ]);
        User::factory()->create([
            'role' => 'teacher',
            'status' => 'pending',
            'name' => 'Tutor Belum Aktif',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/notifications/recipients?q=Penerima&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', $recipient->id)
            ->assertJsonPath('data.0.name', 'Murid Penerima Khusus')
            ->assertJsonPath('data.0.role', 'student')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonMissingPath('data.0.phone')
            ->assertJsonMissingPath('data.0.address');

        $this->postJson('/api/admin/notifications/send', [
            'user_id' => $recipient->id,
            'title' => 'Pengingat belajar',
            'message' => 'Jangan lupa melihat jadwal belajar terbaru.',
            'type' => 'info',
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $recipient->id,
            'title' => 'Pengingat belajar',
        ]);
    }

    private function makePaidBooking(int $studentCount = 1): array
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $students = collect(range(1, $studentCount))
            ->map(fn () => User::factory()->create(['role' => 'student', 'status' => 'active']));
        $scheduledStart = Carbon::parse('2026-08-03 10:00:00', 'Asia/Jakarta');
        $scheduledEnd = Carbon::parse('2026-08-03 11:00:00', 'Asia/Jakarta');
        $bookingRequests = $students->map(fn (User $student) => BookingRequest::create([
            'student_id' => $student->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $scheduledStart->toDateString(),
            'start_time' => $scheduledStart->format('H:i:s'),
            'end_time' => $scheduledEnd->format('H:i:s'),
            'duration_hours' => 1,
            'status' => 'confirmed',
            'hourly_rate' => 50000,
            'total_amount' => 50000,
        ]));
        $booking = Booking::create([
            'booking_request_id' => $bookingRequests[0]->id,
            'student_id' => $students[0]->id,
            'teacher_id' => $teacher->id,
            'start_at' => $scheduledStart,
            'end_at' => $scheduledEnd,
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'hourly_rate' => 50000,
            'total_amount' => 50000 * $studentCount,
            'status' => 'confirmed',
            'commission_percent' => 20,
            'gross_amount' => 50000 * $studentCount,
            'teacher_net_amount' => 40000 * $studentCount,
            'payout_status' => 'locked',
        ]);
        foreach ($students as $index => $student) {
            $order = Order::create([
                'user_id' => $student->id,
                'booking_id' => $booking->id,
                'order_id' => "INV-CP3-{$booking->id}-{$index}",
                'amount' => 50000,
                'status' => 'paid',
            ]);
            BookingParticipant::create([
                'booking_id' => $booking->id,
                'booking_request_id' => $bookingRequests[$index]->id,
                'student_id' => $student->id,
                'order_id' => $order->id,
                'amount' => 50000,
                'status' => 'paid',
            ]);
            $bookingRequests[$index]->update(['booking_id' => $booking->id]);
            if ($index === 0) {
                $booking->update(['order_id' => $order->id]);
            }
        }

        return [$teacher, $students, $booking->fresh()];
    }
}
