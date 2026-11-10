<?php

namespace Tests\Feature;

use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\GuestPackageOrder;
use App\Models\LearningPackage;
use App\Models\PackagePlan;
use App\Models\PaymentSetting;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CurriculumCatalogSeeder;
use Database\Seeders\StageFiveExperienceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestPackageOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_multi_participant_pricing_stays_disabled_until_every_tier_is_complete(): void
    {
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(StageFiveExperienceSeeder::class);

        $plan = PackagePlan::query()->where('slug', 'coba-belajar')->firstOrFail();
        $subject = CurriculumSubject::query()->where('normalized_name', 'matematika')->firstOrFail();
        $quote = [
            'package_plan_id' => $plan->id,
            'education_level' => 'SMP',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'participant_count' => 2,
            'subjects' => [[
                'curriculum_subject_id' => $subject->id,
                'session_count' => 1,
            ]],
        ];

        $this->postJson('/api/guest/packages/quote', $quote)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('participant_count');

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $tiers = collect(range(2, 8))->mapWithKeys(fn (int $count) => [(string) $count => [
            'discount_percent' => min(5 + $count, 15),
        ]])->all();

        $this->putJson('/api/admin/private-participant-pricing', ['tiers' => $tiers])
            ->assertOk()
            ->assertJsonPath('multi_participant_ready', true);

        auth()->forgetGuards();
        $response = $this->postJson('/api/guest/packages/quote', $quote)
            ->assertOk()
            ->assertJsonPath('participant_count', 2);

        $this->assertGreaterThan(0, (float) $response->json('group_discount_amount'));
    }

    public function test_admin_can_raise_private_participant_limit_and_quote_uses_the_new_limit(): void
    {
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(StageFiveExperienceSeeder::class);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $tiers = collect(range(2, 12))->mapWithKeys(fn (int $count) => [(string) $count => [
            'discount_percent' => min(5 + $count, 15),
        ]])->all();

        $this->putJson('/api/admin/private-participant-pricing', [
            'maximum_participants' => 12,
            'tiers' => $tiers,
        ])->assertOk()
            ->assertJsonPath('maximum_participants', 12)
            ->assertJsonPath('multi_participant_ready', true);

        auth()->forgetGuards();
        $this->getJson('/api/private-participant-pricing')
            ->assertOk()
            ->assertJsonPath('maximum_participants', 12)
            ->assertJsonPath('multi_participant_ready', true)
            ->assertJsonPath('tiers.12.discount_percent', 15);

        $plan = PackagePlan::query()->where('slug', 'coba-belajar')->firstOrFail();
        $subject = CurriculumSubject::query()->where('normalized_name', 'matematika')->firstOrFail();
        $this->postJson('/api/guest/packages/quote', [
            'package_plan_id' => $plan->id,
            'education_level' => 'SMP',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'participant_count' => 12,
            'subjects' => [[
                'curriculum_subject_id' => $subject->id,
                'session_count' => 1,
            ]],
        ])->assertOk()->assertJsonPath('participant_count', 12);
    }

    public function test_guest_order_is_claimed_only_by_a_verified_account_with_the_same_email(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 09:00:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(StageFiveExperienceSeeder::class);
        PaymentSetting::query()->updateOrCreate(['singleton_key' => 1], [
            'merchant_name' => 'BimbelKu Official',
            'bank_name' => 'Bank Pengujian',
            'account_number' => '1234567890',
            'account_name' => 'BimbelKu',
        ]);

        $plan = PackagePlan::query()->where('slug', 'coba-belajar')->firstOrFail();
        $subject = CurriculumSubject::query()->where('normalized_name', 'matematika')->firstOrFail();
        $chapter = CurriculumChapter::query()
            ->where('curriculum_subject_id', $subject->id)
            ->where('education_level', 'SMP')
            ->where('grade', 'Kelas 7')
            ->where('is_active', true)
            ->firstOrFail();

        $draft = $this->postJson('/api/guest/packages', [
            'name' => 'Murid Tamu',
            'email' => 'murid.tamu@example.com',
            'phone' => '081234567890',
            'package_plan_id' => $plan->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'participant_count' => 1,
            'subjects' => [[
                'curriculum_subject_id' => $subject->id,
                'curriculum_chapter_ids' => [$chapter->id],
                'learning_goal' => 'Menguatkan aljabar dasar.',
                'weekdays' => [1],
                'schedules' => ['2026-09-21 15:00:00'],
            ]],
        ])->assertCreated()->assertJsonPath('status', 'pending_account');

        $code = $draft->json('code');
        $storedDraft = GuestPackageOrder::query()->where('code', $code)->firstOrFail();
        $this->assertTrue($storedDraft->expires_at->equalTo(now()->addHour()));
        $this->getJson("/api/guest/packages/{$code}")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('email_hint', 'mu***@example.com')
            ->assertJsonPath('summary.education_level', 'SMP')
            ->assertJsonPath('summary.learning_mode', 'online')
            ->assertJsonPath('summary.session_count', 1)
            ->assertJsonPath('summary.subjects.0', 'Matematika');

        $otherStudent = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'email' => 'orang.lain@example.com',
        ]);
        Sanctum::actingAs($otherStudent);
        $this->postJson("/api/guest/packages/{$code}/claim")->assertForbidden();

        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'email' => 'murid.tamu@example.com',
        ]);
        Sanctum::actingAs($student);
        $this->getJson('/api/student/guest-packages/pending')
            ->assertOk()
            ->assertJsonPath('data.0.code', $code);
        // Email terverifikasi dan sama, jadi draf ditautkan tanpa mengubah status.
        $this->assertDatabaseHas('guest_package_orders', [
            'code' => $code,
            'status' => 'pending',
            'student_id' => $student->id,
        ]);
        $first = $this->postJson("/api/guest/packages/{$code}/claim")
            ->assertOk()
            ->assertJsonPath('data.participant_count', 1);
        $second = $this->postJson("/api/guest/packages/{$code}/claim")->assertOk();
        $this->assertTrue(LearningPackage::query()->findOrFail($first->json('data.id'))->payment_due_at->equalTo(now()->addHour()));

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('learning_packages', 1);
        $this->assertDatabaseHas('guest_package_orders', [
            'code' => $code,
            'status' => 'claimed',
            'student_id' => $student->id,
        ]);
        $this->assertNotNull(GuestPackageOrder::query()->where('code', $code)->value('claimed_at'));
    }

    public function test_guest_order_preserves_each_private_participant_identity_when_purchaser_does_not_join(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 09:00:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(StageFiveExperienceSeeder::class);
        PaymentSetting::query()->updateOrCreate(['singleton_key' => 1], [
            'merchant_name' => 'BimbelKu Official',
            'bank_name' => 'Bank Pengujian',
            'account_number' => '1234567890',
            'account_name' => 'BimbelKu',
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $tiers = collect(range(2, 8))->mapWithKeys(fn (int $count) => [(string) $count => [
            'discount_percent' => min(5 + $count, 15),
        ]])->all();
        $this->putJson('/api/admin/private-participant-pricing', ['tiers' => $tiers])->assertOk();

        $plan = PackagePlan::query()->where('slug', 'coba-belajar')->firstOrFail();
        $subject = CurriculumSubject::query()->where('normalized_name', 'matematika')->firstOrFail();
        $chapter = CurriculumChapter::query()
            ->where('curriculum_subject_id', $subject->id)
            ->where('education_level', 'SMP')
            ->where('grade', 'Kelas 7')
            ->where('is_active', true)
            ->firstOrFail();
        auth()->forgetGuards();

        $draft = $this->postJson('/api/guest/packages', [
            'name' => 'Orang Tua Murid',
            'email' => 'orang.tua@example.com',
            'phone' => '081234567891',
            'package_plan_id' => $plan->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'participant_count' => 2,
            'purchaser_participates' => false,
            'participant_details' => [
                ['full_name' => 'Anak Pertama', 'nickname' => 'Ana', 'birth_date' => '2013-05-10', 'gender' => 'female'],
                ['full_name' => 'Anak Kedua', 'nickname' => 'Dika', 'birth_date' => '2014-08-12', 'gender' => 'male'],
            ],
            'subjects' => [[
                'curriculum_subject_id' => $subject->id,
                'curriculum_chapter_ids' => [$chapter->id],
                'learning_goal' => 'Belajar bersama saudara.',
                'weekdays' => [1],
                'schedules' => ['2026-09-21 15:00:00'],
            ]],
        ])->assertCreated();

        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'email' => 'orang.tua@example.com',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($student);
        $this->postJson("/api/guest/packages/{$draft->json('code')}/claim")
            ->assertOk()
            ->assertJsonPath('data.participant_count', 2)
            ->assertJsonPath('data.purchaser_participates', false)
            ->assertJsonPath('data.participant_details.0.full_name', 'Anak Pertama')
            ->assertJsonPath('data.participant_details.1.nickname', 'Dika');

        $this->assertDatabaseHas('learning_packages', [
            'student_id' => $student->id,
            'participant_count' => 2,
            'purchaser_participates' => false,
        ]);
    }

    public function test_expired_guest_draft_is_marked_and_cannot_be_claimed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 11:00:00', 'Asia/Jakarta'));
        $draft = GuestPackageOrder::query()->create([
            'code' => 'BKU-T-EXPIREDTEST01',
            'email' => 'expired@example.com',
            'name' => 'Draf Kedaluwarsa',
            'phone' => '081234567899',
            'payload' => [],
            'quoted_total_amount' => 100000,
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('guest-packages:expire')->assertSuccessful();
        $this->assertSame('expired', $draft->fresh()->status);

        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'email' => 'expired@example.com',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($student);
        $this->postJson('/api/guest/packages/'.$draft->code.'/claim')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Draf pesanan sudah tidak aktif.');
    }
}
