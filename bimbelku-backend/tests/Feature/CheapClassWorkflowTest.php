<?php

namespace Tests\Feature;

use App\Models\CheapClass;
use App\Models\CheapClassEnrollment;
use App\Models\CheapClassSession;
use App\Models\CheapClassTemplate;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Refund;
use App\Models\TeacherAvailability;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\CheapClassService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CheapClassWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_creates_multi_session_class_with_catalog_chapter_custom_price_and_automatic_teacher(): void
    {
        $subject = CurriculumSubject::create([
            'name' => 'Matematika',
            'normalized_name' => 'matematika',
            'education_levels' => ['SD'],
            'grades' => ['Kelas 6'],
            'is_active' => true,
        ]);
        $chapter = CurriculumChapter::create([
            'curriculum_subject_id' => $subject->id,
            'education_level' => 'SD',
            'grade' => 'Kelas 6',
            'title' => 'Pecahan',
            'normalized_title' => 'pecahan',
            'is_active' => true,
        ]);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'verified_at' => now(),
            'is_accepting_requests' => true,
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $subject->id,
            'name' => 'Matematika',
            'levels' => ['SD'],
            'is_active' => true,
            'is_online' => true,
            'is_group_active' => true,
        ]);

        $firstSession = now()->addDays(4)->setTime(10, 0);
        $day = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'][$firstSession->dayOfWeekIso];
        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => $day,
            'slots' => [['start_time' => '10:00', 'end_time' => '11:00']],
            'is_active' => true,
        ]);
        $result = app(CheapClassService::class)->createPackage([
            'curriculum_subject_id' => $subject->id,
            'curriculum_chapter_id' => $chapter->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SD',
            'grade' => 'Kelas 6',
            'chapter' => 'Pecahan',
            'first_session_date' => $firstSession->toDateString(),
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'session_count' => 4,
            'price_per_session' => 25000,
            'custom_price_per_student' => 75000,
            'price_per_student' => 75000,
            'minimum_participants' => 2,
            'maximum_participants' => 6,
            'registration_window_hours' => 168,
            'registration_closes_before_minutes' => 60,
            'payment_window_minutes' => 60,
        ]);
        $template = $result['template'];
        $class = $result['class'];
        $this->assertSame($teacher->id, $class->teacher_id);
        $this->assertSame(1, CheapClass::query()->where('cheap_class_template_id', $template->id)->count());
        $this->assertFalse($template->is_active);
        $this->assertSame('open', $class->status);
        $this->assertSame(4, $class->sessions()->count());
        $this->assertSame('75000.00', $class->price_per_student);
        $this->assertSame($chapter->id, $class->curriculum_chapter_id);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        [$enrollment, $order] = app(CheapClassService::class)->join($class, $student);

        $this->assertEqualsWithDelta(3600, $enrollment->seat_expires_at->getTimestamp() - $enrollment->created_at->getTimestamp(), 1);

        $this->assertSame($class->id, $enrollment->cheap_class_id);
        $this->assertSame('75000.00', $order->amount);
        $this->assertSame(1, Order::query()->where('cheap_class_enrollment_id', $enrollment->id)->count());
        $this->assertSame(4, $order->class_details_snapshot['session_count']);
        $this->assertSame('package', $order->class_details_snapshot['payment_scope']);
        $this->assertSame('once', $order->class_details_snapshot['payment_frequency']);

        // Pembatalan sebelum bukti dikirim hanya melepas kursi. Murid boleh
        // bergabung lagi dan record lama dipakai ulang agar tidak menduplikasi data.
        app(CheapClassService::class)->cancelEnrollment($enrollment, $student);
        $this->assertSame('cancelled', $enrollment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertTrue(app(CheapClassService::class)->studentPayload($class->fresh(), $student->id)['can_join']);

        [$rejoinedEnrollment, $rejoinedOrder] = app(CheapClassService::class)->join($class->fresh(), $student);
        $this->assertSame($enrollment->id, $rejoinedEnrollment->id);
        $this->assertSame($order->id, $rejoinedOrder->id);
        $this->assertSame('seat_held', $rejoinedEnrollment->status);
        $this->assertSame('pending', $rejoinedOrder->status);

        Sanctum::actingAs($student);
        $this->getJson('/api/active-order')
            ->assertOk()
            ->assertJsonPath('order_id', $rejoinedOrder->id)
            ->assertJsonPath('order_kind', 'cheap_class')
            ->assertJsonPath('enrollment_status', 'seat_held')
            ->assertJsonPath('can_cancel', true)
            ->assertJsonPath('will_refund_if_accepted', false);

        $this->assertDatabaseCount('cheap_class_enrollments', 1);
        $this->assertDatabaseCount('orders', 1);

        // Kapasitas maksimum dikunci saat pengambilan kursi. Setelah enam
        // kursi aktif, murid ketujuh tidak pernah mendapat tagihan.
        foreach (range(1, 5) as $index) {
            $extraStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
            app(CheapClassService::class)->join($class->fresh(), $extraStudent);
        }
        $overflowStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        try {
            app(CheapClassService::class)->join($class->fresh(), $overflowStudent);
            $this->fail('Murid ketujuh tidak boleh memperoleh kursi pada kelas dengan kapasitas enam.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertTrue(
                str_contains($exception->getMessage(), 'Kuota Kelas Kelompok sudah penuh.')
                    || str_contains($exception->getMessage(), 'Pendaftaran Kelas Kelompok sudah ditutup'),
                'Murid ketujuh harus ditolak sebelum tagihan dibuat.'
            );
        }
        $this->assertSame(6, $class->enrollments()->whereIn('status', ['seat_held', 'payment_submitted', 'payment_rejected', 'confirmed'])->count());
        $this->assertSame('registration_closed', $class->fresh()->status);
    }

    public function test_payment_proof_submitted_before_seat_deadline_remains_safe_during_verification(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass();
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'seat_held',
            'seat_expires_at' => now()->addMinute(),
        ]);
        $order = $this->makeOrder($student, $enrollment);

        app(CheapClassService::class)->submitPayment($order, [
            'sender_name' => 'Murid Penguji',
            'bank_name' => 'Bank Uji',
            'sender_account_number' => '12345678',
        ], 'payment_proofs/test-proof.png');

        $enrollment->update(['seat_expires_at' => now()->subMinute()]);
        app(CheapClassService::class)->refreshLifecycle();

        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('payment_submitted', $enrollment->fresh()->status);
    }

    public function test_rejected_proof_can_be_resubmitted_while_its_seat_and_registration_are_valid(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'status' => 'awaiting_verification',
            'maximum_participants' => 2,
            'registration_deadline' => now()->addHour(),
        ]);
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'payment_submitted',
            'seat_expires_at' => now()->addMinutes(30),
            'payment_submitted_at' => now(),
        ]);
        $order = $this->makeOrder($student, $enrollment, 'submitted');

        $result = app(CheapClassService::class)->verifyPayment(
            $order,
            'rejected',
            'Nominal pada bukti belum terbaca.',
            $admin
        );

        $this->assertSame('Pembayaran ditolak. Murid masih dapat mengirim ulang.', $result['message']);
        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertSame('payment_rejected', $enrollment->fresh()->status);
    }

    public function test_late_admin_verification_never_confirms_a_package_after_its_first_session_started(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'status' => 'registration_closed',
            'minimum_participants' => 1,
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addMinutes(50),
            'registration_deadline' => now()->subHour(),
        ]);
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'payment_submitted',
            'seat_expires_at' => now()->subMinutes(5),
            'payment_submitted_at' => now()->subMinutes(20),
        ]);
        $order = $this->makeOrder($student, $enrollment, 'submitted');

        $result = app(CheapClassService::class)->verifyPayment($order, 'paid', '', $admin);

        $this->assertSame('Pembayaran valid, tetapi sesi pertama sudah dimulai. Refund penuh masuk antrean admin.', $result['message']);
        $this->assertSame('cancelled', $class->fresh()->status);
        $this->assertSame('refund_pending', $order->fresh()->status);
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
    }

    public function test_admin_can_create_a_package_today_and_it_remains_visible_while_waiting_for_teacher(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 08:15:00', 'Asia/Jakarta'));
        [$subject, $chapter] = $this->makeCatalog();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($subject, $chapter, [
            'first_session_date' => '2026-08-11',
            'start_time' => '11:00',
        ]), ['Idempotency-Key' => 'cheap-class-create-today-0001']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'waiting_teacher')
            ->assertJsonPath('data.session_count', 4)
            ->assertJsonPath('data.teacher', null);
        $this->assertDatabaseCount('cheap_classes', 1);
        $this->assertDatabaseCount('cheap_class_sessions', 4);
        $this->assertDatabaseHas('cheap_class_templates', ['is_active' => false]);

        $this->getJson('/api/admin/cheap-classes/schedule?view=packages')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'waiting_teacher')
            ->assertJsonPath('data.0.teacher', null)
            ->assertJsonPath('data.0.teacher_status_message', fn ($message) => str_contains($message, 'otomatis setiap menit'));

        Carbon::setTestNow();
    }

    public function test_admin_can_choose_one_to_three_subjects_and_teacher_must_cover_all_selected_subjects(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 08:15:00', 'Asia/Jakarta'));
        $catalog = collect(['Matematika', 'Bahasa Indonesia', 'IPA', 'Bahasa Inggris'])
            ->map(function (string $name, int $index) {
                $subject = CurriculumSubject::create([
                    'name' => $name,
                    'normalized_name' => mb_strtolower(str_replace(' ', '-', $name)),
                    'education_levels' => ['SD'],
                    'grades' => ['Kelas 6'],
                    'is_active' => true,
                ]);
                $chapter = CurriculumChapter::create([
                    'curriculum_subject_id' => $subject->id,
                    'education_level' => 'SD',
                    'grade' => 'Kelas 6',
                    'title' => 'Bab '.($index + 1),
                    'normalized_title' => 'bab-'.($index + 1),
                    'is_active' => true,
                ]);

                return [$subject, $chapter];
            });

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        [$primarySubject, $primaryChapter] = $catalog[0];
        $subjectsPayload = $catalog->take(3)->map(fn ($pair) => [
            'subject_name' => $pair[0]->name,
            'curriculum_chapter_id' => $pair[1]->id,
        ])->values()->all();

        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($primarySubject, $primaryChapter, [
            'subjects' => array_slice($subjectsPayload, 0, 2),
            'first_session_date' => '2026-08-17',
            'start_time' => '10:00',
            'session_count' => 1,
            'weekdays' => [1],
        ]), ['Idempotency-Key' => 'cheap-class-one-session-two-subjects-rejected'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paket 1 sesi maksimal memiliki 1 mata pelajaran.');

        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($primarySubject, $primaryChapter, [
            'subjects' => $subjectsPayload,
            'first_session_date' => '2026-08-17',
            'start_time' => '10:00',
            'session_count' => 8,
            'weekdays' => [1],
        ]), ['Idempotency-Key' => 'cheap-class-eight-sessions-three-subjects-rejected'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paket 8 sesi maksimal memiliki 2 mata pelajaran.');
        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($primarySubject, $primaryChapter, [
            'subjects' => $subjectsPayload,
            'first_session_date' => '2026-08-17',
            'start_time' => '10:00',
            'session_count' => 12,
            'weekdays' => [1],
        ]), ['Idempotency-Key' => 'cheap-class-three-subjects-0001'])
            ->assertCreated()
            ->assertJsonCount(3, 'data.subjects')
            ->assertJsonPath('data.subject_name', 'Matematika + Bahasa Indonesia + IPA');

        $class = CheapClass::query()->firstOrFail();
        $this->assertCount(3, $class->subjects);
        $this->assertSame('waiting_teacher', $class->status);

        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'verified_at' => now(),
            'is_accepting_requests' => true,
        ]);
        foreach ($catalog->take(2) as [$subject]) {
            TeacherSubject::create([
                'teacher_profile_id' => $profile->id,
                'curriculum_subject_id' => $subject->id,
                'name' => $subject->name,
                'levels' => ['SD'],
                'is_active' => true,
                'is_online' => true,
                'is_group_active' => true,
            ]);
        }
        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => 'Senin',
            'slots' => [['start_time' => '10:00', 'end_time' => '11:00']],
            'is_active' => true,
        ]);

        $this->assertNull(app(CheapClassService::class)->replaceTeacherIfNeeded($class->fresh()));
        $thirdSubject = $catalog[2][0];
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $thirdSubject->id,
            'name' => $thirdSubject->name,
            'levels' => ['SD'],
            'is_active' => true,
            'is_online' => true,
            'is_group_active' => true,
        ]);
        $this->assertSame($teacher->id, app(CheapClassService::class)->replaceTeacherIfNeeded($class->fresh())?->id);

        $fourSubjects = $catalog->map(fn ($pair) => [
            'subject_name' => $pair[0]->name,
            'curriculum_chapter_id' => $pair[1]->id,
        ])->values()->all();
        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($primarySubject, $primaryChapter, [
            'subjects' => $fourSubjects,
            'first_session_date' => '2026-08-24',
            'start_time' => '10:00',
            'session_count' => 12,
            'weekdays' => [1],
        ]), ['Idempotency-Key' => 'cheap-class-four-subjects-rejected-0001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subjects']);

        Carbon::setTestNow();
    }

    public function test_admin_can_choose_up_to_four_weekdays_and_twelve_sessions_follow_them_in_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 08:15:00', 'Asia/Jakarta'));
        [$subject, $chapter] = $this->makeCatalog();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($subject, $chapter, [
            'first_session_date' => '2026-08-17',
            'start_time' => '10:00',
            'session_count' => 12,
            'weekdays' => [1, 3, 5],
        ]), ['Idempotency-Key' => 'cheap-class-weekdays-0001'])
            ->assertCreated()
            ->assertJsonPath('data.session_count', 12);

        $this->assertSame(
            [
                '2026-08-17', '2026-08-19', '2026-08-21',
                '2026-08-24', '2026-08-26', '2026-08-28',
                '2026-08-31', '2026-09-02', '2026-09-04',
                '2026-09-07', '2026-09-09', '2026-09-11',
            ],
            CheapClassSession::query()
                ->orderBy('session_number')
                ->get()
                ->map(fn (CheapClassSession $session) => $session->starts_at->toDateString())
                ->all()
        );
        $this->assertSame([1, 3, 5], CheapClassTemplate::query()->firstOrFail()->recurrence_days);

        Carbon::setTestNow();
    }

    public function test_admin_cannot_choose_more_than_four_weekdays_or_exclude_the_first_session_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 08:15:00', 'Asia/Jakarta'));
        [$subject, $chapter] = $this->makeCatalog();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($subject, $chapter, [
            'first_session_date' => '2026-08-17',
            'weekdays' => [],
        ]), ['Idempotency-Key' => 'cheap-class-no-days-0001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weekdays']);

        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($subject, $chapter, [
            'first_session_date' => '2026-08-17',
            'weekdays' => [1, 2, 3, 4, 5],
        ]), ['Idempotency-Key' => 'cheap-class-too-many-days-0001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weekdays']);

        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($subject, $chapter, [
            'first_session_date' => '2026-08-17',
            'weekdays' => [2, 4],
        ]), ['Idempotency-Key' => 'cheap-class-first-day-mismatch-0001'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tanggal sesi pertama harus sesuai dengan salah satu hari belajar yang dipilih.');

        $this->assertDatabaseCount('cheap_classes', 0);
        Carbon::setTestNow();
    }

    public function test_waiting_package_gets_a_teacher_automatically_when_teacher_schedule_becomes_compatible(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 08:15:00', 'Asia/Jakarta'));
        [$subject, $chapter] = $this->makeCatalog();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($subject, $chapter, [
            'first_session_date' => '2026-08-17',
            'start_time' => '10:00',
            'session_count' => 8,
            'weekdays' => [1, 3],
        ]), ['Idempotency-Key' => 'cheap-class-auto-teacher-0001'])->assertCreated();
        $class = CheapClass::query()->firstOrFail();
        $this->assertSame('waiting_teacher', $class->status);

        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'verified_at' => now(),
            'is_accepting_requests' => true,
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $subject->id,
            'name' => $subject->name,
            'levels' => ['SD'],
            'is_active' => true,
            'is_online' => true,
            'is_group_active' => true,
        ]);
        $schedules = collect(['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'])
            ->map(fn (string $day) => [
                'day' => $day,
                'is_active' => in_array($day, ['Senin', 'Rabu'], true),
                'ranges' => in_array($day, ['Senin', 'Rabu'], true)
                    ? [['start_time' => '10:00', 'end_time' => '11:00']]
                    : [],
            ])->all();

        Sanctum::actingAs($teacher);
        $this->postJson('/api/teacher/schedule', ['schedules' => $schedules])->assertOk();

        $this->assertSame($teacher->id, $class->fresh()->teacher_id);
        $this->assertSame('open', $class->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_admin_cannot_create_a_package_with_a_passed_time_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 10:15:00', 'Asia/Jakarta'));
        [$subject, $chapter] = $this->makeCatalog();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($subject, $chapter, [
            'first_session_date' => '2026-08-11',
            'start_time' => '10:00',
        ]), ['Idempotency-Key' => 'cheap-class-past-time-0001'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih jam mulai yang belum lewat.');

        $this->assertDatabaseCount('cheap_class_templates', 0);
        $this->assertDatabaseCount('cheap_classes', 0);
        Carbon::setTestNow();
    }

    public function test_admin_cannot_create_a_session_that_ends_on_the_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 08:15:00', 'Asia/Jakarta'));
        [$subject, $chapter] = $this->makeCatalog();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/cheap-class-templates', $this->packagePayload($subject, $chapter, [
            'first_session_date' => '2026-08-12',
            'start_time' => '23:00',
        ]), ['Idempotency-Key' => 'cheap-class-late-session-0001'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sesi harus selesai pada hari yang sama. Pilih jam mulai paling lambat 22.00.');

        $this->assertDatabaseCount('cheap_class_templates', 0);
        $this->assertDatabaseCount('cheap_classes', 0);
        Carbon::setTestNow();
    }

    public function test_replayed_admin_package_request_creates_only_one_package(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 08:15:00', 'Asia/Jakarta'));
        [$subject, $chapter] = $this->makeCatalog();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $payload = $this->packagePayload($subject, $chapter, [
            'first_session_date' => '2026-08-12',
            'start_time' => '14:00',
        ]);
        $headers = ['Idempotency-Key' => 'cheap-class-create-once-0001'];

        $first = $this->postJson('/api/admin/cheap-class-templates', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/admin/cheap-class-templates', $payload, $headers)->assertCreated();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertDatabaseCount('cheap_class_templates', 1);
        $this->assertDatabaseCount('cheap_classes', 1);
        $this->assertDatabaseCount('cheap_class_sessions', 4);
        Carbon::setTestNow();
    }

    public function test_student_cannot_cancel_after_payment_proof_submission(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass();
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'payment_submitted',
            'seat_expires_at' => now()->addHour(),
            'payment_submitted_at' => now(),
        ]);
        $order = $this->makeOrder($student, $enrollment, 'submitted');
        $order->update(['payment_proof' => 'payment_proofs/submitted-proof.png']);
        Sanctum::actingAs($student);

        $this->postJson("/api/student/cheap-class-enrollments/{$enrollment->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Keikutsertaan tidak dapat dibatalkan setelah bukti pembayaran dikirim atau pembayaran diterima.');

        $this->assertSame('payment_submitted', $enrollment->fresh()->status);
        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('payment_proofs/submitted-proof.png', $order->fresh()->payment_proof);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_student_cannot_cancel_after_payment_is_verified(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass();
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'confirmed',
            'seat_expires_at' => now()->addHour(),
            'confirmed_at' => now(),
        ]);
        $order = $this->makeOrder($student, $enrollment, 'paid');
        Sanctum::actingAs($student);

        $this->postJson("/api/student/cheap-class-enrollments/{$enrollment->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Keikutsertaan tidak dapat dibatalkan setelah bukti pembayaran dikirim atau pembayaran diterima.');

        $this->assertSame('confirmed', $enrollment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_system_cancellation_keeps_submitted_proof_for_review_and_refunds_only_when_valid(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $class = $this->makeCheapClass(['status' => 'registration_closed']);
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'payment_submitted',
            'seat_expires_at' => now()->addHour(),
            'payment_submitted_at' => now(),
        ]);
        $order = $this->makeOrder($student, $enrollment, 'submitted');
        $order->update(['payment_proof' => 'payment_proofs/system-cancel-review.png']);
        $service = app(CheapClassService::class);

        $service->cancelClass($class, 'Kelas dibatalkan penyelenggara.');

        $this->assertSame('cancelled', $class->fresh()->status);
        $this->assertSame('cancellation_pending', $enrollment->fresh()->status);
        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('payment_proofs/system-cancel-review.png', $order->fresh()->payment_proof);
        $this->assertDatabaseCount('refunds', 0);

        Sanctum::actingAs($student);
        $this->getJson("/api/student/orders/{$order->id}/status")
            ->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('order_kind', 'cheap_class')
            ->assertJsonPath('enrollment_status', 'cancellation_pending')
            ->assertJsonPath('cheap_class_status', 'cancelled')
            ->assertJsonPath('can_cancel', false)
            ->assertJsonPath('will_refund_if_accepted', true);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/finance/payments')
            ->assertOk()
            ->assertJsonPath('pending.0.id', $order->id)
            ->assertJsonPath('pending.0.is_cheap_class', true)
            ->assertJsonPath('pending.0.enrollment_status', 'cancellation_pending')
            ->assertJsonPath('pending.0.cheap_class_status', 'cancelled')
            ->assertJsonPath('pending.0.can_resubmit_if_rejected', false)
            ->assertJsonPath('pending.0.will_refund_if_accepted', true);

        $result = $service->verifyPayment($order->fresh(), 'paid', '', $admin);

        $this->assertSame('Pembayaran valid pada kelas yang sudah dibatalkan. Refund penuh masuk antrean admin.', $result['message']);
        $this->assertSame('refund_pending', $enrollment->fresh()->status);
        $this->assertSame('refund_pending', $order->fresh()->status);
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
    }

    public function test_system_cancelled_submitted_proof_rejected_finishes_without_refund(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $class = $this->makeCheapClass(['status' => 'registration_closed']);
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'payment_submitted',
            'seat_expires_at' => now()->addHour(),
            'payment_submitted_at' => now(),
        ]);
        $order = $this->makeOrder($student, $enrollment, 'submitted');
        $order->update(['payment_proof' => 'payment_proofs/system-cancel-invalid.png']);
        $service = app(CheapClassService::class);

        $service->cancelClass($class, 'Kelas dibatalkan penyelenggara.');
        $result = $service->verifyPayment($order->fresh(), 'rejected', 'Bukti tidak menunjukkan transfer yang valid.', $admin);

        $this->assertSame('Bukti ditolak dan pembatalan kelas diselesaikan tanpa refund.', $result['message']);
        $this->assertSame('cancelled', $enrollment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_second_verification_guard_refunds_anomalous_payment_above_maximum_capacity(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $firstStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $overflowStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'status' => 'registration_closed',
            'minimum_participants' => 1,
            'maximum_participants' => 1,
        ]);

        $confirmedEnrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $firstStudent->id,
            'amount' => 25000,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        $this->makeOrder($firstStudent, $confirmedEnrollment, 'paid');

        // Record kedua mensimulasikan data legacy/anomali yang lolos sebelum
        // pengaman join. Verifikasi tidak boleh menambah peserta kedua.
        $overflowEnrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $overflowStudent->id,
            'amount' => 25000,
            'status' => 'payment_submitted',
            'seat_expires_at' => now()->addHour(),
            'payment_submitted_at' => now(),
        ]);
        $overflowOrder = $this->makeOrder($overflowStudent, $overflowEnrollment, 'submitted');
        $overflowOrder->update(['payment_proof' => 'payment_proofs/overflow.png']);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/finance/payments')
            ->assertOk()
            ->assertJsonPath('pending.0.id', $overflowOrder->id)
            ->assertJsonPath('pending.0.can_resubmit_if_rejected', false)
            ->assertJsonPath('pending.0.will_refund_if_accepted', true)
            ->assertJsonPath('pending.0.refund_reason_if_accepted', 'capacity_full');

        Sanctum::actingAs($overflowStudent);
        $this->getJson("/api/student/orders/{$overflowOrder->id}/status")
            ->assertOk()
            ->assertJsonPath('will_refund_if_accepted', true)
            ->assertJsonPath('refund_reason_if_accepted', 'capacity_full');

        $result = app(CheapClassService::class)->verifyPayment($overflowOrder, 'paid', '', $admin);

        $this->assertSame('Pembayaran valid, tetapi kuota maksimum sudah terisi. Refund penuh masuk antrean admin.', $result['message']);
        $this->assertSame(1, $class->enrollments()->where('status', 'confirmed')->count());
        $this->assertSame('refund_pending', $overflowEnrollment->fresh()->status);
        $this->assertSame('refund_pending', $overflowOrder->fresh()->status);
        $this->assertSame(1, Refund::query()->where('order_id', $overflowOrder->id)->count());
    }

    public function test_rejecting_anomalous_payment_after_capacity_is_full_ends_the_seat(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $firstStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $overflowStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'status' => 'registration_closed',
            'minimum_participants' => 1,
            'maximum_participants' => 1,
        ]);

        $confirmedEnrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $firstStudent->id,
            'amount' => 25000,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        $this->makeOrder($firstStudent, $confirmedEnrollment, 'paid');

        $overflowEnrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $overflowStudent->id,
            'amount' => 25000,
            'status' => 'payment_submitted',
            'seat_expires_at' => now()->addHour(),
            'payment_submitted_at' => now(),
        ]);
        $overflowOrder = $this->makeOrder($overflowStudent, $overflowEnrollment, 'submitted');
        $overflowOrder->update(['payment_proof' => 'payment_proofs/overflow-invalid.png']);

        $result = app(CheapClassService::class)->verifyPayment(
            $overflowOrder,
            'rejected',
            'Transfer tidak ditemukan pada mutasi rekening admin.',
            $admin
        );

        $this->assertSame('Pembayaran ditolak dan kursi berakhir.', $result['message']);
        $this->assertSame('payment_expired', $overflowEnrollment->fresh()->status);
        $this->assertSame('expired', $overflowOrder->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_admin_can_delete_an_empty_future_package_with_all_of_its_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $template = $this->makeTemplate();
        $class = $this->makeCheapClass([
            'cheap_class_template_id' => $template->id,
            'session_count' => 4,
        ]);
        foreach (range(1, 4) as $number) {
            CheapClassSession::create([
                'cheap_class_id' => $class->id,
                'session_number' => $number,
                'starts_at' => $class->starts_at->copy()->addWeeks($number - 1),
                'ends_at' => $class->ends_at->copy()->addWeeks($number - 1),
                'status' => 'scheduled',
            ]);
        }
        $sessionIds = $class->sessions()->pluck('id')->all();
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/cheap-classes/{$class->id}")
            ->assertOk()
            ->assertJsonPath('removed_sessions', 4)
            ->assertJsonPath('removed_template', true);

        $this->assertDatabaseMissing('cheap_classes', ['id' => $class->id]);
        $this->assertDatabaseMissing('cheap_class_templates', ['id' => $template->id]);
        foreach ($sessionIds as $sessionId) {
            $this->assertDatabaseMissing('cheap_class_sessions', ['id' => $sessionId]);
        }

        app(CheapClassService::class)->maintain();
        $this->assertDatabaseMissing('cheap_classes', ['id' => $class->id]);
        $this->assertDatabaseMissing('cheap_class_templates', ['id' => $template->id]);
    }

    public function test_admin_can_cancel_a_future_package_and_schedule_exposes_the_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $class = $this->makeCheapClass(['status' => 'open']);
        CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 1,
            'starts_at' => $class->starts_at,
            'ends_at' => $class->ends_at,
            'status' => 'scheduled',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/cheap-classes/schedule?view=packages')
            ->assertOk()
            ->assertJsonPath('data.0.id', $class->id)
            ->assertJsonPath('data.0.can_cancel', true);

        $this->postJson("/api/admin/cheap-classes/{$class->id}/cancel", [
            'reason' => 'Jadwal operasional dibatalkan admin.',
        ], ['Idempotency-Key' => 'cheap-class-admin-cancel-0001'])->assertOk()
            ->assertJsonPath('message', 'Paket Kelas Kelompok berhasil dibatalkan.');

        $this->assertSame('cancelled', $class->fresh()->status);
        $this->assertSame('Jadwal operasional dibatalkan admin.', $class->fresh()->cancellation_reason);
        $this->assertSame('cancelled', $class->sessions()->first()->status);
    }

    public function test_admin_cancel_of_paid_future_package_queues_refund(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass(['status' => 'confirmed']);
        $session = CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 1,
            'starts_at' => $class->starts_at,
            'ends_at' => $class->ends_at,
            'status' => 'scheduled',
        ]);
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        $order = $this->makeOrder($student, $enrollment, 'paid');
        Sanctum::actingAs($admin);

        $this->postJson(
            "/api/admin/cheap-classes/{$class->id}/cancel",
            [],
            ['Idempotency-Key' => 'cheap-class-admin-cancel-paid-0001']
        )->assertOk();

        $this->assertSame('cancelled', $class->fresh()->status);
        $this->assertSame('refund_pending', $order->fresh()->status);
        $this->assertSame('refund_pending', $enrollment->fresh()->status);
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
    }

    public function test_admin_cannot_cancel_a_package_after_the_first_session_started(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 17:00:00'));
        try {
            $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
            $class = $this->makeCheapClass([
                'status' => 'confirmed',
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHour(),
            ]);
            CheapClassSession::create([
                'cheap_class_id' => $class->id,
                'session_number' => 1,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHour(),
                'status' => 'scheduled',
            ]);
            Sanctum::actingAs($admin);

            $this->getJson('/api/admin/cheap-classes/schedule?view=packages')
                ->assertOk()
                ->assertJsonPath('data.0.can_cancel', false)
                ->assertJsonPath('data.0.cancel_block_reason', 'Paket sudah dimulai sehingga tidak dapat dibatalkan dari halaman jadwal.');

            $this->postJson(
                "/api/admin/cheap-classes/{$class->id}/cancel",
                [],
                ['Idempotency-Key' => 'cheap-class-admin-cancel-started-0001']
            )->assertStatus(422)
                ->assertJsonPath('message', 'Paket sudah dimulai sehingga tidak dapat dibatalkan dari halaman jadwal.');

            $this->assertSame('confirmed', $class->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_can_delete_an_empty_cancelled_future_package(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $class = $this->makeCheapClass(['status' => 'cancelled']);
        CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 1,
            'starts_at' => $class->starts_at,
            'ends_at' => $class->ends_at,
            'status' => 'cancelled',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/cheap-classes/schedule?view=packages&status=cancelled')
            ->assertOk()
            ->assertJsonPath('meta.scope', 'history')
            ->assertJsonPath('data.0.id', $class->id)
            ->assertJsonPath('data.0.can_delete', true)
            ->assertJsonPath('data.0.delete_block_reason', null);

        $this->deleteJson("/api/admin/cheap-classes/{$class->id}")
            ->assertOk();

        $this->assertDatabaseMissing('cheap_classes', ['id' => $class->id]);
    }

    public function test_admin_cannot_delete_a_cancelled_package_that_already_started(): void
    {
        Carbon::setTestNow('2026-08-12 16:45:00');
        try {
            $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
            $class = $this->makeCheapClass([
                'status' => 'cancelled',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addHour(),
            ]);
            CheapClassSession::create([
                'cheap_class_id' => $class->id,
                'session_number' => 1,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addHour(),
                'status' => 'cancelled',
            ]);
            Sanctum::actingAs($admin);

            $this->getJson('/api/admin/cheap-classes/schedule?view=packages&status=cancelled')
                ->assertOk()
                ->assertJsonPath('meta.scope', 'history')
                ->assertJsonPath('data.0.id', $class->id)
                ->assertJsonPath('data.0.can_delete', false)
                ->assertJsonPath('data.0.delete_block_reason', 'Paket sudah dimulai sehingga disimpan sebagai riwayat.');

            $this->deleteJson("/api/admin/cheap-classes/{$class->id}")
                ->assertStatus(422)
                ->assertJsonPath('message', 'Paket sudah dimulai sehingga disimpan sebagai riwayat.');

            $this->assertDatabaseHas('cheap_classes', ['id' => $class->id]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_cannot_delete_a_package_that_has_participant_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass();
        CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'seat_held',
            'seat_expires_at' => now()->addHour(),
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/cheap-classes/{$class->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Paket tidak dapat dihapus karena sudah memiliki riwayat peserta.');

        $this->assertDatabaseHas('cheap_classes', ['id' => $class->id]);
    }

    public function test_full_class_waits_for_active_payment_holds_before_finalization(): void
    {
        $class = $this->makeCheapClass([
            'status' => 'registration_closed',
            'minimum_participants' => 2,
            'maximum_participants' => 2,
        ]);

        foreach (range(1, 2) as $index) {
            $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
            CheapClassEnrollment::create([
                'cheap_class_id' => $class->id,
                'student_id' => $student->id,
                'amount' => 25000,
                'status' => 'seat_held',
                'seat_expires_at' => now()->addMinutes(30 + $index),
            ]);
        }

        app(CheapClassService::class)->finalizeIfReady($class);

        $this->assertSame('registration_closed', $class->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_cancellation_creates_only_one_refund_for_each_paid_participant(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass(['status' => 'registration_closed']);
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        $order = $this->makeOrder($student, $enrollment, 'paid');
        $service = app(CheapClassService::class);

        $service->cancelClass($class, 'Tutor pengganti tidak tersedia.');
        $service->cancelClass($class->fresh(), 'Tutor pengganti tidak tersedia.');

        $this->assertSame('cancelled', $class->fresh()->status);
        $this->assertSame('refund_pending', $order->fresh()->status);
        $this->assertSame('refund_pending', $enrollment->fresh()->status);
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
    }

    public function test_cancelling_a_held_seat_reopens_a_full_class_before_deadline(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $otherStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'status' => 'registration_closed',
            'maximum_participants' => 2,
        ]);
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'seat_held',
            'seat_expires_at' => now()->addHour(),
        ]);
        CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $otherStudent->id,
            'amount' => 25000,
            'status' => 'seat_held',
            'seat_expires_at' => now()->addHour(),
        ]);
        $this->makeOrder($student, $enrollment);

        app(CheapClassService::class)->cancelEnrollment($enrollment, $student);
        app(CheapClassService::class)->cancelEnrollment($enrollment->fresh(), $student);

        $this->assertSame('cancelled', $enrollment->fresh()->status);
        $this->assertSame('open', $class->fresh()->status);
    }

    public function test_student_class_list_returns_current_participant_data_without_tutor_before_confirmation(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass();
        CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'seat_held',
            'seat_expires_at' => now()->addHour(),
        ]);

        Sanctum::actingAs($student);
        $this->getJson('/api/student/cheap-classes')
            ->assertOk()
            ->assertJsonPath('0.id', $class->id)
            ->assertJsonPath('0.participant_count', 1)
            ->assertJsonPath('0.occupied_seat_count', 1)
            ->assertJsonPath('0.confirmed_participant_count', 0)
            ->assertJsonPath('0.pending_payment_count', 1)
            ->assertJsonPath('0.teacher', null)
            ->assertJsonPath('0.meeting_link', null);
    }

    public function test_closed_offer_disappears_for_other_students_but_remains_visible_to_its_participant(): void
    {
        $participant = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $otherStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'status' => 'registration_closed',
            'registration_deadline' => now()->addHour(),
        ]);
        CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $participant->id,
            'amount' => 25000,
            'status' => 'seat_held',
            'seat_expires_at' => now()->addMinutes(30),
        ]);

        Sanctum::actingAs($otherStudent);
        $this->getJson('/api/student/cheap-classes')
            ->assertOk()
            ->assertExactJson([]);

        Sanctum::actingAs($participant);
        $this->getJson('/api/student/cheap-classes')
            ->assertOk()
            ->assertJsonPath('0.id', $class->id)
            ->assertJsonPath('0.can_join', false);
    }

    public function test_waiting_teacher_package_is_cancelled_and_paid_participant_receives_one_refund_after_deadline(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'status' => 'waiting_teacher',
            'teacher_id' => null,
            'minimum_participants' => 1,
            'registration_deadline' => now()->subMinute(),
        ]);
        CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 1,
            'starts_at' => $class->starts_at,
            'ends_at' => $class->ends_at,
            'status' => 'scheduled',
        ]);
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        $order = $this->makeOrder($student, $enrollment, 'paid');

        app(CheapClassService::class)->refreshLifecycle();

        $this->assertSame('cancelled', $class->fresh()->status);
        $this->assertSame('cancelled', $class->sessions()->first()->status);
        $this->assertSame('refund_pending', $order->fresh()->status);
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
    }

    public function test_teacher_keeps_seeing_a_multi_session_package_after_the_first_session_ends(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'teacher_id' => $teacher->id,
            'status' => 'confirmed',
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHour(),
            'session_count' => 2,
            'confirmed_at' => now()->subDays(8),
        ]);
        CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 1,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHour(),
            'status' => 'scheduled',
        ]);
        CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 2,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'scheduled',
        ]);
        Sanctum::actingAs($teacher);

        $this->getJson('/api/teacher/cheap-classes')
            ->assertOk()
            ->assertJsonPath('0.id', $class->id)
            ->assertJsonPath('0.sessions.0.status', 'report_required')
            ->assertJsonPath('0.sessions.1.status', 'scheduled');
        $this->assertSame('confirmed', $class->fresh()->status);
        $this->assertTrue(Notification::query()
            ->where('user_id', $teacher->id)
            ->where('unique_key', 'cheap-class-session-report-required:'.$class->sessions()->where('session_number', 1)->value('id').':teacher:'.$teacher->id)
            ->exists());
    }

    public function test_teacher_report_requires_admin_verification_before_student_progress_changes(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $outsider = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'teacher_id' => $teacher->id,
            'status' => 'confirmed',
            'confirmed_at' => now()->subDay(),
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'session_count' => 1,
            'subjects' => [[
                'subject_name' => 'Matematika',
                'chapter' => 'Pecahan',
            ]],
        ]);
        $session = CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 1,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'status' => 'scheduled',
        ]);
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        $this->makeOrder($student, $enrollment, 'paid');
        app(CheapClassService::class)->refreshLifecycle(false);
        $this->assertSame('report_required', $session->fresh()->status);

        Sanctum::actingAs($teacher);
        $this->putJson("/api/teacher/cheap-classes/{$class->id}/progress", [
            'session_id' => $session->id,
            'attended_participants_count' => 1,
            'session_notes' => 'Sesi pertama fokus menuntaskan konsep pecahan.',
            'updates' => [[
                'subject_index' => 0,
                'progress_status' => 'completed',
                'needs_review' => true,
                'progress_notes' => 'Konsep selesai, latihan pecahan campuran perlu diulang.',
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('session.status', 'awaiting_admin_verification');

        $this->assertSame('not_started', $class->fresh()->subjects[0]['progress_status'] ?? 'not_started');
        $session->refresh();
        $this->assertSame('awaiting_admin_verification', $session->status);
        $this->assertSame(1, $session->attended_participants_count);
        $this->assertSame('completed', $session->progress_updates[0]['status_after']);

        Sanctum::actingAs($student);
        $this->getJson('/api/student/cheap-classes?scope=progress')
            ->assertOk()
            ->assertJsonPath('0.progress_summary.progress_percent', 0)
            ->assertJsonPath('0.sessions.0.status', 'awaiting_admin_verification')
            ->assertJsonPath('0.sessions.0.progress_updates', []);

        Sanctum::actingAs($admin);
        $this->withHeader('Idempotency-Key', 'cheap-session-verify-0001')
            ->postJson("/api/admin/cheap-classes/{$class->id}/sessions/{$session->id}/verify", [])
            ->assertOk()
            ->assertJsonPath('class_status', 'completed');

        $this->assertSame('completed', $class->fresh()->subjects[0]['progress_status']);
        $this->assertSame('completed', $session->fresh()->status);

        Sanctum::actingAs($student);
        $this->getJson('/api/student/cheap-classes?scope=progress')
            ->assertOk()
            ->assertJsonPath('0.progress_summary.completed_chapters', 1)
            ->assertJsonPath('0.progress_summary.progress_percent', 100)
            ->assertJsonPath('0.sessions.0.progress_updates.0.status_after', 'completed');

        Sanctum::actingAs($outsider);
        $this->getJson('/api/student/cheap-classes?scope=progress')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_admin_must_verify_cheap_class_reports_in_session_order(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'teacher_id' => $teacher->id,
            'status' => 'confirmed',
            'confirmed_at' => now()->subDay(),
            'starts_at' => now()->subHours(4),
            'ends_at' => now()->subHours(3),
            'session_count' => 2,
            'subjects' => [['subject_name' => 'Matematika', 'chapter' => 'Aljabar']],
        ]);
        $sessionOne = CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 1,
            'starts_at' => now()->subHours(4),
            'ends_at' => now()->subHours(3),
            'status' => 'awaiting_admin_verification',
            'progress_updates' => [[
                'subject_index' => 0,
                'subject_name' => 'Matematika',
                'chapter' => 'Aljabar',
                'status_before' => 'not_started',
                'status_after' => 'in_progress',
                'needs_review_before' => false,
                'needs_review_after' => false,
                'notes' => 'Konsep dasar.',
            ]],
            'progress_notes' => 'Pertemuan pertama membahas konsep dasar aljabar.',
            'progress_recorded_at' => now()->subHours(3),
            'progress_recorded_by' => $teacher->id,
            'report_submitted_at' => now()->subHours(3),
            'report_submitted_by' => $teacher->id,
            'attended_participants_count' => 0,
        ]);
        $sessionTwo = CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 2,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'status' => 'awaiting_admin_verification',
            'progress_updates' => [[
                'subject_index' => 0,
                'subject_name' => 'Matematika',
                'chapter' => 'Aljabar',
                'status_before' => 'not_started',
                'status_after' => 'completed',
                'needs_review_before' => false,
                'needs_review_after' => false,
                'notes' => 'Bab selesai.',
            ]],
            'progress_notes' => 'Pertemuan kedua menuntaskan aljabar.',
            'progress_recorded_at' => now()->subHour(),
            'progress_recorded_by' => $teacher->id,
            'report_submitted_at' => now()->subHour(),
            'report_submitted_by' => $teacher->id,
            'attended_participants_count' => 0,
        ]);

        Sanctum::actingAs($admin);
        $this->withHeader('Idempotency-Key', 'cheap-session-order-0002')
            ->postJson("/api/admin/cheap-classes/{$class->id}/sessions/{$sessionTwo->id}/verify", [])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Verifikasi sesi sebelumnya terlebih dahulu agar riwayat progress tetap berurutan.']);

        $this->withHeader('Idempotency-Key', 'cheap-session-order-0001')
            ->postJson("/api/admin/cheap-classes/{$class->id}/sessions/{$sessionOne->id}/verify", [])
            ->assertOk();
        $this->withHeader('Idempotency-Key', 'cheap-session-order-0003')
            ->postJson("/api/admin/cheap-classes/{$class->id}/sessions/{$sessionTwo->id}/verify", [])
            ->assertOk()
            ->assertJsonPath('class_status', 'completed');

        $this->assertSame('completed', $class->fresh()->subjects[0]['progress_status']);
    }

    public function test_admin_can_request_cheap_class_report_revision_and_teacher_can_resubmit(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'teacher_id' => $teacher->id,
            'status' => 'confirmed',
            'confirmed_at' => now()->subDay(),
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'session_count' => 1,
            'subjects' => [['subject_name' => 'Matematika', 'chapter' => 'Pecahan']],
        ]);
        $session = CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 1,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'status' => 'report_required',
        ]);

        Sanctum::actingAs($teacher);
        $payload = [
            'session_id' => $session->id,
            'attended_participants_count' => 0,
            'session_notes' => 'Kelas tetap berlangsung dan materi direview bersama.',
            'updates' => [[
                'subject_index' => 0,
                'progress_status' => 'in_progress',
                'needs_review' => false,
                'progress_notes' => 'Perlu latihan lanjutan.',
            ]],
        ];
        $this->putJson("/api/teacher/cheap-classes/{$class->id}/progress", $payload)->assertOk();

        Sanctum::actingAs($admin);
        $this->withHeader('Idempotency-Key', 'cheap-session-revise-0001')
            ->postJson("/api/admin/cheap-classes/{$class->id}/sessions/{$session->id}/request-revision", [
                'reason' => 'Jelaskan evaluasi kelas dengan lebih spesifik.',
            ])
            ->assertOk();
        $this->assertSame('revision_requested', $session->fresh()->status);

        Sanctum::actingAs($teacher);
        $payload['session_notes'] = 'Mayoritas murid memahami konsep dasar; latihan soal cerita masih perlu dilanjutkan.';
        $this->putJson("/api/teacher/cheap-classes/{$class->id}/progress", $payload)
            ->assertOk()
            ->assertJsonPath('session.status', 'awaiting_admin_verification');
    }

    public function test_teacher_meeting_link_must_use_official_zoom_domain(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $class = $this->makeCheapClass([
            'teacher_id' => $teacher->id,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 1,
            'starts_at' => $class->starts_at,
            'ends_at' => $class->ends_at,
            'status' => 'scheduled',
        ]);
        Sanctum::actingAs($teacher);

        $this->putJson("/api/teacher/cheap-classes/{$class->id}/meeting-link", [
            'meeting_link' => 'https://example.com/kelas',
        ])->assertStatus(422);

        $this->putJson("/api/teacher/cheap-classes/{$class->id}/meeting-link", [
            'meeting_link' => 'https://us06web.zoom.us/j/123456789',
        ])->assertOk();
        $this->assertSame('https://us06web.zoom.us/j/123456789', $class->fresh()->meeting_link);
    }

    private function makeCheapClass(array $overrides = []): CheapClass
    {
        $startsAt = now()->addDays(2)->startOfHour();

        return CheapClass::create(array_merge([
            'subject_name' => 'Matematika',
            'education_level' => 'SD',
            'grade' => 'Kelas 6',
            'chapter' => 'Pecahan',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'registration_opens_at' => now()->subHour(),
            'registration_deadline' => now()->addDay(),
            'price_per_student' => 25000,
            'minimum_participants' => 2,
            'maximum_participants' => 6,
            'payment_window_minutes' => 60,
            'status' => 'open',
        ], $overrides));
    }

    private function makeTemplate(array $overrides = []): CheapClassTemplate
    {
        return CheapClassTemplate::create(array_merge([
            'subject_name' => 'Matematika',
            'education_level' => 'SD',
            'grade' => 'Kelas 6',
            'chapter' => 'Pecahan',
            'first_session_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'session_count' => 4,
            'recurrence_days' => null,
            'price_per_session' => 25000,
            'price_per_student' => 100000,
            'minimum_participants' => 2,
            'maximum_participants' => 6,
            'registration_window_hours' => 24,
            'registration_closes_before_minutes' => 60,
            'payment_window_minutes' => 60,
            'is_active' => false,
        ], $overrides));
    }

    private function makeCatalog(): array
    {
        $subject = CurriculumSubject::create([
            'name' => 'Matematika',
            'normalized_name' => 'matematika',
            'education_levels' => ['SD'],
            'grades' => ['Kelas 6'],
            'is_active' => true,
        ]);
        $chapter = CurriculumChapter::create([
            'curriculum_subject_id' => $subject->id,
            'education_level' => 'SD',
            'grade' => 'Kelas 6',
            'title' => 'Pecahan',
            'normalized_title' => 'pecahan',
            'is_active' => true,
        ]);

        return [$subject, $chapter];
    }

    private function packagePayload(CurriculumSubject $subject, CurriculumChapter $chapter, array $overrides = []): array
    {
        $payload = array_merge([
            'subject_name' => $subject->name,
            'education_level' => 'SD',
            'grade' => 'Kelas 6',
            'curriculum_chapter_id' => $chapter->id,
            'topic' => null,
            'first_session_date' => now()->addDay()->toDateString(),
            'start_time' => '14:00',
            'duration_minutes' => 60,
            'session_count' => 4,
            'price_per_session' => 25000,
            'use_custom_price' => false,
            'custom_price_per_student' => null,
            'minimum_participants' => 2,
            'maximum_participants' => 6,
            'registration_window_hours' => 24,
            'registration_closes_before_minutes' => 60,
            'payment_window_minutes' => 60,
        ], $overrides);

        if (! array_key_exists('weekdays', $overrides)) {
            $payload['weekdays'] = [Carbon::parse($payload['first_session_date'])->dayOfWeekIso];
        }

        return $payload;
    }

    private function makeOrder(User $student, CheapClassEnrollment $enrollment, string $status = 'pending'): Order
    {
        return Order::create([
            'user_id' => $student->id,
            'cheap_class_enrollment_id' => $enrollment->id,
            'order_id' => 'KM-TEST-'.$enrollment->id,
            'amount' => 25000,
            'subtotal_amount' => 25000,
            'discount_amount' => 0,
            'status' => $status,
            'class_details_snapshot' => ['kind' => 'cheap_class'],
        ]);
    }
}
