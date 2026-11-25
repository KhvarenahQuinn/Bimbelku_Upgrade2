<?php

namespace Tests\Feature;

use App\Models\CurriculumSubject;
use App\Models\HourlyRate;
use App\Models\User;
use App\Support\AdminPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBatchOneParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bimbelku.primary_admin_email', '');
    }

    public function test_finance_report_requires_its_own_permission_not_payout_access(): void
    {
        $this->admin([]); // Establish a primary admin before testing restricted permissions.
        $payoutsAdmin = $this->admin([AdminPermissionCatalog::FINANCE_PAYOUTS]);
        Sanctum::actingAs($payoutsAdmin);

        $this->getJson('/api/admin/finance/report')
            ->assertForbidden()
            ->assertJsonPath('required_permission', AdminPermissionCatalog::FINANCE_REPORTS);
        $this->get('/api/admin/finance/report.pdf')
            ->assertForbidden()
            ->assertJsonPath('required_permission', AdminPermissionCatalog::FINANCE_REPORTS);

        $reportAdmin = $this->admin([AdminPermissionCatalog::FINANCE_REPORTS]);
        Sanctum::actingAs($reportAdmin);
        $this->getJson('/api/admin/finance/report')->assertOk();
        $this->get('/api/admin/finance/report.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');

        Sanctum::actingAs($reportAdmin);
        $this->getJson('/api/admin/finance')->assertForbidden()
            ->assertJsonPath('required_permission', AdminPermissionCatalog::FINANCE_PAYOUTS);
    }

    public function test_each_finance_module_remains_independently_authorized(): void
    {
        $this->admin([]);
        $this->admin(); // Establish a primary admin before testing restricted permissions.
        $matrix = [
            AdminPermissionCatalog::FINANCE_PAYMENTS => [
                'allowed' => '/api/admin/finance/payments',
                'denied' => ['/api/admin/finance/refunds', '/api/admin/finance/report'],
            ],
            AdminPermissionCatalog::FINANCE_REFUNDS => [
                'allowed' => '/api/admin/finance/refunds',
                'denied' => ['/api/admin/finance/payments', '/api/admin/finance/report'],
            ],
        ];

        foreach ($matrix as $permission => $paths) {
            Sanctum::actingAs($this->admin([$permission]));
            $this->getJson($paths['allowed'])->assertOk();
            foreach ($paths['denied'] as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }
    }

    public function test_active_restricted_admin_can_read_its_own_profile_without_dashboard_permission(): void
    {
        $this->admin(); // Establish a primary admin so this account stays restricted.
        $admin = $this->admin([]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $admin->id)
            ->assertJsonPath('admin_permissions', []);
    }

    public function test_hourly_rate_index_exposes_active_state_and_default_values(): void
    {
        $subject = CurriculumSubject::create([
            'name' => 'Matematika',
            'normalized_name' => 'matematika',
            'group_name' => 'Wajib',
            'education_levels' => ['SMP'],
            'is_active' => true,
        ]);
        HourlyRate::create([
            'subject_name' => $subject->name,
            'curriculum_subject_id' => $subject->id,
            'education_level' => 'SMP',
            'class_type' => 'private',
            'learning_mode' => 'online',
            'amount' => 125000,
            'is_active' => false,
        ]);
        Sanctum::actingAs($this->admin([AdminPermissionCatalog::CONTENT_MANAGE]));

        $this->getJson('/api/admin/hourly-rates')
            ->assertOk()
            ->assertJsonPath('rates.0.is_active', false)
            ->assertJsonPath('rates.0.amount', '125000.00');
    }

    public function test_hourly_rate_and_default_amounts_enforce_the_rupiah_range(): void
    {
        $this->admin([]);
        $contentAdmin = $this->admin([AdminPermissionCatalog::CONTENT_MANAGE]);
        $subject = CurriculumSubject::create([
            'name' => 'Matematika',
            'normalized_name' => 'matematika',
            'group_name' => 'Wajib',
            'education_levels' => ['SMP'],
            'is_active' => true,
        ]);
        Sanctum::actingAs($contentAdmin);

        foreach ([999, 10000001] as $amount) {
            $this->postJson('/api/admin/hourly-rates', [
                'subject_name' => 'Matematika',
                'education_level' => 'SMP',
                'class_type' => 'private',
                'learning_mode' => 'online',
                'amount' => $amount,
            ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }

        $this->postJson('/api/admin/hourly-rates', [
            'subject_name' => $subject->name,
            'education_level' => 'SMP',
            'class_type' => 'private',
            'learning_mode' => 'online',
            'amount' => 1000,
        ])->assertOk();
        $this->postJson('/api/admin/hourly-rates', [
            'subject_name' => $subject->name,
            'education_level' => 'SMP',
            'class_type' => 'private',
            'learning_mode' => 'offline',
            'amount' => 10000000,
        ])->assertOk();

        foreach ([999, 10000001] as $amount) {
            $this->postJson('/api/admin/hourly-rates/defaults', [
                'private_online' => $amount,
                'private_offline' => 40000,
            ])->assertUnprocessable()->assertJsonValidationErrors('private_online');
        }
        $this->postJson('/api/admin/hourly-rates/defaults', [
            'private_online' => 1000,
            'private_offline' => 10000000,
        ])->assertOk();
    }

    private function admin(array $permissions = []): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'admin_permissions' => $permissions,
        ]);
    }
}
