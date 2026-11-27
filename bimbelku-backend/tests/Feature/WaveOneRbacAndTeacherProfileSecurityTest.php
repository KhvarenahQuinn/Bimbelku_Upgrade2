<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\CurriculumSubject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\AdminPermissionCatalog;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WaveOneRbacAndTeacherProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_subadmin_permissions_are_enforced_and_class_actions_respect_caller_permissions(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $subject = CurriculumSubject::query()->first();

        $bookingRequest = BookingRequest::query()->create([
            'student_id' => $student->id,
            'subject_name' => 'Matematika',
            'curriculum_subject_id' => $subject?->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 8',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'chapter' => 'Aljabar',
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
            'duration_hours' => 1,
            'status' => 'matched',
        ]);

        $secondBookingRequest = BookingRequest::query()->create([
            'student_id' => $student->id,
            'subject_name' => 'Fisika',
            'curriculum_subject_id' => $subject?->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 8',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'chapter' => 'Gerak',
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'duration_hours' => 1,
            'status' => 'matched',
        ]);

        Booking::query()->create([
            'booking_request_id' => $bookingRequest->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'class_type' => 'private',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'hourly_rate' => 150000,
            'status' => 'refund_pending',
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'total_amount' => 150000,
            'gross_amount' => 150000,
            'teacher_net_amount' => 120000,
            'commission_percent' => 20,
        ]);

        Booking::query()->create([
            'booking_request_id' => $secondBookingRequest->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'class_type' => 'private',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'hourly_rate' => 150000,
            'status' => 'disputed',
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHour(),
            'total_amount' => 150000,
            'gross_amount' => 150000,
            'teacher_net_amount' => 120000,
            'commission_percent' => 20,
        ]);

        // Primary admin exists first so sub-admins are not treated as primary admin.
        $primaryAdmin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $classesOnlyAdmin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'admin_permissions' => [AdminPermissionCatalog::CLASSES_MANAGE],
        ]);
        $financeOnlyAdmin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'admin_permissions' => [AdminPermissionCatalog::FINANCE_REPORTS],
        ]);

        // 1. Sub-admin with only classes.manage can read /api/admin/classes, sees null actions for refund/case rows,
        // and gets 403 on protected finance/refund/case endpoints.
        Sanctum::actingAs($classesOnlyAdmin);
        $classesResponse = $this->getJson('/api/admin/classes')->assertOk();
        $rows = collect($classesResponse->json('data'));
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row['action']);
        }
        $this->getJson('/api/admin/finance/refunds')->assertForbidden();
        $this->getJson('/api/admin/cases')->assertForbidden();
        $this->getJson('/api/admin/finance')->assertForbidden();
        $this->getJson('/api/admin/finance/report')->assertForbidden();

        // 2. Sub-admin with only finance.reports can read finance report, but gets 403 on classes/cases/users.
        Sanctum::actingAs($financeOnlyAdmin);
        $this->getJson('/api/admin/finance/report')->assertOk();
        $this->getJson('/api/admin/classes')->assertForbidden();
        $this->getJson('/api/admin/cases')->assertForbidden();
        $this->getJson('/api/admin/users?role=student')->assertForbidden();

        // 3. Primary admin receives both action URLs on /api/admin/classes.
        Sanctum::actingAs($primaryAdmin);
        $fullClasses = $this->getJson('/api/admin/classes')->assertOk();
        $actionUrls = collect($fullClasses->json('data'))->pluck('action.url')->all();
        $this->assertContains('/admin/refunds', $actionUrls);
        $this->assertContains('/admin/cases', $actionUrls);
    }

    public function test_teacher_profile_endpoint_excludes_totp_secret_and_bank_fingerprint(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
            'finance_totp_secret' => 'JBSWY3DPEHPK3PXP_SECRET_VALUE',
        ]);
        $profile = TeacherProfile::query()->create([
            'user_id' => $teacher->id,
            'expertise' => 'Matematika',
            'bio' => 'Tutor berpengalaman',
            'whatsapp_number' => '081234567890',
        ]);
        $profile->forceFill([
            'bank_name' => 'BCA',
            'payout_channel_code' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'bank_account_fingerprint' => 'hmac_fingerprint_secret_12345',
            'verified_at' => now(),
        ])->save();

        Sanctum::actingAs($teacher);
        $response = $this->getJson('/api/teacher/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $teacher->id)
            ->assertJsonPath('profile.expertise', 'Matematika')
            ->assertJsonMissingPath('user.finance_totp_secret')
            ->assertJsonMissingPath('user.finance_totp_confirmed_at')
            ->assertJsonMissingPath('user.teacher_profile')
            ->assertJsonMissingPath('profile.bank_account_fingerprint');

        $rawBody = $response->getContent();
        $this->assertStringNotContainsString('finance_totp_secret', $rawBody);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP_SECRET_VALUE', $rawBody);
        $this->assertStringNotContainsString('bank_account_fingerprint', $rawBody);
        $this->assertStringNotContainsString('hmac_fingerprint_secret_12345', $rawBody);
    }

    public function test_teacher_profile_rejects_mass_assignment_of_privileged_columns(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::query()->create([
            'user_id' => $teacher->id,
            'expertise' => 'Fisika',
        ]);

        Model::preventSilentlyDiscardingAttributes(true);
        try {
            $this->expectException(MassAssignmentException::class);
            $profile->fill([
                'verified_at' => now(),
                'verified_by' => 1,
                'assignment_count' => 99,
                'bank_account_fingerprint' => 'forged-fp',
                'bank_details_version' => 99,
                'payout_hold_until' => now()->subYear(),
                'suspended_until' => null,
            ]);
        } finally {
            Model::preventSilentlyDiscardingAttributes(false);
        }
    }
}
