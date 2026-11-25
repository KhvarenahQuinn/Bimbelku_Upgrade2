<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\AdminAuditLog;
use App\Models\CurriculumSubject;
use App\Models\HourlyRate;
use App\Models\User;
use App\Services\AdminActivityLogService;
use App\Services\AdminAuditService;
use App\Support\AdminPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bimbelku.primary_admin_email', '');
    }

    public function test_admin_account_creation_and_permission_update_are_recorded(): void
    {
        $primary = $this->primaryAdmin();
        Sanctum::actingAs($primary);

        $created = $this->postJson('/api/admin/accounts', [
            'name' => 'Operations Admin',
            'email' => 'ops@example.test',
            'password' => 'StrongInitialPass123!',
            'admin_permissions' => [AdminPermissionCatalog::USERS_MANAGE],
            'super_admin_password' => 'PrimaryAdminPass123!',
        ])->assertCreated()->json('data');

        $target = User::findOrFail($created['id']);
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'account.created',
            'actor_id' => $primary->id,
            'target_id' => $target->id,
        ]);

        $this->putJson('/api/admin/accounts/'.$target->id, [
            'name' => 'Operations Admin',
            'email' => 'ops@example.test',
            'password' => null,
            'admin_permissions' => [AdminPermissionCatalog::AUDIT_VIEW],
            'super_admin_password' => 'PrimaryAdminPass123!',
        ])->assertOk();

        $permissionsLog = AdminActivityLog::query()->where('action', 'permissions.updated')->sole();
        $this->assertSame([AdminPermissionCatalog::USERS_MANAGE], $permissionsLog->metadata['before']['admin_permissions']);
        $this->assertSame([AdminPermissionCatalog::AUDIT_VIEW], $permissionsLog->metadata['after']['admin_permissions']);
    }

    public function test_hourly_rate_update_records_before_and_after_values(): void
    {
        $subject = CurriculumSubject::create([
            'name' => 'Matematika',
            'normalized_name' => 'matematika',
            'group_name' => 'Wajib',
            'education_levels' => ['SMP'],
            'is_active' => true,
        ]);
        $rate = HourlyRate::create([
            'subject_name' => $subject->name,
            'curriculum_subject_id' => $subject->id,
            'education_level' => 'SMP',
            'class_type' => 'private',
            'learning_mode' => 'online',
            'amount' => 100000,
            'is_active' => true,
        ]);
        $admin = $this->primaryAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/hourly-rates', [
            'subject_name' => $subject->name,
            'education_level' => 'SMP',
            'class_type' => 'private',
            'learning_mode' => 'online',
            'amount' => 125000,
        ])->assertOk();

        $log = AdminActivityLog::query()->where('action', 'hourly_rate.updated')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($rate->id, $log->target_id);
        $this->assertSame(100000, $log->metadata['before']['amount']);
        $this->assertSame(125000, $log->metadata['after']['amount']);
    }

    public function test_admin_login_records_success_and_failure_with_client_ip_without_credentials(): void
    {
        $admin = User::factory()->create([
            'email' => 'login-admin@example.test',
            'password' => Hash::make('AdminLoginPass123!'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->postJson('/api/login/admin', [
                'email' => $admin->email,
                'password' => 'AdminLoginPass123!',
            ])->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
            ->postJson('/api/login/admin', [
                'email' => $admin->email,
                'password' => 'DoNotLogThisPass123!',
            ])->assertUnauthorized();

        $success = AdminActivityLog::query()->where('action', 'admin.login.succeeded')->sole();
        $failure = AdminActivityLog::query()->where('action', 'admin.login.failed')->sole();
        $this->assertSame($admin->id, $success->actor_id);
        $this->assertSame('203.0.113.42', $success->ip_address);
        $this->assertSame('198.51.100.24', $failure->ip_address);
        $this->assertSame('failed', $failure->outcome);
        $this->assertSame($admin->id, AdminActivityLog::query()->where('action', 'admin.login.failed')->where('target_id', $admin->id)->value('actor_id'));
        $this->assertStringNotContainsString('DoNotLogThisPass123!', (string) $failure->getRawOriginal('metadata'));

        $sanitized = app(AdminActivityLogService::class)->sanitize([
            'before' => ['pin' => '1234', 'amount' => 100],
            'after' => ['password' => 'hidden', 'amount' => 200],
        ]);
        $this->assertSame(['before' => ['amount' => 100], 'after' => ['amount' => 200]], $sanitized);
    }

    public function test_activity_endpoint_is_admin_permission_filtered_and_paginated(): void
    {
        $primary = $this->primaryAdmin();
        Sanctum::actingAs($primary);
        app(\App\Services\AdminActivityLogService::class)->record(request(), $primary, 'pricing', 'hourly_rate.updated', 'Tarif Matematika diperbarui.');
        app(\App\Services\AdminActivityLogService::class)->record(request(), $primary, 'users', 'user.status_changed', 'Status pengguna berubah.', outcome: 'failed');

        $this->getJson('/api/admin/activity-logs?module=pricing&outcome=success&search=Matematika')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.module', 'pricing');

        $limited = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'admin_permissions' => [AdminPermissionCatalog::USERS_MANAGE],
        ]);
        Sanctum::actingAs($limited);
        $this->getJson('/api/admin/activity-logs')->assertForbidden();
    }

    public function test_financial_audit_hash_chain_remains_independent_and_valid(): void
    {
        $admin = $this->primaryAdmin();
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/notes', [
            'title' => 'Financial chain stays intact',
            'content' => 'Separate operational activity must not alter the financial chain.',
        ])->assertOk();
        app(AdminActivityLogService::class)->record(request(), $admin, 'pricing', 'rate.test', 'Test activity.');

        $this->getJson('/api/admin/audit-log/integrity')
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('checked', 1);
        $this->assertSame(1, AdminAuditLog::query()->count());
        $this->assertSame(1, AdminActivityLog::query()->count());
        $this->assertTrue(app(AdminAuditService::class)->verifyChain(AdminAuditLog::query()->oldest('id')->get())['valid']);
    }

    private function primaryAdmin(): User
    {
        return User::factory()->create([
            'name' => 'Primary Admin',
            'email' => 'primary@example.test',
            'password' => Hash::make('PrimaryAdminPass123!'),
            'role' => 'admin',
            'status' => 'active',
            'admin_type' => 'super_admin',
            'admin_permissions' => null,
        ]);
    }
}
