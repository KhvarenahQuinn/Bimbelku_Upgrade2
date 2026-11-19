<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherCoverSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_replace_global_teacher_cover_and_public_url_updates(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $first = $this->postJson('/api/admin/settings/teacher-cover', [
            'image' => UploadedFile::fake()->image('sampul-pertama.jpg', 8, 8),
        ])->assertOk()->json('url');
        $firstPath = Setting::where('key', 'teacher_cover_path')->value('value');
        Storage::disk('public')->assertExists($firstPath);

        $second = $this->postJson('/api/admin/settings/teacher-cover', [
            'image' => UploadedFile::fake()->image('sampul-kedua.jpg', 8, 8),
        ])->assertOk()->json('url');
        $secondPath = Setting::where('key', 'teacher_cover_path')->value('value');

        $this->assertNotSame($firstPath, $secondPath);
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $response = $this->getJson('/api/settings/teacher-cover')
            ->assertOk()
            ->assertJsonPath('url', strtok($second, '?'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_teacher_can_replace_own_profile_cover_without_resubmitting_identity(): void
    {
        Storage::fake('public');
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Sanctum::actingAs($teacher);

        $first = $this->postJson('/api/teacher/profile/cover', [
            'profile_cover' => UploadedFile::fake()->image('sampul-profil-1.jpg', 8, 8),
        ])->assertOk()->json('profile_cover_url');
        $firstPath = $teacher->fresh()->profile_cover;
        Storage::disk('public')->assertExists($firstPath);

        $second = $this->postJson('/api/teacher/profile/cover', [
            'profile_cover' => UploadedFile::fake()->image('sampul-profil-2.jpg', 8, 8),
        ])->assertOk()->json('profile_cover_url');

        $this->assertNotSame($first, $second);
        $this->assertSame('active', $teacher->fresh()->status);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($teacher->fresh()->profile_cover);
        $this->getJson('/api/teacher/profile')->assertJsonPath('profile.profile_cover_url', $second);
    }

    public function test_admin_can_replace_one_teacher_cover_and_return_it_to_default(): void
    {
        Storage::fake('public');
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $url = $this->postJson("/api/admin/users/{$teacher->id}/profile-cover", [
            'profile_cover' => UploadedFile::fake()->image('sampul-khusus.jpg', 8, 8),
        ])->assertOk()->json('profile_cover_url');

        $path = $teacher->fresh()->profile_cover;
        Storage::disk('public')->assertExists($path);
        $this->getJson('/api/admin/users?role=teacher')
            ->assertOk()
            ->assertJsonPath('data.0.profile_cover_url', $url);

        $this->postJson("/api/admin/users/{$student->id}/profile-cover", [
            'profile_cover' => UploadedFile::fake()->image('salah-akun.jpg', 8, 8),
        ])->assertNotFound();

        $this->deleteJson("/api/admin/users/{$teacher->id}/profile-cover")
            ->assertOk()
            ->assertJsonPath('profile_cover_url', null);
        $this->assertSame($path, $teacher->fresh()->profile_cover);
        $this->assertTrue($teacher->fresh()->profile_cover_use_default);
        Storage::disk('public')->assertExists($path);

        $this->postJson("/api/admin/users/{$teacher->id}/profile-cover/restore")
            ->assertOk()
            ->assertJsonPath('profile_cover_url', $url);
        $this->assertFalse($teacher->fresh()->profile_cover_use_default);
    }

    public function test_admin_can_apply_default_to_existing_teachers_without_deleting_personal_files(): void
    {
        Storage::fake('public');
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Sanctum::actingAs($teacher);
        $this->postJson('/api/teacher/profile/cover', [
            'profile_cover' => UploadedFile::fake()->image('pribadi.jpg', 8, 8),
        ])->assertOk();
        $personalPath = $teacher->fresh()->profile_cover;

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->postJson('/api/admin/settings/teacher-cover', [
            'image' => UploadedFile::fake()->image('default.jpg', 8, 8),
        ])->assertOk();
        $this->getJson('/api/admin/settings/teacher-cover/status')
            ->assertOk()
            ->assertJsonPath('teacher_count', 1)
            ->assertJsonPath('custom_cover_count', 1);

        $this->postJson('/api/admin/settings/teacher-cover/apply-default')
            ->assertOk()
            ->assertJsonPath('updated_count', 1);
        $this->assertTrue($teacher->fresh()->profile_cover_use_default);
        Storage::disk('public')->assertExists($personalPath);
        $this->getJson('/api/admin/settings/teacher-cover/status')
            ->assertJsonPath('custom_cover_count', 0);

        Sanctum::actingAs($teacher);
        $this->getJson('/api/teacher/profile')->assertJsonPath('profile.profile_cover_url', null);
    }
}
