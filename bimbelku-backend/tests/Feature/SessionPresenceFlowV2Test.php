<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\LearningPackage;
use App\Models\LearningProgram;
use App\Models\Order;
use App\Models\PackageChapter;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionPresenceFlowV2Test extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_presence_v2_starts_with_one_tap_and_finishes_without_pin_plan_or_camera(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));
        [$student, $teacher, $booking] = $this->makePresenceBooking();

        Sanctum::actingAs($teacher);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'teacher_mark_ready');
        $this->postJson("/api/teacher/bookings/{$booking->id}/ready", [
            'focus_note' => 'Latihan pecahan campuran.',
        ])
            ->assertOk()
            ->assertJsonPath('data.focus_note', 'Latihan pecahan campuran.');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'session_flow_version' => 'presence_confirmation_v2',
            'session_focus_note' => 'Latihan pecahan campuran.',
            'status' => 'confirmed',
        ]);

        Sanctum::actingAs($student);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'student_confirm_presence');
        $this->postJson("/api/student/bookings/{$booking->id}/presence-confirm")
            ->assertOk();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseHas('session_attendances', [
            'booking_id' => $booking->id,
            'user_id' => $teacher->id,
            'role' => 'teacher',
        ]);
        $this->assertDatabaseHas('participant_attendances', [
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'status' => 'present',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-20 10:50:00', 'Asia/Jakarta'));
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/check-out")
            ->assertOk();
        $this->postJson("/api/teacher/bookings/{$booking->id}/progress-reports", [
            'material_covered' => 'Pembahasan pecahan campuran.',
            'mastered_skills' => 'Murid mampu menyelesaikan latihan pecahan campuran dengan lebih mandiri.',
            'next_exercise' => 'Lanjutkan latihan pecahan campuran pada sesi berikutnya.',
            'progress_percent' => 50,
        ])
            ->assertCreated();

        $booking->refresh();
        $this->assertSame('awaiting_student_approval', $booking->status);
        $this->assertNotNull($booking->objection_deadline);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'completed',
            'payout_status' => 'ready',
        ]);
        $this->assertDatabaseHas('booking_participants', [
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'status' => 'approved',
        ]);
    }

    public function test_presence_v2_package_progress_is_provisional_until_student_accepts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));
        [$student, $teacher, $booking] = $this->makePresenceBooking();
        $topic = $this->attachSingleChapterPackage($student, $teacher, $booking);

        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/ready", [
            'focus_note' => 'Latihan Bab Pecahan.',
        ])->assertOk();

        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/presence-confirm")
            ->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-20 10:50:00', 'Asia/Jakarta'));
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/check-out")
            ->assertOk();
        $this->postJson("/api/teacher/bookings/{$booking->id}/progress-reports", [
            'material_covered' => 'Pembahasan Bab Pecahan dan latihan terarah.',
            'mastered_skills' => 'Murid mampu menyelesaikan latihan Bab Pecahan dengan lebih mandiri.',
            'next_exercise' => 'Lanjutkan latihan Bab Pecahan pada sesi berikutnya.',
            'chapter_updates' => [[
                'chapter' => 'Pecahan',
                'activity_type' => 'taught',
                'status_after' => 'completed',
                'needs_review' => false,
            ]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.progress_percent', 100);

        // Tutor boleh melaporkan hasil, tetapi Bab belum final sebelum murid menerima sesi.
        $this->assertSame('not_started', $topic->fresh()->status);
        $this->assertDatabaseHas('package_session_chapter_logs', [
            'package_chapter_id' => $topic->id,
            'status_before' => 'not_started',
            'status_after' => 'completed',
        ]);
        $this->assertSame('awaiting_student_approval', $booking->fresh()->status);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/approve")
            ->assertOk();

        $this->assertSame('completed', $topic->fresh()->status);
        $this->assertSame('completed', $booking->fresh()->status);
    }

    public function test_program_session_without_chapters_can_finish_and_release_tutor_earnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));
        [$student, $teacher, $booking] = $this->makePresenceBooking();
        $topic = $this->attachSingleChapterPackage($student, $teacher, $booking);
        $subject = $topic->subject;
        $package = $subject->package;
        $program = LearningProgram::create([
            'name' => 'Persiapan TKA', 'slug' => 'test-tka',
            'education_level' => 'SMP', 'is_active' => true,
        ]);
        $package->update(['learning_program_id' => $program->id]);
        $subject->update(['chapter' => 'Persiapan TKA', 'curriculum_chapter_ids' => []]);
        $topic->delete();

        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/ready", [
            'focus_note' => 'Latihan soal penalaran matematika TKA.',
        ])->assertOk();
        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/presence-confirm")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-20 10:50:00', 'Asia/Jakarta'));
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/check-out")->assertOk();
        $this->getJson("/api/bookings/{$booking->id}/learning-session")
            ->assertOk()->assertJsonCount(0, 'learning_chapters')
            ->assertJsonPath('permissions.can_report_progress', true);
        $payload = [
            'material_covered' => 'Latihan penalaran matematika TKA.',
            'mastered_skills' => 'Murid memahami strategi membaca soal penalaran.',
            'next_exercise' => 'Latihan soal penalaran lanjutan.',
            'chapter_updates' => [], 'progress_percent' => 60,
            'no_material_change' => false,
        ];
        $this->postJson("/api/teacher/bookings/{$booking->id}/progress-reports", $payload)
            ->assertCreated()->assertJsonPath('data.progress_percent', 60)
            ->assertJsonPath('data.no_material_change', false);
        $this->assertSame('awaiting_student_approval', $booking->fresh()->status);
        $this->assertSame('locked', $booking->fresh()->payout_status);
        $this->assertDatabaseCount('package_session_chapter_logs', 0);
        $this->postJson("/api/teacher/bookings/{$booking->id}/progress-reports", $payload)
            ->assertUnprocessable();

        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/approve")->assertOk();
        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertSame('ready', $booking->fresh()->payout_status);
        $this->assertEquals(40000, $booking->fresh()->teacher_net_amount);
        $this->assertSame('completed', $subject->fresh()->status);
        $this->assertSame('completed', $package->fresh()->status);
        $this->assertSame(1, $package->fresh()->used_sessions);
    }

    public function test_regular_package_with_missing_chapters_remains_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));
        [$student, $teacher, $booking] = $this->makePresenceBooking();
        $this->attachSingleChapterPackage($student, $teacher, $booking)->delete();
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/ready")->assertOk();
        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/presence-confirm")->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:50:00', 'Asia/Jakarta'));
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/check-out")->assertOk();
        $this->postJson("/api/teacher/bookings/{$booking->id}/progress-reports", [
            'material_covered' => 'Latihan matematika.', 'mastered_skills' => 'Memahami pecahan.',
            'next_exercise' => 'Latihan lanjutan.', 'progress_percent' => 100,
        ])->assertUnprocessable()->assertJsonPath('message', 'Katalog Bab paket tidak tersedia. Hubungi admin.');
        $this->assertSame('in_progress', $booking->fresh()->status);
        $this->assertSame('locked', $booking->fresh()->payout_status);
        $this->assertDatabaseCount('learning_progress_reports', 0);
    }

    public function test_teacher_portal_and_dashboard_expose_package_session_and_chapter_progress(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));
        [$student, $teacher, $booking] = $this->makePresenceBooking();
        $topic = $this->attachSingleChapterPackage($student, $teacher, $booking);

        Sanctum::actingAs($teacher);
        $this->getJson('/api/teacher/classes')
            ->assertOk()
            ->assertJsonPath('0.package_session_progress.subject_total_sessions', 1)
            ->assertJsonPath('0.package_session_progress.subject_completed_sessions', 0)
            ->assertJsonPath('0.package_session_progress.subject_remaining_sessions', 1)
            ->assertJsonPath('0.package_progress.completed_chapters', 0)
            ->assertJsonPath('0.package_progress.progress_percent', 0);
        $this->getJson('/api/teacher/dashboard-v2')
            ->assertOk()
            ->assertJsonPath('classes.session_progress.total', 1)
            ->assertJsonPath('classes.session_progress.completed', 0)
            ->assertJsonPath('classes.session_progress.remaining', 1);

        $this->postJson("/api/teacher/bookings/{$booking->id}/ready", [
            'focus_note' => 'Latihan Bab Pecahan.',
        ])->assertOk();
        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/presence-confirm")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-20 10:50:00', 'Asia/Jakarta'));
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/check-out")->assertOk();
        $this->postJson("/api/teacher/bookings/{$booking->id}/progress-reports", [
            'material_covered' => 'Pembahasan Bab Pecahan dan latihan terarah.',
            'mastered_skills' => 'Murid mampu menyelesaikan latihan Bab Pecahan dengan lebih mandiri.',
            'next_exercise' => 'Lanjutkan latihan Bab Pecahan pada sesi berikutnya.',
            'chapter_updates' => [[
                'chapter' => 'Pecahan',
                'activity_type' => 'taught',
                'status_after' => 'completed',
                'needs_review' => false,
            ]],
        ])->assertCreated();

        // Progress sesi tetap provisional sampai murid menerima sesi.
        $this->getJson('/api/teacher/classes')
            ->assertOk()
            ->assertJsonPath('0.package_session_progress.subject_completed_sessions', 0)
            ->assertJsonPath('0.package_progress.progress_percent', 0);

        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/approve")->assertOk();
        $this->assertSame('completed', $topic->fresh()->status);

        Sanctum::actingAs($teacher);
        $this->getJson('/api/teacher/classes')
            ->assertOk()
            ->assertJsonPath('0.package_session_progress.subject_total_sessions', 1)
            ->assertJsonPath('0.package_session_progress.subject_completed_sessions', 1)
            ->assertJsonPath('0.package_session_progress.subject_remaining_sessions', 0)
            ->assertJsonPath('0.package_session_progress.used_sessions', 1)
            ->assertJsonPath('0.package_session_progress.remaining_sessions', 0)
            ->assertJsonPath('0.package_progress.completed_chapters', 1)
            ->assertJsonPath('0.package_progress.progress_percent', 100);
        $this->getJson('/api/teacher/dashboard-v2')
            ->assertOk()
            ->assertJsonPath('classes.session_progress.total', 1)
            ->assertJsonPath('classes.session_progress.completed', 1)
            ->assertJsonPath('classes.session_progress.remaining', 0);
    }

    private function makePresenceBooking(): array
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $start = Carbon::parse('2026-08-20 10:00:00', 'Asia/Jakarta');
        $end = Carbon::parse('2026-08-20 11:00:00', 'Asia/Jakarta');

        $request = BookingRequest::create([
            'student_id' => $student->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'chapter' => 'Pecahan',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'duration_hours' => 1,
            'status' => 'confirmed',
            'hourly_rate' => 50000,
            'total_amount' => 50000,
        ]);

        $booking = Booking::create([
            'booking_request_id' => $request->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'start_at' => $start,
            'end_at' => $end,
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'meeting_link' => 'https://zoom.us/j/12345678901',
            'hourly_rate' => 50000,
            'total_amount' => 50000,
            'status' => 'confirmed',
            'session_flow_version' => 'presence_confirmation_v2',
            'commission_percent' => 20,
            'gross_amount' => 50000,
            'teacher_net_amount' => 40000,
            'payout_status' => 'locked',
        ]);

        $order = Order::create([
            'user_id' => $student->id,
            'booking_id' => $booking->id,
            'order_id' => 'INV-PRESENCE-V2-'.$booking->id,
            'amount' => 50000,
            'status' => 'paid',
        ]);
        BookingParticipant::create([
            'booking_id' => $booking->id,
            'booking_request_id' => $request->id,
            'student_id' => $student->id,
            'order_id' => $order->id,
            'amount' => 50000,
            'status' => 'paid',
        ]);
        $booking->update(['order_id' => $order->id]);
        $request->update(['booking_id' => $booking->id]);

        return [$student, $teacher, $booking->fresh()];
    }

    private function attachSingleChapterPackage(User $student, User $teacher, Booking $booking): PackageChapter
    {
        $plan = PackagePlan::create([
            'name' => 'Paket Uji Presence V2',
            'slug' => 'presence-v2-test-'.$booking->id,
            'description' => 'Fixture otomatis untuk Session Flow V2.',
            'session_count' => 1,
            'validity_days' => 7,
            'maximum_subjects' => 1,
            'sort_order' => 9999,
            'is_active' => false,
        ]);
        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-PRESENCE-'.$booking->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'status' => 'active',
            'total_sessions' => 1,
            'used_sessions' => 0,
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'total_amount' => 50000,
            'starts_at' => $booking->start_at,
            'expires_at' => $booking->start_at->copy()->addDays(7),
        ]);
        $subject = PackageSubject::create([
            'learning_package_id' => $package->id,
            'assigned_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'chapter' => 'Pecahan',
            'curriculum_chapter_ids' => [],
            'allocated_sessions' => 1,
            'unit_price' => 50000,
            'subtotal_amount' => 50000,
            'status' => 'active',
        ]);
        $topic = PackageChapter::create([
            'package_subject_id' => $subject->id,
            'curriculum_chapter_id' => null,
            'title' => 'Pecahan',
            'status' => 'not_started',
            'needs_review' => false,
            'sort_order' => 1,
        ]);
        PackageSession::create([
            'package_subject_id' => $subject->id,
            'booking_id' => $booking->id,
            'sequence' => 1,
            'scheduled_start_at' => $booking->start_at,
            'scheduled_end_at' => $booking->end_at,
            'status' => 'scheduled',
        ]);

        $booking->bookingRequest?->update(['package_subject_id' => $subject->id]);
        $booking->order?->update(['learning_package_id' => $package->id]);

        return $topic;
    }
}
