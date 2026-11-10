<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Rating;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\WebsiteSection;
use App\Models\WebsiteTrustItem;
use App\Support\AdminPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicWebsiteCmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_content_exposes_safe_brand_structure_and_real_system_value(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        TeacherProfile::create([
            'user_id' => $teacher->id,
            'verified_at' => now(),
        ]);
        WebsiteTrustItem::query()->where('source_key', 'verified_tutors')->update([
            'source_note' => 'Catatan internal yang tidak boleh tampil ke publik.',
        ]);
        WebsiteSection::query()->where('section_key', 'reviews')->update(['is_visible' => false]);

        $response = $this->getJson('/api/website-content')
            ->assertOk()
            ->assertJsonPath('settings.brand_name', 'BimbelKu')
            ->assertJsonPath('settings.theme.primary', '#F97316')
            ->assertJsonPath('settings.navigation_items.0.url', '/program')
            ->assertJsonMissingPath('trust_items.0.source_note');

        $tutorTrust = collect($response->json('trust_items'))->firstWhere('source_key', 'verified_tutors');
        $this->assertSame('1+', $tutorTrust['resolved_value'] ?? null);

        $this->assertNotContains('reviews', collect($response->json('sections'))->pluck('section_key')->all());
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
    }

    public function test_non_admin_cannot_open_website_settings(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($student);

        $this->getJson('/api/admin/website-settings')->assertForbidden();
    }

    public function test_website_settings_use_settings_permission(): void
    {
        $request = Request::create('/api/admin/website-settings', 'POST');

        $this->assertSame(
            AdminPermissionCatalog::SETTINGS_MANAGE,
            AdminPermissionCatalog::permissionFor($request)
        );
    }

    public function test_admin_can_update_configuration_and_upload_logo(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $payload = $this->validPayload();
        $payload['brand_name'] = 'Bimbelku Yogyakarta';
        $payload['logo'] = UploadedFile::fake()->image('logo.png', 500, 180);
        $payload['trust_image_1'] = UploadedFile::fake()->image('tutor-consented.jpg', 800, 1000);
        $payload['trust_image_6'] = UploadedFile::fake()->image('tutor-consented-six.jpg', 800, 1000);

        $response = $this->post('/api/admin/website-settings', $payload, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.settings.brand_name', 'Bimbelku Yogyakarta');

        $logoUrl = $response->json('data.settings.logo_url');
        $this->assertIsString($logoUrl);
        $this->assertStringContainsString('/api/public-media/website/logo_', $logoUrl);
        $this->assertStringContainsString('/api/public-media/website/trust_image_1_', $response->json('data.settings.trust_image_1_url'));
        $this->assertStringContainsString('/api/public-media/website/trust_image_6_', $response->json('data.settings.trust_image_6_url'));
        $this->assertDatabaseHas('website_settings', ['brand_name' => 'Bimbelku Yogyakarta']);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'permission_code' => AdminPermissionCatalog::SETTINGS_MANAGE,
        ]);
    }

    public function test_media_page_changes_only_images_not_landing_text(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $section = WebsiteSection::query()->where('section_key', 'hero')->firstOrFail();
        $section->update(['title' => 'Judul landing yang harus tetap sama']);

        $this->getJson('/api/admin/website-media')->assertOk()->assertJsonStructure(['media']);
        $response = $this->post('/api/admin/website-media', [
            'hero_desktop_image' => UploadedFile::fake()->image('hero.jpg', 1200, 800),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertStringContainsString('/api/public-media/website/hero_desktop_image_',
            $response->json('media.hero_desktop_image_url'));
        $this->assertSame('Judul landing yang harus tetap sama', $section->fresh()->title);
        $this->postJson('/api/admin/website-media', [])->assertUnprocessable();
    }

    public function test_manual_trust_value_requires_source_note_and_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $payload = $this->validPayload();
        $payload['trust_items'][0]['source_type'] = 'manual';
        $payload['trust_items'][0]['source_key'] = null;
        $payload['trust_items'][0]['display_value'] = '120+';
        $payload['trust_items'][0]['source_note'] = null;
        $payload['trust_items'][0]['source_updated_at'] = null;

        $this->postJson('/api/admin/website-settings', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Catatan sumber dan tanggal pembaruan wajib diisi untuk data manual ke-1.');
    }

    public function test_admin_can_publish_verified_testimonial_with_private_proof(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $classroom = Classroom::create(['user_id' => $teacher->id, 'title' => 'Kelas testimoni', 'subject' => 'Matematika', 'type' => 'private']);
        $rating = Rating::create(['classroom_id' => $classroom->id, 'student_id' => $student->id, 'teacher_id' => $teacher->id, 'rating' => 5, 'review' => 'Belajar menjadi lebih terarah.']);
        Sanctum::actingAs($admin);
        $payload = $this->validPayload();
        $payload['testimonials'] = [[
            'rating_id' => $rating->id,
            'display_name' => 'Nadia A.',
            'audience_role' => 'Siswa kelas 12',
            'quote' => 'Belajar menjadi lebih terarah.',
            'program_name' => 'Persiapan SNBT',
            'outcome' => 'Diterima melalui SNBT',
            'institution' => 'Universitas Gadjah Mada',
            'major' => 'Statistika',
            'achievement_year' => now()->year,
            'consent_confirmed' => true,
            'verified' => true,
            'is_featured' => true,
            'is_visible' => true,
            'sort_order' => 0,
            'photo' => UploadedFile::fake()->image('nadia.jpg', 800, 1000),
            'proof' => UploadedFile::fake()->create('bukti.pdf', 300, 'application/pdf'),
        ]];

        $this->post('/api/admin/website-settings', $payload, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.testimonials.0.display_name', 'Nadia A.')
            ->assertJsonPath('data.testimonials.0.has_proof', true);

        $this->getJson('/api/website-content')
            ->assertOk()
            ->assertJsonPath('testimonials.0.display_name', 'Nadia A.')
            ->assertJsonPath('testimonials.0.is_verified', true)
            ->assertJsonPath('testimonials.0.verified_by_name', $admin->name)
            ->assertJsonPath('testimonials.0.verified_at', now()->toDateString())
            ->assertJsonMissingPath('testimonials.0.has_proof');

        $this->getJson('/api/testimonials')
            ->assertOk()
            ->assertJsonPath('data.0.outcome', 'Diterima melalui SNBT');
    }

    public function test_public_testimonial_requires_consent_verification_and_photo(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $classroom = Classroom::create(['user_id' => $teacher->id, 'title' => 'Kelas testimoni', 'subject' => 'Matematika', 'type' => 'private']);
        $rating = Rating::create(['classroom_id' => $classroom->id, 'student_id' => $student->id, 'teacher_id' => $teacher->id, 'rating' => 5, 'review' => 'Belajar menjadi lebih terarah.']);
        Sanctum::actingAs($admin);
        $payload = $this->validPayload();
        $payload['testimonials'] = [[
            'display_name' => 'Data belum siap',
            'quote' => 'Belum boleh dipublikasikan.',
            'consent_confirmed' => false,
            'verified' => false,
            'is_featured' => false,
            'is_visible' => true,
            'sort_order' => 0,
        ]];

        $this->postJson('/api/admin/website-settings', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Izin publikasi wajib dikonfirmasi untuk testimoni ke-1.');

        $payload['testimonials'][0]['consent_confirmed'] = true;
        $payload['testimonials'][0]['verified'] = true;
        $this->postJson('/api/admin/website-settings', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pilih rating murid nyata untuk testimoni publik ke-1.');

        $payload['testimonials'][0]['rating_id'] = $rating->id;
        $this->postJson('/api/admin/website-settings', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Kutipan testimoni ke-1 harus berasal dari ulasan rating murid yang dipilih.');
        $payload['testimonials'][0]['quote'] = $rating->review;
        $this->postJson('/api/admin/website-settings', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Foto asli wajib diunggah untuk testimoni publik ke-1.');

        $payload['testimonials'][0]['photo'] = UploadedFile::fake()->image('siswa.jpg');
        $this->post('/api/admin/website-settings', $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Bukti privat wajib diunggah sebelum testimoni ke-1 ditampilkan.');
    }

    private function validPayload(): array
    {
        $settings = $this->getJson('/api/admin/website-settings')->assertOk()->json('settings');
        $sections = WebsiteSection::query()->orderBy('sort_order')->get();
        $trustItems = WebsiteTrustItem::query()->orderBy('sort_order')->get();

        return [
            'brand_name' => $settings['brand_name'],
            'brand_description' => $settings['brand_description'],
            'information_bar_enabled' => $settings['information_bar_enabled'],
            'information_bar_text' => $settings['information_bar_text'],
            'primary_cta_label' => $settings['primary_cta_label'],
            'primary_cta_url' => $settings['primary_cta_url'],
            'whatsapp_enabled' => false,
            'whatsapp_number' => null,
            'whatsapp_label' => $settings['whatsapp_label'],
            'whatsapp_hours' => $settings['whatsapp_hours'],
            'whatsapp_default_message' => $settings['whatsapp_default_message'],
            'contact_email' => $settings['contact_email'],
            'office_address' => $settings['office_address'],
            'google_maps_url' => $settings['google_maps_url'],
            'animations_enabled' => $settings['animations_enabled'],
            'navigation_items' => collect($settings['navigation_items'])->map(fn (array $item) => [
                ...$item,
                'is_visible' => $item['is_visible'] ?? true,
            ])->all(),
            'sections' => $sections->map(fn (WebsiteSection $section) => [
                'id' => $section->id,
                'is_visible' => $section->is_visible,
                'sort_order' => $section->sort_order,
            ])->all(),
            'trust_items' => $trustItems->map(fn (WebsiteTrustItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'display_value' => $item->display_value,
                'description' => $item->description,
                'source_type' => $item->source_type,
                'source_key' => $item->source_key,
                'source_note' => $item->source_note,
                'source_updated_at' => optional($item->source_updated_at)->toDateString(),
                'is_visible' => $item->is_visible,
                'sort_order' => $item->sort_order,
            ])->all(),
        ];
    }
}
