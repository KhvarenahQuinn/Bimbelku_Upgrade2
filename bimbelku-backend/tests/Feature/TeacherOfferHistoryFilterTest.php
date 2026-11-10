<?php

namespace Tests\Feature;

use App\Models\BookingRequest;
use App\Models\TeacherOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherOfferHistoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_scope_filters_accepted_rejected_and_expired_offers(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $bookingRequest = BookingRequest::create([
            'student_id' => $student->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'duration_hours' => 1,
            'status' => 'matching',
            'matching_attempts' => 1,
            'search_started_at' => now(),
            'search_expires_at' => now()->addHour(),
        ]);

        foreach (['accepted', 'rejected', 'expired', 'student_rejected', 'cancelled'] as $index => $status) {
            // One offer per request per teacher, so each status needs its own request.
            $request = $index === 0 ? $bookingRequest : BookingRequest::create([
                'student_id' => $student->id,
                'subject_name' => 'Matematika',
                'education_level' => 'SMA',
                'grade' => 'Kelas 12',
                'learning_mode' => 'online',
                'class_type' => 'private',
                'scheduled_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00:00',
                'end_time' => '11:00:00',
                'duration_hours' => 1,
                'status' => 'matching',
                'matching_attempts' => 1,
                'search_started_at' => now(),
                'search_expires_at' => now()->addHour(),
            ]);

            TeacherOffer::create([
                'booking_request_id' => $request->id,
                'teacher_id' => $teacher->id,
                'status' => $status,
                'offered_at' => now()->subMinutes($index + 1),
                'expires_at' => now()->subMinute(),
            ]);
        }

        Sanctum::actingAs($teacher);

        $this->getJson('/api/teacher/offers?scope=history')
            ->assertOk()
            ->assertJsonPath('total', 5);

        foreach (['accepted', 'rejected', 'expired'] as $status) {
            $this->getJson("/api/teacher/offers?scope=history&status={$status}")
                ->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.status', $status);
        }

        $this->getJson('/api/teacher/offers?scope=history&status=unknown')
            ->assertStatus(422);
    }
}
