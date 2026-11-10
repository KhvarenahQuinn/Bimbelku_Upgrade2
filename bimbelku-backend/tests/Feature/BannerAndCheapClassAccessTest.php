<?php

namespace Tests\Feature;

use App\Models\CheapClassTemplate;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\DynamicBanner;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Support\AdminPermissionCatalog;
use App\Support\CheapClassSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BannerAndCheapClassAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_banner_response_uses_a_short_browser_cache(): void
    {
        DynamicBanner::create([
            'title' => 'Banner terbaru',
            'audience' => 'student',
            'destination_kind' => 'internal',
            'destination_url' => '/student/dashboard',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/content/banners?audience=student')
            ->assertOk()
            ->assertJsonPath('0.title', 'Banner terbaru');

        $this->assertStringContainsString(
            'max-age=60',
            (string) $response->headers->get('Cache-Control')
        );
        $this->assertStringContainsString(
            'stale-while-revalidate=300',
            (string) $response->headers->get('Cache-Control')
        );
    }

    public function test_public_banner_image_uses_api_media_route_without_storage_symlink(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('stage-five/banners/banner.png', 'image');
        DynamicBanner::create([
            'title' => 'Banner bergambar',
            'image_path' => 'stage-five/banners/banner.png',
            'audience' => 'student',
            'destination_kind' => 'internal',
            'destination_url' => '/student/dashboard',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->getJson('/api/content/banners?audience=student')
            ->assertOk()
            ->assertJsonPath('0.image_path', 'stage-five/banners/banner.png')
            ->assertJsonPath('0.image_url', fn (string $url) => str_contains($url, '/api/public-media/stage-five/banners/banner.png'));

        $mediaResponse = $this->get('/api/public-media/stage-five/banners/banner.png')->assertOk();
        $this->assertStringContainsString(
            'immutable',
            (string) $mediaResponse->headers->get('Cache-Control')
        );
    }

    public function test_missing_public_banner_image_does_not_emit_a_broken_media_url(): void
    {
        Storage::fake('public');
        DynamicBanner::create([
            'title' => 'Banner tanpa file',
            'image_path' => 'stage-five/banners/missing.webp',
            'audience' => 'student',
            'destination_kind' => 'internal',
            'destination_url' => '/student/dashboard',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->getJson('/api/content/banners?audience=student')
            ->assertOk()
            ->assertJsonPath('0.image_url', null);
    }

    public function test_primary_admin_can_load_cheap_class_form_and_lists(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'verified_at' => now(),
            'is_accepting_requests' => true,
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'name' => 'Matematika',
            'levels' => ['SD'],
            'is_active' => true,
            'is_online' => true,
        ]);
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
        $firstSession = now()->addDays(4)->setTime(10, 0);
        $template = CheapClassTemplate::create([
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
            'recurrence_days' => [6],
            'price_per_session' => 25000,
            'price_per_student' => 100000,
            'minimum_participants' => 2,
            'maximum_participants' => 6,
            'registration_window_hours' => 24,
            'registration_closes_before_minutes' => 60,
            'payment_window_minutes' => 60,
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->assertTrue(CheapClassSchema::status()['ready']);
        $this->getJson('/api/admin/cheap-class-templates/form')
            ->assertOk()
            ->assertJsonPath('setup.ready', true)
            ->assertJsonPath('chapters.0.id', $chapter->id)
            ->assertJsonPath('chapters.0.title', 'Pecahan');
        $this->getJson('/api/admin/cheap-class-templates')
            ->assertOk()
            ->assertJsonPath('setup.ready', true)
            ->assertJsonStructure(['classes']);
        $this->assertSame([6], $template->fresh()->recurrence_days);
    }

    public function test_cheap_class_admin_endpoints_use_content_permission(): void
    {
        foreach ([
            '/api/admin/cheap-class-templates/form',
            '/api/admin/cheap-class-templates',
            '/api/admin/cheap-classes/schedule',
            '/api/admin/cheap-classes/1',
            '/api/admin/cheap-classes/1/retry-teacher',
            '/api/admin/cheap-classes/1/finalize',
        ] as $path) {
            $request = Request::create($path, 'GET');

            $this->assertSame(
                AdminPermissionCatalog::CONTENT_MANAGE,
                AdminPermissionCatalog::permissionFor($request),
                $path
            );
        }
    }
}
