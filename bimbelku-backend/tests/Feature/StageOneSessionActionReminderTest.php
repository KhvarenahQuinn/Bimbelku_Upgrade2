<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageOneSessionActionReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_session_action_reminder_follows_presence_v2_from_ready_to_review(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));
        $this->artisan('demo:session-reminder', ['stage' => 'setup'])->assertSuccessful();

        $student = User::query()->where('email', 'demo.student@bimbelku.local')->firstOrFail();
        $teacher = User::query()->where('email', 'demo.tutor@bimbelku.local')->firstOrFail();
        $booking = Booking::query()->where('student_id', $student->id)->where('teacher_id', $teacher->id)->latest('id')->firstOrFail();
        $this->assertSame('https://zoom.us/j/12345678901', $booking->meeting_link);

        Sanctum::actingAs($teacher);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.booking_id', $booking->id)
            ->assertJsonPath('data.kind', 'teacher_mark_ready');
        $this->postJson("/api/teacher/bookings/{$booking->id}/ready", [
            'focus_note' => 'Latihan Persamaan Linear.',
        ])->assertOk();

        Sanctum::actingAs($student);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'student_confirm_presence');
        $this->postJson("/api/student/bookings/{$booking->id}/presence-confirm")->assertOk();
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'student_session_started')
            ->assertJsonPath('data.target_url', "/student/my-classes?session={$booking->id}&session_action=started")
            ->assertJsonPath('data.informational', true);

        Sanctum::actingAs($teacher);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'teacher_session_ready');
        $this->postJson("/api/teacher/bookings/{$booking->id}/check-out")->assertOk();
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'teacher_report_progress');

        $this->postJson("/api/teacher/bookings/{$booking->id}/progress-reports", [
            'material_covered' => 'Persamaan linear dan latihan terarah.',
            'mastered_skills' => 'Murid mampu menyelesaikan latihan dasar secara mandiri.',
            'next_exercise' => 'Lanjutkan latihan Persamaan Linear.',
            'chapter_updates' => [[
                'chapter' => 'Persamaan Linear',
                'activity_type' => 'taught',
                'status_after' => 'completed',
                'needs_review' => false,
            ]],
        ])->assertCreated();

        Sanctum::actingAs($student);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'student_review_session')
            ->assertJsonPath('data.target_url', "/student/my-classes?session={$booking->id}&session_action=review");
    }

    public function test_online_session_requires_meeting_link_before_teacher_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));
        $this->artisan('demo:session-reminder', ['stage' => 'setup'])->assertSuccessful();

        $teacher = User::query()->where('email', 'demo.tutor@bimbelku.local')->firstOrFail();
        $booking = Booking::query()->latest('id')->firstOrFail();
        $booking->update(['meeting_link' => null]);

        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/ready", [
            'focus_note' => 'Tanpa tautan belum boleh mulai.',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Simpan tautan Zoom terlebih dahulu di Kelola sesi sebelum menyatakan siap mengajar.');

        $booking->update(['meeting_link' => 'https://zoom.us/j/12345678901']);
        $this->postJson("/api/teacher/bookings/{$booking->id}/ready", [
            'focus_note' => 'Tautan sudah tersimpan.',
        ])->assertOk();
    }

    public function test_demo_command_prepares_only_presence_v2_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));

        $this->artisan('demo:session-reminder', ['stage' => 'setup'])->assertSuccessful();

        $booking = Booking::query()->latest('id')->firstOrFail();
        $this->assertSame('presence_confirmation_v2', $booking->session_flow_version);
        $this->assertSame('confirmed', $booking->status);
        $this->assertNull($booking->tutor_ready_at);
        $this->assertNull($booking->student_confirmed_at);
        $this->assertSame('https://zoom.us/j/12345678901', $booking->meeting_link);

        $this->artisan('demo:session-reminder', ['stage' => 'status'])->assertSuccessful();
        $this->artisan('demo:session-reminder', ['stage' => 'reset'])->assertSuccessful();
    }
}
