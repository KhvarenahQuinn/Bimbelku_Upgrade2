<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bimbelku.primary_admin_email', '');
    }

    public function test_admin_can_update_user_and_email_change_requires_reverification(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'admin_type' => 'single_admin']);
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'email' => 'murid@gmail.com',
            'email_verified_at' => now(),
        ]);
        $student->createToken('old-session');
        $originalName = $student->name;
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$student->id}/details", [
            'name' => 'Murid Baru',
            'email' => 'murid.baru@outlook.com',
            'phone' => '+62 812-3456-7890',
            'guardian_name' => 'Wali Murid',
            'guardian_phone' => '081234567890',
            'guardian_relationship' => 'wali_keluarga',
        ])->assertOk()
            ->assertJsonPath('data.email_verification_required', true)
            ->assertJsonPath('data.email', 'murid.baru@outlook.com');

        $student->refresh();
        $this->assertSame('Murid Baru', $student->name);
        $this->assertSame('6281234567890', $student->phone);
        $this->assertNull($student->email_verified_at);
        $this->assertNotNull($student->email_verification_required_at);
        $this->assertSame(0, $student->tokens()->count());
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'target_type' => 'User',
            'target_id' => $student->id,
            'response_status' => 200,
        ]);
    }

    public function test_admin_user_update_rejects_duplicate_or_disallowed_email_without_mutation(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'admin_type' => 'single_admin']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active', 'email' => 'murid@gmail.com']);
        $originalName = $student->name;
        User::factory()->create(['email' => 'existing@yahoo.com']);
        Sanctum::actingAs($admin);

        $payload = [
            'name' => 'Murid Diperbarui',
            'phone' => '081234567890',
        ];
        $this->patchJson("/api/admin/users/{$student->id}/details", $payload + ['email' => 'existing@yahoo.com'])->assertUnprocessable();
        $this->patchJson("/api/admin/users/{$student->id}/details", $payload + ['email' => 'person@unknown.test'])->assertUnprocessable();

        $this->assertSame('murid@gmail.com', $student->fresh()->email);
        $this->assertSame($originalName, $student->fresh()->name);
    }
}
