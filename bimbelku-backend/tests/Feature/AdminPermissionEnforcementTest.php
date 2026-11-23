<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `admin_permissions` harus benar-benar ditegakkan di server, bukan hanya
 * dipakai untuk menyembunyikan menu di klien.
 */
class AdminPermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bimbelku.primary_admin_email', '');
    }

    public function test_limited_admin_is_denied_outside_its_explicit_permissions(): void
    {
        $primary = $this->admin();
        $limited = $this->admin(['admin_permissions' => [AdminPermissionCatalog::CONTENT_MANAGE]]);

        $this->assertTrue($primary->isPrimaryAdmin());
        $this->assertFalse($limited->isPrimaryAdmin());

        Sanctum::actingAs($limited);

        $this->getJson('/api/admin/users?role=student')
            ->assertForbidden()
            ->assertJsonPath('required_permission', AdminPermissionCatalog::USERS_MANAGE)
            ->assertJsonPath('message', 'Akun admin ini tidak memiliki izin untuk membuka modul tersebut.');

        $this->getJson('/api/admin/finance/payments')
            ->assertForbidden()
            ->assertJsonPath('required_permission', AdminPermissionCatalog::FINANCE_PAYMENTS);

        $this->getJson('/api/admin/dashboard-stats')
            ->assertForbidden()
            ->assertJsonPath('required_permission', AdminPermissionCatalog::OPERATIONS_DASHBOARD);

        // Modul yang memang diberikan tetap terbuka.
        $this->getJson('/api/admin/subjects')->assertOk();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('is_primary_admin', false)
            ->assertJsonPath('admin_permissions', [AdminPermissionCatalog::CONTENT_MANAGE]);
    }

    public function test_primary_admin_keeps_full_access_and_reports_every_permission(): void
    {
        $primary = $this->admin(['admin_permissions' => null]);
        $this->admin(['admin_permissions' => [AdminPermissionCatalog::CONTENT_MANAGE]]);

        Sanctum::actingAs($primary);

        $this->getJson('/api/admin/users?role=student')->assertOk();
        $this->getJson('/api/admin/finance/payments')->assertOk();
        $this->getJson('/api/admin/subjects')->assertOk();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('is_primary_admin', true)
            ->assertJsonPath('admin_permissions', AdminPermissionCatalog::allCodes());
    }

    public function test_legacy_admin_without_an_explicit_permission_list_keeps_full_access(): void
    {
        $this->admin();
        $legacy = $this->admin(['admin_permissions' => null]);

        $this->assertFalse($legacy->isPrimaryAdmin());

        Sanctum::actingAs($legacy);

        $this->getJson('/api/admin/users?role=student')->assertOk();
        $this->getJson('/api/admin/finance/payments')->assertOk();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('admin_permissions', AdminPermissionCatalog::allCodes());
    }

    public function test_admin_with_an_empty_permission_list_is_restricted_to_nothing(): void
    {
        $this->admin();
        $restricted = $this->admin(['admin_permissions' => []]);

        Sanctum::actingAs($restricted);

        $this->getJson('/api/admin/subjects')->assertForbidden();
        $this->getJson('/api/admin/users?role=student')->assertForbidden();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('admin_permissions', []);
    }

    private function admin(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'admin',
            'status' => 'active',
        ], $overrides));
    }
}
