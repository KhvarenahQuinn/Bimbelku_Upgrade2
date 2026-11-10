<?php

namespace Tests\Feature;

use App\Models\GuestPackageOrder;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UnifiedLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_login_refuses_admin_and_admin_login_uses_the_admin_portal(): void
    {
        User::factory()->create(['email' => 'admin@example.test', 'password' => Hash::make('password'), 'role' => 'admin', 'status' => 'active']);

        // Pintu masuk pengguna tidak boleh menerbitkan sesi admin.
        $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
            'login_portal' => 'user',
            'remember_device' => true,
        ])->assertForbidden()
            ->assertJsonPath('error_code', 'admin_portal_required');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Portal admin tetap memakai sesi pendek tanpa "ingat perangkat".
        $this->postJson('/api/login', [
            'email' => 'admin@example.test',
            'password' => 'password',
            'login_portal' => 'admin',
            'remember_device' => true,
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('remember_device', false);
    }

    public function test_admin_portal_cannot_be_used_to_elevate_a_student_or_teacher(): void
    {
        $student = User::factory()->create(['email' => 'student@example.test', 'password' => Hash::make('password'), 'role' => 'student', 'status' => 'active']);
        User::factory()->create(['email' => 'teacher@example.test', 'password' => Hash::make('password'), 'role' => 'teacher', 'status' => 'active']);

        $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => 'password',
            'login_portal' => 'admin',
            'role' => 'admin',
        ])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/login', [
            'email' => 'teacher@example.test',
            'password' => 'password',
            'login_portal' => 'admin',
        ])->assertForbidden();
    }

    public function test_legacy_portal_or_submitted_role_cannot_promote_student_to_admin(): void
    {
        User::factory()->create(['email' => 'student@example.test', 'password' => Hash::make('password'), 'role' => 'student', 'status' => 'active']);

        $login = $this->postJson('/api/login', [
            'email' => 'student@example.test',
            'password' => 'password',
            'role' => 'admin',
        ])->assertOk()->assertJsonPath('user.role', 'student');
        $this->withToken($login->json('access_token'))->getJson('/api/admin/subjects?all=1')->assertForbidden();
    }

    public function test_user_login_links_a_matching_pending_guest_order(): void
    {
        $student = User::factory()->create([
            'email' => 'murid.tamu@example.test',
            'password' => Hash::make('password'),
            'role' => 'student',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $guest = GuestPackageOrder::query()->create([
            'code' => 'GUEST-LINK-001',
            'email' => 'murid.tamu@example.test',
            'name' => 'Murid Tamu',
            'phone' => '081234567890',
            'payload' => [],
            'quoted_total_amount' => 150000,
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => 'password',
            'login_portal' => 'user',
        ])->assertOk()
            ->assertJsonPath('pending_guest_order', $guest->code);

        // Status draf tidak berubah; hanya kepemilikan akun yang ditautkan.
        $this->assertDatabaseHas('guest_package_orders', [
            'id' => $guest->id,
            'status' => 'pending',
            'student_id' => $student->id,
        ]);
    }

    public function test_verified_teacher_can_use_shared_login(): void
    {
        $teacher = User::factory()->create(['email' => 'teacher@example.test', 'password' => Hash::make('password'), 'role' => 'teacher', 'status' => 'active']);
        TeacherProfile::create(['user_id' => $teacher->id, 'expertise' => 'Matematika', 'verified_at' => now()]);
        $this->postJson('/api/login', ['email' => $teacher->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.role', 'teacher');
    }

    public function test_shared_login_rejects_inactive_admin_and_pending_teacher(): void
    {
        foreach ([['admin', 'rejected', 'admin'], ['teacher', 'pending', 'user']] as [$role, $status, $portal]) {
            $user = User::factory()->create(['password' => Hash::make('password'), 'role' => $role, 'status' => $status]);
            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password',
                'login_portal' => $portal,
            ])->assertForbidden();
            $this->assertSame(0, $user->tokens()->count());
        }
    }
}
