<?php

namespace Tests\Feature;

use App\Models\CurriculumSubject;
use App\Models\LearningTimeSlot;
use App\Models\TeacherAvailability;
use App\Models\TeacherProfile;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CurriculumCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageTwoMobileBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_student_can_check_tutor_availability_without_receiving_teacher_identity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 08:00:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $profile = tap(TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_accepting_requests' => true,
        ]), fn ($profile) => $profile->forceFill(['verified_at' => now()->subDay()])->save());
        $subject = CurriculumSubject::query()
            ->where('normalized_name', 'matematika')
            ->firstOrFail();
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
            'day' => 'Rabu',
            'start_time' => '09:00:00',
            'end_time' => '15:00:00',
            'is_active' => true,
        ]);

        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/student/tutor-availability', [
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => '2026-07-29',
            'start_time' => '10:00',
            'duration_hours' => 1,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'available')
            ->assertJsonPath('has_candidate', true)
            ->assertJsonMissing(['teacher_id' => $teacher->id])
            ->assertJsonMissing(['teacher_name' => $teacher->name]);
    }

    public function test_unavailable_preview_still_returns_a_safe_generic_response(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 08:00:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        Sanctum::actingAs($student);

        $this->postJson('/api/student/tutor-availability', [
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => '2026-07-29',
            'start_time' => '10:00',
            'duration_hours' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'not_found')
            ->assertJsonPath('has_candidate', false)
            ->assertJsonMissingPath('candidate_count');
    }

    public function test_availability_endpoint_rejects_non_student_roles(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
        Sanctum::actingAs($teacher);

        $this->postJson('/api/student/tutor-availability', [])
            ->assertForbidden();
    }

    public function test_schedule_recommendations_preserve_days_and_check_every_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 08:00:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);
        $subject = CurriculumSubject::query()
            ->where('normalized_name', 'matematika')
            ->firstOrFail();
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = tap(TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_accepting_requests' => true,
        ]), fn ($profile) => $profile->forceFill(['verified_at' => now()->subDay()])->save());
        $profile->subjects()->create([
            'name' => 'Nama lama yang berbeda',
            'curriculum_subject_id' => $subject->id,
            'levels' => ['SMP'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);
        foreach (['Selasa', 'Kamis'] as $day) {
            TeacherAvailability::create([
                'user_id' => $teacher->id,
                'day' => $day,
                'start_time' => '15:00:00',
                'end_time' => '20:00:00',
                'is_active' => true,
            ]);
        }
        foreach ([16, 17, 18] as $hour) {
            LearningTimeSlot::query()->updateOrCreate(
                ['start_time' => sprintf('%02d:00:00', $hour)],
                [
                    'label' => sprintf('%02d.00 WIB', $hour),
                    'sort_order' => $hour,
                    'is_active' => true,
                ]
            );
        }

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($student);
        $response = $this->postJson('/api/student/schedule-recommendations', [
            'curriculum_subject_id' => $subject->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'schedule_start_date' => '2026-09-01',
            'weekdays' => [2, 4],
            'session_count' => 4,
            'current_time' => '18:00',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('mode', 'preserve_days')
            ->assertJsonPath('weekdays', [2, 4])
            ->assertJsonMissing(['teacher_id' => $teacher->id]);

        $recommendations = collect($response->json('recommendations'));
        $this->assertTrue(
            $recommendations->contains(fn (array $item) => $item['candidate_count'] > 0),
            $response->getContent()
        );
        $this->assertTrue($recommendations->every(function (array $item) {
            return collect($item['schedules'])->every(
                fn (string $schedule) => in_array(Carbon::parse($schedule)->dayOfWeekIso, [2, 4], true)
            );
        }));
    }
}
