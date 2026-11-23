<?php

namespace Tests\Feature;

use App\Models\CurriculumSubject;
use App\Models\PackagePlan;
use App\Models\User;
use Database\Seeders\CurriculumCatalogSeeder;
use Database\Seeders\StageFiveExperienceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PackageQuoteGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_rejects_inactive_subjects_and_unsupported_education_levels(): void
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
            'duration_hours' => 1,
            'subjects' => [[
                'curriculum_subject_id' => $subject->id,
                'session_count' => $plan->session_count,
            ]],
        ];

        $this->postJson('/api/student/packages/quote', $payload)->assertOk();

        $subject->forceFill(['education_levels' => ['SMA']])->save();
        $this->postJson('/api/student/packages/quote', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', "{$subject->name} tidak tersedia pada jenjang ini.");

        $subject->forceFill(['education_levels' => ['SD', 'SMP'], 'is_active' => false])->save();
        $this->postJson('/api/student/packages/quote', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Salah satu mata pelajaran tidak aktif.');
    }
}
