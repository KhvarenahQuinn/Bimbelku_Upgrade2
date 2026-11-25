<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccountPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bimbelku.primary_admin_email', '');
    }

    public function test_primary_admin_can_create_and_update_admin_permission_presets(): void
    {
        $primary = $this->admin('primary@example.test');
        Sanctum::actingAs($primary);
        $financePermissions = [
            AdminPermissionCatalog::FINANCE_PAYMENTS,
            AdminPermissionCatalog::FINANCE_PAYOUTS,
            AdminPermissionCatalog::FINANCE_REFUNDS,
            AdminPermissionCatalog::FINANCE_REPORTS,
        ];

        $created = $this->postJson('/api/admin/accounts', [
            'name' => 'Finance Admin',
            'email' => 'finance@example.test',
            'password' => 'long-secure-password',
            'admin_permissions' => $financePermissions,
            'super_admin_password' => 'password',
        ])->assertCreated()
            ->assertJsonPath('data.admin_permissions', $financePermissions);

        $id = (int) $created->json('data.id');
        $this->assertDatabaseHas('users', [
            'id' => $id,
            'role' => 'admin',
            'admin_type' => 'standard_admin',
            'admin_permissions_updated_by' => $primary->id,
        ]);
        $this->assertSame($financePermissions, $this->user($id)->admin_permissions);

        $updatedPermissions = [AdminPermissionCatalog::CASES_MANAGE, AdminPermissionCatalog::AUDIT_VIEW];
        $this->putJson('/api/admin/accounts/'.$id, [
            'name' => 'Audit and Cases',
            'email' => 'audit@example.test',
            'password' => null,
            'admin_permissions' => $updatedPermissions,
            'super_admin_password' => 'password',
        ])->assertOk()->assertJsonPath('data.admin_permissions', $updatedPermissions);

        $this->assertSame($updatedPermissions, $this->user($id)->admin_permissions);
        $this->assertSame((int) $primary->id, (int) $this->user($id)->admin_permissions_updated_by);
    }

    public function test_unknown_permissions_are_rejected_without_creating_or_mutating_admins(): void
    {
        $primary = $this->admin('primary@example.test');
        Sanctum::actingAs($primary);

        $this->postJson('/api/admin/accounts', [
            'name' => 'Invalid Admin',
            'email' => 'invalid@example.test',
            'password' => 'long-secure-password',
            'admin_permissions' => ['superuser.all'],
            'super_admin_password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('admin_permissions.0');
        $this->assertDatabaseMissing('users', ['email' => 'invalid@example.test']);

        $admin = $this->admin('limited@example.test', [AdminPermissionCatalog::CONTENT_MANAGE]);
        $this->putJson('/api/admin/accounts/'.$admin->id, [
            'name' => 'Limited Admin',
            'email' => 'limited@example.test',
            'password' => null,
            'admin_permissions' => ['superuser.all'],
            'super_admin_password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('admin_permissions.0');
        $this->assertSame([AdminPermissionCatalog::CONTENT_MANAGE], $admin->fresh()->admin_permissions);
    }

    public function test_configured_primary_admin_permission_list_cannot_be_restricted(): void
    {
        $primary = $this->admin('primary@example.test', [AdminPermissionCatalog::CONTENT_MANAGE]);
        config()->set('bimbelku.primary_admin_email', $primary->email);
        $primary->unsetRelation('adminPermissionsUpdatedBy');
        Sanctum::actingAs($primary);

        $this->putJson('/api/admin/accounts/'.$primary->id, [
            'name' => 'Primary Admin',
            'email' => 'primary@example.test',
            'password' => null,
            'admin_permissions' => [AdminPermissionCatalog::AUDIT_VIEW],
            'super_admin_password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.admin_permissions', AdminPermissionCatalog::allCodes());

        $this->assertNull($primary->fresh()->admin_permissions);
        $this->assertTrue($primary->fresh()->hasAdminPermission(AdminPermissionCatalog::FINANCE_PAYOUTS));
    }

    public function test_admin_permission_catalog_is_returned_for_primary_admin(): void
    {
        Sanctum::actingAs($this->admin('primary@example.test'));

        $this->getJson('/api/admin/accounts/permission-catalog')->assertOk()
            ->assertJsonPath('groups.0.key', 'operations');
    }

    private function admin(string $email, ?array $permissions = null): User
    {
        return User::factory()->create([
            'name' => 'Admin User',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'admin',
            'status' => 'active',
            'admin_permissions' => $permissions,
        ]);
    }

    private function user(int $id): User
    {
        return User::query()->findOrFail($id);
    }
}
