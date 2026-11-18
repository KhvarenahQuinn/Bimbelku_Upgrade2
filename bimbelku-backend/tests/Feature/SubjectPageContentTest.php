<?php

namespace Tests\Feature;

use App\Models\CurriculumSubject;
use App\Models\SubjectPageContent;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use Database\Seeders\CurriculumCatalogSeeder;
use Database\Seeders\SubjectPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubjectPageContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_content_is_available_for_every_active_subject(): void
    {
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(SubjectPageContentSeeder::class);

        $activeSubjects = CurriculumSubject::query()->where('is_active', true)->count();
        $profiles = SubjectPageContent::query()->where('is_published', true)->get();

        $this->assertGreaterThan(0, $activeSubjects);
        $this->assertCount($activeSubjects, $profiles);
        $profiles->each(function (SubjectPageContent $profile): void {
            $this->assertNotEmpty($profile->hero_intro);
            $this->assertNotEmpty($profile->facts);
            $this->assertNotEmpty($profile->learning_map);
            $this->assertNotEmpty($profile->learning_journey);
            $this->assertNotEmpty($profile->benefits);
            $this->assertNotEmpty($profile->suitable_for);
            $this->assertNotEmpty($profile->reasons);
            $this->assertNotEmpty($profile->articles);
            $this->assertNotEmpty($profile->faqs);
            $this->assertNotEmpty($profile->source_note);
            $this->assertNotNull($profile->reviewed_at);
        });
    }

    public function test_admin_can_publish_replace_and_remove_a_subject_hero_image(): void
    {
        Storage::fake('public');
        $this->seed(CurriculumCatalogSeeder::class);
        $subject = CurriculumSubject::query()->where('is_active', true)->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->post('/api/admin/subjects/'.$subject->id.'/page/hero', [
            'hero_image' => new UploadedFile(
                '/tmp/test_hero_720x480.jpg',
                'mapel.jpg',
                'image/jpeg',
                null,
                true
            ),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Foto hero Mapel tersimpan.');

        $content = SubjectPageContent::query()->where('curriculum_subject_id', $subject->id)->firstOrFail();
        Storage::disk('public')->assertExists($content->hero_image_path);

        $this->putJson('/api/admin/subjects/'.$subject->id.'/page', [
            'hero_intro' => 'Pendampingan terarah berdasarkan kebutuhan murid.',
            'learning_approach' => 'Tutor menyesuaikan materi dari hasil pemetaan awal.',
            'facts' => [],
            'learning_map' => [],
            'learning_journey' => [],
            'benefits' => [],
            'suitable_for' => [],
            'reasons' => [],
            'articles' => [],
            'faqs' => [],
            'source_note' => 'Ditinjau oleh tim akademik BimbelKu.',
            'reviewed_at' => now()->toDateString(),
            'is_published' => true,
        ])->assertOk();

        $this->getJson('/api/subject-pages/'.$subject->id)
            ->assertOk()
            ->assertJsonPath('content.hero_intro', 'Pendampingan terarah berdasarkan kebutuhan murid.')
            ->assertJsonPath('content.hero_image_url', fn ($value) => is_string($value) && str_contains($value, '/api/public-media/'))
            ->assertJsonMissingPath('content.hero_image_path');

        $storedPath = $content->fresh()->hero_image_path;
        $this->deleteJson('/api/admin/subjects/'.$subject->id.'/page/hero')
            ->assertOk();
        Storage::disk('public')->assertMissing($storedPath);
        $this->assertNull($content->fresh()->hero_image_path);
    }

    public function test_only_consented_verified_tutors_are_exposed_on_subject_page(): void
    {
        $this->seed(CurriculumCatalogSeeder::class);
        $subject = CurriculumSubject::query()->where('name', 'Matematika')->firstOrFail();
        $teacher = User::factory()->create(['name' => 'Tutor Publik', 'role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::query()->create([
            'user_id' => $teacher->id,
            'title' => 'Tutor Matematika',
            'experience' => '4 tahun',
            'verified_at' => now(),
            'public_profile_enabled' => true,
            'public_profile_consent_at' => now(),
            'public_directory_approved_at' => now(),
            'public_credentials' => 'Alumni Pendidikan Matematika',
        ]);
        TeacherSubject::query()->create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $subject->id,
            'name' => $subject->name,
            'levels' => ['SMP'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);

        $this->getJson('/api/subject-pages/'.$subject->id)
            ->assertOk()
            ->assertJsonPath('tutors.0.name', 'Tutor Publik')
            ->assertJsonPath('tutors.0.credentials', 'Alumni Pendidikan Matematika')
            ->assertJsonMissingPath('tutors.0.email')
            ->assertJsonMissingPath('tutors.0.phone');

        $this->getJson('/api/public-tutors')
            ->assertOk()
            ->assertJsonPath('0.name', 'Tutor Publik')
            ->assertJsonPath('0.subjects.0', 'Matematika')
            ->assertJsonMissingPath('0.email')
            ->assertJsonMissingPath('0.phone')
            ->assertJsonMissingPath('0.whatsapp_number');

        $profile->update(['public_profile_enabled' => false]);
        $this->getJson('/api/subject-pages/'.$subject->id)
            ->assertOk()
            ->assertJsonCount(0, 'tutors');
        $this->getJson('/api/public-tutors')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_public_tutor_requires_separate_admin_approval_and_uses_catalog_group(): void
    {
        Storage::fake('public');
        $this->seed(CurriculumCatalogSeeder::class);
        $subject = CurriculumSubject::query()->where('name', 'Matematika')->firstOrFail();
        $teacher = User::factory()->create(['name' => 'Tutor Bidang', 'role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::query()->create([
            'user_id' => $teacher->id,
            'verified_at' => now(),
            'public_profile_enabled' => true,
            'public_profile_consent_at' => now(),
            'public_credentials' => 'Alumni Pendidikan Matematika',
        ]);
        TeacherSubject::query()->create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $subject->id,
            'name' => $subject->name,
            'levels' => ['SMP'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);

        $this->getJson('/api/public-tutors')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/subject-pages/'.$subject->id)->assertOk()->assertJsonCount(0, 'tutors');

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->getJson('/api/admin/public-tutors')->assertOk()->assertJsonPath('data.0.consented', true);
        $this->patchJson('/api/admin/public-tutors/'.$profile->id, ['approved' => true])->assertOk();
        $this->patchJson('/api/admin/public-tutors/'.$profile->id.'/identity', [
            'public_display_name' => 'Tutor Matematika',
            'public_degree' => 'S.Pd. · Alumni UGM',
            'identity_confirmed' => true,
        ])->assertOk()->assertJsonPath('public_degree', 'S.Pd. · Alumni UGM');
        $this->getJson('/api/public-tutors')
            ->assertOk()
            ->assertJsonPath('0.name', 'Tutor Matematika')
            ->assertJsonPath('0.degree', 'S.Pd. · Alumni UGM')
            ->assertJsonPath('0.groups.0', $subject->group_name)
            ->assertJsonMissingPath('0.email')
            ->assertJsonMissingPath('0.whatsapp_number');
        $this->getJson('/api/public-tutors?limit=1')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/public-tutors?limit=21')->assertUnprocessable();
        $this->getJson('/api/subject-pages/'.$subject->id)->assertOk()->assertJsonPath('tutors.0.name', 'Tutor Matematika');

        $this->post('/api/admin/public-tutors/'.$profile->id.'/photo', [
            'photo' => UploadedFile::fake()->create('tutor.jpg', 100, 'image/jpeg'),
            'photo_consent_confirmed' => true,
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('photo_url', fn ($url) => str_contains($url, '/api/public-media/photos/public_tutors/'));
        $this->getJson('/api/public-tutors')->assertOk()
            ->assertJsonPath('0.photo_url', fn ($url) => str_contains($url, '/api/public-media/photos/public_tutors/'));

        Sanctum::actingAs($teacher);
        $this->patchJson('/api/admin/public-tutors/'.$profile->id, ['approved' => false])->assertForbidden();
        $this->patchJson('/api/admin/public-tutors/'.$profile->id.'/identity', [
            'public_display_name' => 'Nama lain',
            'identity_confirmed' => true,
        ])->assertForbidden();
        $this->postJson('/api/teacher/profile', [
            'whatsapp_number' => '081234567890',
            'public_profile_enabled' => true,
            'public_credentials' => 'Alumni Pendidikan Matematika, pengalaman baru',
        ])->assertOk();
        $this->assertNull($profile->fresh()->public_directory_approved_at);
        $this->getJson('/api/public-tutors')->assertOk()->assertJsonCount(0);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->patchJson('/api/admin/public-tutors/'.$profile->id, ['approved' => true])->assertOk();
        $this->patchJson('/api/admin/public-tutors/'.$profile->id, ['approved' => false])->assertOk();
        $this->getJson('/api/public-tutors')->assertOk()->assertJsonCount(0);

        $profile->update(['public_profile_enabled' => false]);
        $this->patchJson('/api/admin/public-tutors/'.$profile->id, ['approved' => true])->assertStatus(422);
        $this->patchJson('/api/admin/public-tutors/'.$profile->id.'/identity', [
            'public_degree' => 'Gelar baru',
            'identity_confirmed' => true,
        ])->assertStatus(422);
        $this->post('/api/admin/public-tutors/'.$profile->id.'/photo', [
            'photo' => UploadedFile::fake()->create('another.jpg', 100, 'image/jpeg'),
            'photo_consent_confirmed' => true,
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_admin_tutor_directory_filters_across_all_pages(): void
    {
        $this->seed(CurriculumCatalogSeeder::class);
        $subject = CurriculumSubject::query()->where('name', 'Matematika')->firstOrFail();
        foreach (range(1, 23) as $number) {
            $teacher = User::factory()->create([
                'name' => sprintf('Tutor Katalog %02d', $number),
                'role' => 'teacher', 'status' => 'active',
            ]);
            $consented = $number <= 11;
            $profile = TeacherProfile::query()->create([
                'user_id' => $teacher->id,
                'verified_at' => now(),
                'public_profile_enabled' => $consented,
                'public_profile_consent_at' => $consented ? now() : null,
                'public_directory_approved_at' => $consented ? now() : null,
            ]);
            TeacherSubject::query()->create([
                'teacher_profile_id' => $profile->id,
                'curriculum_subject_id' => $subject->id,
                'name' => $subject->name,
                'levels' => ['SMP'],
                'is_active' => true,
                'is_online' => true,
                'is_offline' => false,
                'is_private_active' => true,
            ]);
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->getJson('/api/admin/public-tutors?per_page=20')->assertOk()
            ->assertJsonPath('total', 23)->assertJsonCount(20, 'data');
        $this->getJson('/api/admin/public-tutors?per_page=20&page=2')->assertOk()
            ->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/public-tutors?status=visible&per_page=10')->assertOk()
            ->assertJsonPath('total', 11)->assertJsonCount(10, 'data');
        $this->getJson('/api/admin/public-tutors?status=no_consent&group='.urlencode($subject->group_name))
            ->assertOk()->assertJsonPath('total', 12);
        $this->getJson('/api/admin/public-tutors?per_page=50')->assertUnprocessable();
    }
}
