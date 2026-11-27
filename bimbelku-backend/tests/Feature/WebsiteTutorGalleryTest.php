<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\WebsiteSection;
use App\Models\WebsiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebsiteTutorGalleryTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_gallery_entry_requires_admin_confirmation_and_can_be_edited(): void
    {
        $this->getJson('/api/website-tutor-gallery')->assertOk()->assertJsonCount(0);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $data = [
            'display_name' => 'Tutor Nisa',
            'degree' => 'S.Pd.',
            'description' => 'Pengajar Matematika SMP',
            'consent_source' => 'Persetujuan tertulis pada 29 September 2026',
            'is_visible' => true,
        ];
        $this->postJson('/api/admin/website-tutor-gallery', $data)->assertUnprocessable();
        $created = $this->postJson('/api/admin/website-tutor-gallery', $data + [
            'consent_confirmed' => true,
            'identity_confirmed' => true,
        ])->assertCreated()->assertJsonPath('entry.display_name', 'Tutor Nisa');
        $id = $created->json('entry.id');

        $this->getJson('/api/website-tutor-gallery')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.degree', 'S.Pd.')
            ->assertJsonMissingPath('0.consent_source');
        $this->postJson('/api/admin/website-tutor-gallery/'.$id, array_merge($data, [
            'display_name' => 'Tutor Nisa A.',
            'is_visible' => false,
            'consent_confirmed' => true,
            'identity_confirmed' => true,
        ]))->assertOk()->assertJsonPath('entry.display_name', 'Tutor Nisa A.');
        $this->getJson('/api/website-tutor-gallery')->assertOk()->assertJsonCount(0);
    }

    public function test_linked_gallery_card_disappears_if_tutor_revokes_public_consent(): void
    {
        $user = User::factory()->create(['name' => 'Tutor Asli', 'role' => 'teacher', 'status' => 'active']);
        $profile = tap(TeacherProfile::query()->create([
            'user_id' => $user->id,
            'public_profile_enabled' => true,
            'public_profile_consent_at' => now(),
            'public_degree' => 'S.Pd.',
        ]), fn ($profile) => $profile->forceFill(['verified_at' => now(), 'public_directory_approved_at' => now()])->save());
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->getJson('/api/admin/website-tutor-gallery/candidates')->assertOk()
            ->assertJsonPath('0.name', 'Tutor Asli')
            ->assertJsonPath('0.degree', 'S.Pd.');
        $this->getJson('/api/admin/website-tutor-gallery/candidates?q='.urlencode('S.Pd.'))
            ->assertOk()->assertJsonCount(1);
        $this->postJson('/api/admin/website-tutor-gallery', [
            'teacher_profile_id' => $profile->id,
            'display_name' => 'Tutor Asli',
            'degree' => 'S.Pd.',
            'consent_source' => 'Persetujuan di akun tutor',
            'consent_confirmed' => true,
            'identity_confirmed' => true,
            'is_visible' => true,
        ])->assertCreated()->assertJsonPath('entry.photo_from_account', true);
        $this->getJson('/api/website-tutor-gallery')->assertOk()->assertJsonCount(1);

        $profile->forceFill(['public_profile_enabled' => false, 'public_directory_approved_at' => null])->save();
        $this->getJson('/api/website-tutor-gallery')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/admin/website-tutor-gallery/candidates')->assertOk()->assertJsonCount(0);
    }

    public function test_legacy_photos_are_imported_as_unpublished_editable_drafts(): void
    {
        WebsiteSetting::query()->firstOrCreate(['singleton_key' => 1])->update([
            'trust_image_1_path' => 'photos/legacy-tutor.jpg',
        ]);
        WebsiteSection::query()->updateOrCreate(['section_key' => 'trust'], [
            'label' => 'Tutor',
            'content' => [
                'trust_media_titles' => ['Tutor Lama'],
                'trust_media_notes' => ['Alumni kampus'],
            ],
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->postJson('/api/admin/website-tutor-gallery/import-legacy')
            ->assertOk()->assertJsonPath('created', 1);
        $this->postJson('/api/admin/website-tutor-gallery/import-legacy')
            ->assertOk()->assertJsonPath('created', 0);
        $this->getJson('/api/admin/website-tutor-gallery')->assertOk()
            ->assertJsonPath('data.0.display_name', 'Tutor Lama')
            ->assertJsonPath('data.0.is_visible', false);
        $this->getJson('/api/website-tutor-gallery')->assertOk()->assertJsonCount(0);
    }
}
