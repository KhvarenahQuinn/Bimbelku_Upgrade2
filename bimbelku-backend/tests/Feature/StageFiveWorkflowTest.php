<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\CurriculumSubject;
use App\Models\LearningPackage;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\PaymentSetting;
use App\Models\TeacherAvailability;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CurriculumCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageFiveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_catalog_seeds_subjects_and_chapters_for_every_supported_school_grade(): void
    {
        $this->seed(CurriculumCatalogSeeder::class);

        $this->assertGreaterThanOrEqual(60, CurriculumSubject::query()->where('is_active', true)->count());
        $this->assertDatabaseHas('curriculum_subjects', [
            'normalized_name' => 'akuntansi',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('curriculum_subjects', [
            'normalized_name' => 'komputer dasar',
            'is_active' => true,
        ]);

        CurriculumSubject::query()
            ->whereJsonDoesntContain('education_levels', 'Umum')
            ->with('chapters')
            ->get()
            ->each(function (CurriculumSubject $subject) {
                foreach ($subject->grades ?? [] as $grade) {
                    $this->assertTrue(
                        $subject->chapters->contains('grade', $grade),
                        "{$subject->name} belum mempunyai bab untuk {$grade}."
                    );
                }
            });

        $this->getJson('/api/learning-catalog')
            ->assertOk()
            ->assertJsonStructure([
                'subjects',
                'subject_options' => [['id', 'name', 'education_levels', 'grades']],
                'chapters' => [['id', 'subject_id', 'subject_name', 'education_level', 'grade', 'title']],
                'grades_by_level',
            ])
            ->assertJsonPath('education_levels.3', 'Umum')
            ->assertJsonPath('grades_by_level.Umum.0', 'Semua tingkat')
            ->assertJsonPath('grades_by_level.Umum.1', 'Pemula')
            ->assertJsonPath('grades_by_level.Umum.3', 'Lanjutan');
    }

    public function test_catalog_can_load_compact_data_then_only_the_selected_grade_details(): void
    {
        $this->seed(CurriculumCatalogSeeder::class);

        $this->getJson('/api/learning-catalog?compact=1')
            ->assertOk()
            ->assertJsonCount(0, 'chapters')
            ->assertJsonCount(0, 'topics')
            ->assertJsonStructure([
                'subjects',
                'subject_options' => [['id', 'name', 'education_levels', 'grades']],
                'education_levels',
                'grades_by_level',
            ]);

        $response = $this->getJson(
            '/api/learning-catalog?subject_name=Matematika'
            .'&education_level=SMP&grade=Kelas%207'
        )
            ->assertOk();

        $this->assertNotEmpty($response->json('chapters'));
        collect($response->json('chapters'))->each(function (array $chapter) {
            $this->assertSame('Matematika', $chapter['subject_name']);
            $this->assertSame('SMP', $chapter['education_level']);
            $this->assertSame('Kelas 7', $chapter['grade']);
        });
    }

    public function test_admin_can_add_a_subject_without_creating_case_or_space_duplicates(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/admin/subjects', [
            'name' => 'Kimia Eksperimen',
            'group_name' => 'Tambahan admin',
            'education_levels' => ['SMA'],
            'grades' => ['Kelas 10', 'Kelas 11', 'Kelas 12'],
        ])->assertCreated();

        $duplicate = $this->postJson('/api/admin/subjects', [
            'name' => '  kimia    eksperimen ',
            'group_name' => 'Tambahan admin',
            'education_levels' => ['SMA'],
            'grades' => ['Kelas 10', 'Kelas 11', 'Kelas 12'],
        ])->assertOk();

        $this->assertSame(
            $created->json('data.id'),
            $duplicate->json('data.id')
        );
        $this->assertDatabaseCount('curriculum_subjects', 1);
    }

    public function test_reactivating_inactive_subject_restores_supported_scope(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $subject = CurriculumSubject::create([
            'name' => 'Kimia Eksperimen',
            'normalized_name' => 'kimia eksperimen',
            'group_name' => 'Tambahan admin',
            'education_levels' => [],
            'grades' => [],
            'is_active' => false,
        ]);

        $this->postJson('/api/admin/subjects', [
            'name' => 'Kimia Eksperimen',
            'education_levels' => ['SMA'],
            'grades' => ['Kelas 10', 'Kelas 11', 'Kelas 12'],
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $subject->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.education_levels.0', 'SMA')
            ->assertJsonPath('data.grades.0', 'Kelas 10');
    }

    public function test_subject_rejects_grade_outside_selected_education_level(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/subjects', [
            'name' => 'Biologi SMA',
            'education_levels' => ['SMA'],
            'grades' => ['Kelas 2'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('grades');
    }

    public function test_updating_subject_level_without_grades_refreshes_grade_scope(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $subject = CurriculumSubject::create([
            'name' => 'Fisika Adaptif',
            'normalized_name' => 'fisika adaptif',
            'group_name' => 'Tambahan admin',
            'education_levels' => ['SMP'],
            'grades' => ['Kelas 7', 'Kelas 8', 'Kelas 9'],
            'is_active' => true,
        ]);

        $this->putJson("/api/admin/subjects/{$subject->id}", [
            'name' => 'Fisika Adaptif',
            'education_levels' => ['SMA'],
        ])
            ->assertOk()
            ->assertJsonPath('data.education_levels.0', 'SMA')
            ->assertJsonPath('data.grades.0', 'Kelas 10');

        $subject->refresh();
        $this->assertSame(['Kelas 10', 'Kelas 11', 'Kelas 12'], $subject->grades);
    }

    public function test_retired_legacy_booking_request_endpoint_is_not_exposed(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        Sanctum::actingAs($student);

        $this->postJson('/api/student/booking-requests', [
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'chapter' => 'Seluruh materi mapel',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => now()->addDay()->toDateString(),
            'start_time' => '11:00',
            'duration_hours' => 1,
        ])->assertNotFound();

        $this->assertDatabaseCount('booking_requests', 0);
    }

    public function test_retired_legacy_booking_endpoint_does_not_validate_old_payloads(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        Sanctum::actingAs($student);

        $this->postJson('/api/student/booking-requests', [
            'education_level' => 'Perguruan Tinggi',
            'grade' => 'Semester 1',
        ])->assertNotFound();

        $this->assertDatabaseCount('booking_requests', 0);
    }

    public function test_teacher_acceptance_activates_a_paid_package_without_a_second_student_decision(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 10:03:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
            'phone' => '081200000001',
        ]);
        $profile = tap(TeacherProfile::create([
            'user_id' => $teacher->id,
            'whatsapp_number' => '081200000001',
            'is_accepting_requests' => true,
        ]), fn ($profile) => $profile->forceFill(['verified_at' => now()->subDay()])->save());
        $subject = CurriculumSubject::query()->where('normalized_name', 'matematika')->firstOrFail();
        $profile->subjects()->create([
            'name' => 'Matematika',
            'curriculum_subject_id' => $subject->id,
            'levels' => ['SMP'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);
        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => 'Selasa',
            'start_time' => '06:00:00',
            'end_time' => '22:00:00',
            'slots' => [],
            'is_active' => true,
        ]);
        PaymentSetting::create([
            'singleton_key' => 1,
            'merchant_name' => 'BimbelKu Official',
            'bank_name' => 'Bank Uji',
            'account_number' => '1234567890',
            'account_name' => 'BimbelKu',
        ]);

        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'phone' => '081200000002',
        ]);
        $plan = PackagePlan::create([
            'name' => 'Paket Uji Acceptance',
            'slug' => 'stage-five-acceptance',
            'description' => 'Fixture Paket Belajar modern untuk acceptance tutor.',
            'session_count' => 1,
            'validity_days' => 30,
            'maximum_subjects' => 1,
            'sort_order' => 9999,
            'is_active' => false,
        ]);
        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-STAGE5-ACCEPT',
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'status' => 'teacher_pending',
            'total_sessions' => 1,
            'used_sessions' => 0,
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'total_amount' => 50000,
        ]);
        $packageSubject = PackageSubject::create([
            'learning_package_id' => $package->id,
            'curriculum_subject_id' => $subject->id,
            'assigned_teacher_id' => null,
            'subject_name' => 'Matematika',
            'chapter' => 'Seluruh materi mapel',
            'curriculum_chapter_ids' => [],
            'allocated_sessions' => 1,
            'unit_price' => 50000,
            'subtotal_amount' => 50000,
            'status' => 'teacher_pending',
        ]);
        PackageSession::create([
            'package_subject_id' => $packageSubject->id,
            'sequence' => 1,
            'scheduled_start_at' => Carbon::parse('2026-07-28 11:00:00', 'Asia/Jakarta'),
            'scheduled_end_at' => Carbon::parse('2026-07-28 12:00:00', 'Asia/Jakarta'),
            'status' => 'scheduled',
        ]);
        $bookingRequest = BookingRequest::create([
            'student_id' => $student->id,
            'package_subject_id' => $packageSubject->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'curriculum_subject_id' => $subject->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'chapter' => 'Seluruh materi mapel',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => '2026-07-28',
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'duration_hours' => 1,
            'status' => 'teacher_pending',
            'matching_attempts' => 1,
            'hourly_rate' => 50000,
            'total_amount' => 50000,
            'search_radius_km' => 3,
            'search_started_at' => now(),
            'search_expires_at' => now()->addHours(12),
            'teacher_response_deadline' => now()->addMinutes(10),
        ]);
        $offer = TeacherOffer::create([
            'booking_request_id' => $bookingRequest->id,
            'teacher_id' => $teacher->id,
            'status' => 'pending',
            'offered_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);
        $order = Order::create([
            'user_id' => $student->id,
            'learning_package_id' => $package->id,
            'order_id' => 'INV-STAGE5-ACCEPT',
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'amount' => 50000,
            'status' => 'paid',
            'class_details_snapshot' => [
                'type' => 'Paket Belajar',
                'subject' => 'Matematika',
            ],
        ]);

        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/offers/{$offer->id}/accept")
            ->assertOk()
            ->assertJsonPath('message', 'Seluruh tutor menerima paket. Jadwal belajar sudah aktif.')
            ->assertJsonPath('data.package_activated', true);

        $booking = Booking::query()->firstOrFail();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('private', $booking->class_type);
        $this->assertSame('presence_confirmation_v2', $booking->session_flow_version);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('active', $package->fresh()->status);
        $this->assertSame('active', $packageSubject->fresh()->status);
        $this->assertDatabaseHas('booking_requests', [
            'id' => $bookingRequest->id,
            'student_id' => $student->id,
            'status' => 'confirmed',
            'class_type' => 'private',
            'package_subject_id' => $packageSubject->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'title' => 'Semua tutor ditemukan',
            'is_read' => false,
            'target_url' => '/student/my-classes',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $teacher->id,
            'title' => 'Kelas baru aktif',
            'is_read' => false,
            'target_url' => '/guru/kelas',
        ]);
    }
}
