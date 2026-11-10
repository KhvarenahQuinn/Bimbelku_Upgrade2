<?php

namespace Tests\Feature;

use App\Models\CheapClass;
use App\Models\CheapClassSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoCheapClassCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_demo_cheap_class_command_runs_full_teacher_admin_student_flow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 15:00:00', 'Asia/Jakarta'));

        $this->artisan('demo:cheap-class', ['stage' => 'setup'])
            ->assertSuccessful();

        $student = User::query()->where('email', 'demo.student@bimbelku.local')->firstOrFail();
        $secondStudent = User::query()->where('email', 'demo.student2@bimbelku.local')->firstOrFail();
        $teacher = User::query()->where('email', 'demo.tutor@bimbelku.local')->firstOrFail();
        $admin = User::query()->where('email', 'demo.admin@bimbelku.local')->firstOrFail();
        $class = CheapClass::query()->where('package_code', 'like', 'DEMO-KM-%')->firstOrFail();
        $session = CheapClassSession::query()->where('cheap_class_id', $class->id)->firstOrFail();

        $this->assertSame('confirmed', $class->status);
        $this->assertSame(1, (int) $class->session_count);
        $this->assertSame('scheduled', $session->status);
        $this->assertTrue($session->starts_at->lte(now()));
        $this->assertTrue($session->ends_at->gt(now()));
        $this->assertSame(2, $class->enrollments()->where('status', 'confirmed')->count());
        $this->assertSame(2, $class->enrollments()->whereHas('order', fn ($query) => $query->where('status', 'paid'))->count());
        $this->assertNotNull($teacher->teacherProfile?->verified_at);
        $this->assertNotNull($teacher->teacherProfile?->verified_at);
        $this->assertTrue($admin->isPrimaryAdmin());
        $this->assertSame('student', $secondStudent->role);

        // Akun yang dicetak command harus benar-benar dapat dipakai lewat AuthController production.
        $this->postJson('/api/login', [
            'email' => 'demo.student@bimbelku.local',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.role', 'student');
        $this->postJson('/api/login', [
            'email' => 'demo.tutor@bimbelku.local',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.role', 'teacher');
        $this->postJson('/api/login', [
            'email' => 'demo.admin@bimbelku.local',
            'password' => 'password',
            'login_portal' => 'admin',
        ])->assertOk()->assertJsonPath('user.role', 'admin');

        Sanctum::actingAs($teacher);
        $this->getJson('/api/teacher/cheap-classes')
            ->assertOk()
            ->assertJsonPath('0.id', $class->id)
            ->assertJsonPath('0.status', 'confirmed');
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'cheap_teacher_mark_ready');
        $this->postJson("/api/teacher/cheap-classes/{$class->id}/start-session")
            ->assertOk()
            ->assertJsonPath('session.status', 'in_progress');
        $firstAttendance = $session->fresh()->teacher_started_at;
        $this->assertNotNull($firstAttendance);

        // Klik ulang aman dan tidak mengubah jam absensi pertama.
        $this->postJson("/api/teacher/cheap-classes/{$class->id}/start-session")
            ->assertOk();
        $this->assertTrue($session->fresh()->teacher_started_at->equalTo($firstAttendance));

        Sanctum::actingAs($student);
        $this->getJson('/api/student/cheap-classes?scope=owned')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $class->id)
            ->assertJsonPath('0.enrollment.status', 'confirmed');
        $this->getJson('/api/student/cheap-classes')
            ->assertOk()
            ->assertJsonPath('0.id', $class->id)
            ->assertJsonPath('0.enrollment.status', 'confirmed');
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'cheap_student_session_started')
            ->assertJsonPath('data.cheap_class_id', $class->id)
            ->assertJsonPath('data.cheap_class_session_id', $session->id);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/cheap-classes/schedule?view=sessions&scope=active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $session->id)
            ->assertJsonPath('data.0.teacher_started_at', $session->fresh()->teacher_started_at->toISOString());

        // Mempercepat hanya fixture local/testing sampai jadwal sesi berakhir.
        $this->artisan('demo:cheap-class', ['stage' => 'session-ended'])
            ->assertSuccessful();
        $this->assertSame('report_required', $session->fresh()->status);
        $this->assertSame('confirmed', $class->fresh()->status);

        Sanctum::actingAs($teacher);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'cheap_teacher_report_required')
            ->assertJsonPath('data.cheap_class_id', $class->id)
            ->assertJsonPath('data.cheap_class_session_id', $session->id)
            ->assertJsonPath('data.target_url', "/guru/kelas?class_kind=group&cheap_class={$class->id}&cheap_session={$session->id}&cheap_action=report");

        $payload = [
            'session_id' => $session->id,
            'attended_participants_count' => 2,
            'session_notes' => 'Dua murid hadir dan aktif mengikuti latihan aljabar dasar sampai sesi selesai.',
            'updates' => [[
                'subject_index' => 0,
                'progress_status' => 'completed',
                'needs_review' => true,
                'progress_notes' => 'Konsep dasar selesai, tetapi soal cerita perlu diulang pada latihan mandiri.',
            ]],
        ];
        $this->putJson("/api/teacher/cheap-classes/{$class->id}/progress", $payload)
            ->assertOk()
            ->assertJsonPath('session.status', 'awaiting_admin_verification');

        // Progress resmi murid belum boleh berubah sebelum admin menyetujui laporan.
        $this->assertSame('not_started', $class->fresh()->subjects[0]['progress_status']);

        Sanctum::actingAs($admin);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'cheap_admin_verify_report')
            ->assertJsonPath('data.cheap_class_session_id', $session->id)
            ->assertJsonPath('data.teacher_name', $teacher->name);

        Sanctum::actingAs($student);
        $this->getJson("/api/student/cheap-classes/{$class->id}?scope=progress")
            ->assertOk()
            ->assertJsonPath('subjects.0.progress_status', 'not_started');

        Sanctum::actingAs($admin);
        $this->withHeader('Idempotency-Key', 'demo-cheap-class-revision-0001')
            ->postJson("/api/admin/cheap-classes/{$class->id}/sessions/{$session->id}/request-revision", [
                'reason' => 'Tambahkan penjelasan hasil latihan murid agar laporan lebih spesifik.',
            ])
            ->assertOk();
        $this->assertSame('revision_requested', $session->fresh()->status);

        Sanctum::actingAs($teacher);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'cheap_teacher_revision_requested')
            ->assertJsonPath('data.admin_review_notes', 'Tambahkan penjelasan hasil latihan murid agar laporan lebih spesifik.')
            ->assertJsonPath('data.target_url', "/guru/kelas?class_kind=group&cheap_class={$class->id}&cheap_session={$session->id}&cheap_action=revision");

        $payload['session_notes'] = 'Dua murid hadir; keduanya memahami operasi aljabar dasar dan masih perlu penguatan soal cerita.';
        $this->putJson("/api/teacher/cheap-classes/{$class->id}/progress", $payload)
            ->assertOk()
            ->assertJsonPath('session.status', 'awaiting_admin_verification');

        Sanctum::actingAs($admin);
        $this->withHeader('Idempotency-Key', 'demo-cheap-class-verify-0001')
            ->postJson("/api/admin/cheap-classes/{$class->id}/sessions/{$session->id}/verify", [
                'notes' => 'Laporan sudah jelas dan sesi dikonfirmasi.',
            ])
            ->assertOk()
            ->assertJsonPath('class_status', 'completed');

        $this->assertSame('completed', $session->fresh()->status);
        $this->assertSame('completed', $class->fresh()->status);
        $this->assertSame('completed', $class->fresh()->subjects[0]['progress_status']);

        Sanctum::actingAs($student);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'cheap_student_class_completed')
            ->assertJsonPath('data.cheap_class_id', $class->id)
            ->assertJsonPath('data.informational', true);
        $this->assertNotNull($this->getJson('/api/session-action/next')->json('data.notification_id'));

        $this->getJson("/api/student/cheap-classes/{$class->id}?scope=progress")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('subjects.0.progress_status', 'completed');
    }

    public function test_pre_payment_demo_can_start_before_payment_without_waiting_for_admin_opening_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 14:42:00', 'Asia/Jakarta'));
        Storage::fake('local');

        $this->artisan('demo:cheap-class', ['stage' => 'pre-payment'])
            ->assertSuccessful();

        $student = User::query()->where('email', 'demo.student@bimbelku.local')->firstOrFail();
        $teacher = User::query()->where('email', 'demo.tutor@bimbelku.local')->firstOrFail();
        $class = CheapClass::query()->where('package_code', 'like', 'DEMO-KM-PREPAY-%')->firstOrFail();
        $session = CheapClassSession::query()->where('cheap_class_id', $class->id)->firstOrFail();

        $this->assertSame('open', $class->status);
        $this->assertTrue($class->registration_opens_at->lte(now()));
        $this->assertTrue($class->registration_deadline->isFuture());
        $this->assertTrue($session->starts_at->isFuture());
        $this->assertSame(1, $class->enrollments()->where('status', 'confirmed')->count());
        $this->assertFalse($class->enrollments()->where('student_id', $student->id)->exists());

        Sanctum::actingAs($student);
        $this->getJson('/api/student/cheap-classes')
            ->assertOk()
            ->assertJsonPath('0.id', $class->id)
            ->assertJsonPath('0.status', 'open')
            ->assertJsonPath('0.can_join', true)
            ->assertJsonPath('0.enrollment', null);

        $this->postJson(
            "/api/student/cheap-classes/{$class->id}/join",
            [],
            ['Idempotency-Key' => 'demo-cheap-class-prepay-join-0001']
        )
            ->assertCreated()
            ->assertJsonPath('enrollment_status', 'seat_held')
            ->assertJsonPath('order_kind', 'cheap_class');

        $this->assertSame('registration_closed', $class->fresh()->status);

        $this->artisan('demo:cheap-class', ['stage' => 'payment-submitted'])
            ->assertSuccessful();
        $enrollment = $class->enrollments()->where('student_id', $student->id)->with('order')->firstOrFail();
        $this->assertSame('payment_submitted', $enrollment->fresh()->status);
        $this->assertSame('submitted', $enrollment->order->fresh()->status);
        $this->assertNotNull($enrollment->order->fresh()->payment_proof);
        Storage::disk('local')->assertExists($enrollment->order->fresh()->payment_proof);

        $this->artisan('demo:cheap-class', ['stage' => 'payment-paid'])
            ->assertSuccessful();
        $this->assertSame('confirmed', $class->fresh()->status);
        $this->assertSame('confirmed', $enrollment->fresh()->status);
        $this->assertSame('paid', $enrollment->order->fresh()->status);

        $this->artisan('demo:cheap-class', ['stage' => 'session-live'])
            ->assertSuccessful();
        $session->refresh();
        $this->assertSame('scheduled', $session->status);
        $this->assertTrue($session->starts_at->lte(now()));
        $this->assertTrue($session->ends_at->gt(now()));

        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/cheap-classes/{$class->id}/start-session")
            ->assertOk()
            ->assertJsonPath('session.status', 'in_progress');
        $this->assertNotNull($session->fresh()->teacher_started_at);

        Sanctum::actingAs($student);
        $this->getJson('/api/session-action/next')
            ->assertOk()
            ->assertJsonPath('data.kind', 'cheap_student_session_started');

        $this->artisan('demo:cheap-class', ['stage' => 'session-ended'])
            ->assertSuccessful();
        $this->assertSame('report_required', $session->fresh()->status);
    }
}
