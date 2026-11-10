<?php

namespace Tests\Feature;

use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\LearningPackage;
use App\Models\PackagePlan;
use App\Models\PaymentSetting;
use App\Models\Promotion;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CurriculumCatalogSeeder;
use Database\Seeders\StageFiveExperienceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageFivePackageExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_package_name_has_a_clear_message_and_original_package_can_still_be_edited(): void
    {
        $this->seed(StageFiveExperienceSeeder::class);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $plan = PackagePlan::query()->where('slug', 'bulanan-dasar')->firstOrFail();
        $payload = $plan->only(['name', 'slug', 'description', 'session_count', 'validity_days', 'maximum_subjects', 'sort_order', 'is_active']);

        $this->postJson('/api/admin/stage-five/plans', [...$payload, 'slug' => 'paket-lain'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Nama paket ini sudah digunakan. Pilih nama lain agar tidak membingungkan murid.');

        $this->putJson("/api/admin/stage-five/plans/{$plan->id}", $payload)->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_plans_banners_and_role_tutorials_are_available(): void
    {
        $this->seed(StageFiveExperienceSeeder::class);

        $this->getJson('/api/package-plans')
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.name', 'Coba Belajar')
            ->assertJsonPath('3.name', 'Bulanan Intensif');

        $this->getJson('/api/learning-time-slots')
            ->assertOk()
            ->assertJsonCount(15)
            ->assertJsonPath('0.start_time', '08:00:00')
            ->assertJsonPath('14.start_time', '22:00:00');

        $this->getJson('/api/content/banners?audience=student')
            ->assertOk()
            ->assertJsonCount(2);

        $this->getJson('/api/content/tutorials?role=student&context=dashboard')
            ->assertOk()
            ->assertJsonPath('0.role', 'student')
            ->assertJsonCount(4, '0.steps');

        $this->getJson('/api/content/tutorials?role=student&context=package-builder')
            ->assertOk()
            ->assertJsonPath('0.title', 'Memesan dua atau lebih mata pelajaran')
            ->assertJsonCount(5, '0.steps');
    }

    public function test_admin_banner_crud_rejects_an_unsafe_internal_destination(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $payload = [
            'title' => 'Banner uji',
            'description' => 'Banner aman untuk murid.',
            'button_text' => 'Buka',
            'image' => UploadedFile::fake()->image('banner.jpg', 1200, 500),
            'audience' => 'student',
            'destination_kind' => 'internal',
            'destination_url' => '/admin/users/delete-all',
            'sort_order' => 1,
            'is_active' => true,
        ];

        $this->post('/api/admin/stage-five/banners', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Tujuan internal tidak diizinkan.');

        $payload['image'] = UploadedFile::fake()->image('banner-aman.jpg', 1200, 500);
        $payload['destination_url'] = '/student/packages/new';
        $this->post('/api/admin/stage-five/banners', $payload)
            ->assertCreated()
            ->assertJsonPath('data.destination_url', '/student/packages/new');
    }

    public function test_promotion_claim_is_idempotent_and_total_quota_is_enforced(): void
    {
        $promotion = Promotion::create([
            'title' => 'Promo satu kuota',
            'code' => 'SATUKUOTA',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'minimum_purchase' => 0,
            'total_quota' => 1,
            'per_user_limit' => 1,
            'claim_required' => true,
            'is_active' => true,
        ]);
        $firstStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($firstStudent);

        $this->postJson("/api/student/promotions/{$promotion->id}/claim")
            ->assertCreated();
        $this->postJson("/api/student/promotions/{$promotion->id}/claim")
            ->assertOk();
        $this->assertDatabaseCount('promotion_claims', 1);

        $secondStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($secondStudent);
        $this->postJson("/api/student/promotions/{$promotion->id}/claim")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Kuota penawaran sudah habis.');
    }

    public function test_admin_can_activate_promotion_using_browser_timezone_and_students_receive_fresh_status(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-08-11 09:30:00', 'UTC'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/stage-five/promotions', [
            'title' => 'Promo zona waktu Jakarta',
            'code' => 'JAKARTA20',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'minimum_purchase' => 0,
            'total_quota' => 10,
            'per_user_limit' => 1,
            'target_plan_ids' => [],
            'target_levels' => [],
            'target_subjects' => [],
            'target_modes' => [],
            'new_students_only' => false,
            'claim_required' => true,
            'is_active' => true,
            'starts_at' => '2026-08-11T16:00:00+07:00',
            'ends_at' => '2026-08-11T18:00:00+07:00',
        ])->assertCreated();

        $promotionId = $response->json('data.id');
        $this->assertDatabaseHas('promotions', [
            'id' => $promotionId,
            'is_active' => true,
            'starts_at' => '2026-08-11 09:00:00',
            'ends_at' => '2026-08-11 11:00:00',
        ]);

        $promotionResponse = $this->getJson('/api/content/promotions')
            ->assertOk()
            ->assertJsonPath('0.id', $promotionId);
        $cacheControl = (string) $promotionResponse->headers->get('Cache-Control');
        foreach (['no-store', 'max-age=0', 'must-revalidate'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
    }

    public function test_student_package_index_separates_active_and_history_scopes(): void
    {
        $this->seed(StageFiveExperienceSeeder::class);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $plan = PackagePlan::query()->firstOrFail();

        $base = [
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'total_sessions' => 4,
            'used_sessions' => 0,
            'duration_hours' => 1,
            'subtotal_amount' => 200000,
            'discount_amount' => 0,
            'total_amount' => 200000,
        ];

        LearningPackage::create([...$base, 'package_code' => 'PKG-ACTIVE-SCOPE', 'status' => 'active']);
        LearningPackage::create([...$base, 'package_code' => 'PKG-DONE-SCOPE', 'status' => 'completed']);
        LearningPackage::create([...$base, 'package_code' => 'PKG-CANCEL-SCOPE', 'status' => 'cancelled']);

        Sanctum::actingAs($student);

        $this->getJson('/api/student/packages?scope=active&per_page=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.package_code', 'PKG-ACTIVE-SCOPE');

        $history = $this->getJson('/api/student/packages?scope=history&per_page=100')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            ['PKG-DONE-SCOPE', 'PKG-CANCEL-SCOPE'],
            collect($history->json('data'))->pluck('package_code')->all()
        );
    }

    public function test_package_requires_the_exact_session_count_and_hour_slots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 10:00:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(StageFiveExperienceSeeder::class);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($student);

        $plan = PackagePlan::query()->where('slug', 'bulanan-dasar')->firstOrFail();
        $subject = CurriculumSubject::query()
            ->where('normalized_name', 'matematika')
            ->firstOrFail();
        $base = [
            'package_plan_id' => $plan->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'subjects' => [[
                'curriculum_subject_id' => $subject->id,
                'learning_goal' => 'Menguatkan aljabar dasar.',
                'weekdays' => [1],
                'schedules' => [
                    '2026-08-03 15:00:00',
                    '2026-08-10 15:00:00',
                    '2026-08-17 15:00:00',
                ],
            ]],
        ];

        $this->postJson('/api/student/packages', $base, ['Idempotency-Key' => 'stage5-wrong-count'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Jumlah jadwal harus tepat 4 sesi.');

        $base['subjects'][0]['schedules'] = [
            '2026-08-03 15:30:00',
            '2026-08-10 15:00:00',
            '2026-08-17 15:00:00',
            '2026-08-24 15:00:00',
        ];
        $this->postJson('/api/student/packages', $base, ['Idempotency-Key' => 'stage5-wrong-minute'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Semua jadwal hanya boleh memakai menit 00.');
    }

    public function test_four_session_package_limits_unique_weekdays_across_all_subjects(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 10:00:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(StageFiveExperienceSeeder::class);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($student);

        $plan = PackagePlan::query()->where('slug', 'bulanan-dasar')->firstOrFail();
        $plan->update(['maximum_subjects' => 2]);
        $subjects = CurriculumSubject::query()
            ->where('is_active', true)
            ->whereHas('chapters', fn ($query) => $query
                ->where('education_level', 'SMP')
                ->where('grade', 'Kelas 7')
                ->where('is_active', true))
            ->limit(2)
            ->get();
        $this->assertCount(2, $subjects);

        $payloadSubjects = $subjects->values()->map(function (CurriculumSubject $subject, int $index) {
            $chapter = $subject->chapters()
                ->where('education_level', 'SMP')
                ->where('grade', 'Kelas 7')
                ->where('is_active', true)
                ->firstOrFail();
            $dayOffset = $index * 2;

            return [
                'curriculum_subject_id' => $subject->id,
                'curriculum_chapter_ids' => [$chapter->id],
                'learning_goal' => 'Menguji batas hari unik seluruh paket.',
                'weekdays' => [1 + $dayOffset, 2 + $dayOffset],
                'schedules' => $index === 0
                    ? ['2026-08-03 15:00:00', '2026-08-04 15:00:00']
                    : ['2026-08-05 15:00:00', '2026-08-06 15:00:00'],
            ];
        })->all();

        $this->postJson('/api/student/packages', [
            'package_plan_id' => $plan->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'subjects' => $payloadSubjects,
        ], ['Idempotency-Key' => 'stage5-package-weekday-union'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Jumlah hari belajar unik pada seluruh paket melebihi batas paket.');
    }

    public function test_package_quote_accepts_one_or_two_hour_sessions(): void
    {
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(StageFiveExperienceSeeder::class);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($student);

        $plan = PackagePlan::query()->where('slug', 'bulanan-dasar')->firstOrFail();
        $subject = CurriculumSubject::query()
            ->where('normalized_name', 'matematika')
            ->firstOrFail();
        $payload = [
            'package_plan_id' => $plan->id,
            'education_level' => 'SMP',
            'learning_mode' => 'online',
            'subjects' => [[
                'curriculum_subject_id' => $subject->id,
                'session_count' => $plan->session_count,
            ]],
        ];

        $oneHour = $this->postJson('/api/student/packages/quote', [
            ...$payload,
            'duration_hours' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('duration_hours', 1)
            ->assertJsonPath('total_learning_hours', $plan->session_count)
            ->assertJsonPath('lines.0.duration_hours', 1);

        $twoHours = $this->postJson('/api/student/packages/quote', [
            ...$payload,
            'duration_hours' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('duration_hours', 2)
            ->assertJsonPath('total_learning_hours', $plan->session_count * 2)
            ->assertJsonPath('lines.0.duration_hours', 2);

        $this->assertEquals(
            (float) $oneHour->json('total_amount') * 2,
            (float) $twoHours->json('total_amount')
        );

        $this->postJson('/api/student/packages/quote', [
            ...$payload,
            'duration_hours' => 3,
        ])->assertUnprocessable();
    }

    public function test_two_hour_package_creates_two_hour_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 10:00:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(StageFiveExperienceSeeder::class);
        PaymentSetting::updateOrCreate(
            ['singleton_key' => 1],
            [
                'merchant_name' => 'BimbelKu Official',
                'bank_name' => 'Bank Pengujian',
                'account_number' => '1234567890',
                'account_name' => 'BimbelKu',
            ]
        );
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($student);

        $plan = PackagePlan::query()->where('slug', 'bulanan-dasar')->firstOrFail();
        $subject = CurriculumSubject::query()
            ->where('normalized_name', 'matematika')
            ->firstOrFail();
        $chapter = CurriculumChapter::query()
            ->where('curriculum_subject_id', $subject->id)
            ->where('education_level', 'SMP')
            ->where('grade', 'Kelas 7')
            ->where('is_active', true)
            ->firstOrFail();
        $this->postJson('/api/student/packages', [
            'package_plan_id' => $plan->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'duration_hours' => 2,
            'subjects' => [[
                'curriculum_subject_id' => $subject->id,
                'curriculum_chapter_ids' => [$chapter->id],
                'learning_goal' => 'Menguatkan aljabar dasar.',
                'weekdays' => [1],
                'schedules' => [
                    '2026-08-03 15:00:00',
                    '2026-08-10 16:00:00',
                    '2026-08-17 15:00:00',
                    '2026-08-24 15:00:00',
                ],
            ]],
        ], ['Idempotency-Key' => 'stage5-two-hour-package'])
            ->assertCreated()
            ->assertJsonPath('data.duration_hours', 2)
            ->assertJsonPath('data.total_learning_hours', $plan->session_count * 2)
            ->assertJsonPath('order.duration_hours', 2);

        $this->assertDatabaseHas('package_sessions', [
            'scheduled_start_at' => '2026-08-03 15:00:00',
            'scheduled_end_at' => '2026-08-03 17:00:00',
        ]);
        $this->assertDatabaseHas('package_sessions', [
            'scheduled_start_at' => '2026-08-10 16:00:00',
            'scheduled_end_at' => '2026-08-10 18:00:00',
        ]);
        $this->assertDatabaseHas('booking_requests', [
            'duration_hours' => 2,
            'start_time' => '15:00:00',
            'end_time' => '17:00:00',
        ]);
        $this->assertDatabaseHas('package_chapters', [
            'curriculum_chapter_id' => $chapter->id,
            'title' => $chapter->title,
            'status' => 'not_started',
        ]);
    }
}
