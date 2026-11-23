<?php

namespace Tests\Feature;

use App\Models\CurriculumSubject;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use Database\Seeders\CurriculumCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherSubjectModePreservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_omitted_group_flag_keeps_the_previous_value_and_new_subjects_default_to_active(): void
    {
        $this->seed(CurriculumCatalogSeeder::class);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::create(['user_id' => $teacher->id, 'is_accepting_requests' => true]);

        $matematika = CurriculumSubject::query()->where('normalized_name', 'matematika')->firstOrFail();
        $level = ($matematika->education_levels ?? [])[0] ?? 'SMP';
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $matematika->id,
            'name' => $matematika->name,
            'levels' => [$level],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => true,
            'is_private_active' => true,
            'is_group_active' => true,
        ]);

        Sanctum::actingAs($teacher);
        $payload = [
            'subjects' => [[
                'name' => $matematika->name,
                'levels' => [$level],
                'is_online' => true,
                'is_offline' => true,
            ]],
        ];

        // Flag tidak dikirim: nilai lama harus bertahan, bukan berubah jadi mati.
        $this->postJson('/api/teacher/subjects', $payload)->assertOk();
        $this->assertTrue((bool) $this->subject($profile->id, $matematika->id)->is_group_active);

        // Nilai eksplisit tetap dihormati.
        $this->postJson('/api/teacher/subjects', [
            'subjects' => [[...$payload['subjects'][0], 'is_group_active' => false]],
        ])->assertOk();
        $this->assertFalse((bool) $this->subject($profile->id, $matematika->id)->is_group_active);

        // Simpan ulang tanpa flag tidak menghidupkannya kembali.
        $this->postJson('/api/teacher/subjects', $payload)->assertOk();
        $this->assertFalse((bool) $this->subject($profile->id, $matematika->id)->is_group_active);

        // Mapel baru mengikuti default registrasi: kedua mode aktif.
        $bahasa = CurriculumSubject::query()
            ->where('is_active', true)
            ->whereKeyNot($matematika->id)
            ->firstOrFail();
        $bahasaLevel = ($bahasa->education_levels ?? [])[0] ?? 'SMP';
        TeacherSubject::query()->where('teacher_profile_id', $profile->id)->delete();
        $this->postJson('/api/teacher/subjects', [
            'subjects' => [[
                'name' => $bahasa->name,
                'levels' => [$bahasaLevel],
                'is_online' => true,
                'is_offline' => true,
            ]],
        ])->assertOk();

        $created = $this->subject($profile->id, $bahasa->id);
        $this->assertTrue((bool) $created->is_group_active);
        $this->assertTrue((bool) $created->is_private_active);
    }

    private function subject(int $profileId, int $curriculumSubjectId): TeacherSubject
    {
        return TeacherSubject::query()
            ->where('teacher_profile_id', $profileId)
            ->where('curriculum_subject_id', $curriculumSubjectId)
            ->firstOrFail();
    }
}
