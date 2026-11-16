<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\User;
use App\Services\TeacherPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Angka saldo tutor punya tiga konsumen: dashboard gaji, dashboard operasional,
 * dan plafon pencairan. Ketiganya harus sepakat. Sebelum dipusatkan ke
 * TeacherPayoutService::balanceSummary, dashboard operasional melewatkan filter
 * sengketa dan memakai rumus available yang berbeda.
 */
class TeacherBalanceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function booking(User $teacher, array $attributes): Booking
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $start = now()->subDay()->startOfHour();
        $request = BookingRequest::create([
            'student_id' => $student->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addHour()->format('H:i:s'),
            'duration_hours' => 1,
            'status' => 'confirmed',
            'hourly_rate' => 100000,
            'total_amount' => 100000,
        ]);

        return Booking::create(array_merge([
            'booking_request_id' => $request->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'hourly_rate' => 100000,
            'total_amount' => 100000,
            'commission_percent' => 20,
            'status' => 'completed',
            'completed_at' => now(),
            'gross_amount' => 100000,
            'teacher_net_amount' => 80000,
            'teacher_paid_amount' => 0,
            'teacher_reserved_amount' => 0,
            'payout_status' => 'ready',
        ], $attributes));
    }

    public function test_salary_and_operations_dashboard_report_the_same_balance(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->booking($teacher, ['payout_status' => 'ready', 'teacher_net_amount' => 80000]);
        $this->booking($teacher, ['payout_status' => 'locked', 'teacher_net_amount' => 50000]);

        Sanctum::actingAs($teacher);
        $salary = $this->getJson('/api/teacher/salary')->assertOk()->json('balances');
        $operations = $this->getJson('/api/teacher/dashboard-v2')->assertOk()->json('earnings');

        $this->assertSame($salary['available'], $operations['available'], 'available harus identik di kedua dashboard');
        $this->assertSame($salary['held'], $operations['held'], 'held harus identik di kedua dashboard');
        $this->assertSame(130000.0, (float) $salary['available']);

        // Plafon pencairan memakai service yang sama.
        $this->assertSame(
            (float) $salary['available'],
            app(TeacherPayoutService::class)->availableAmount($teacher->id),
            'angka dashboard harus sama dengan plafon pencairan'
        );
    }

    public function test_unresolved_dispute_reduces_balance_in_both_dashboards(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->booking($teacher, ['teacher_net_amount' => 80000]);
        $disputed = $this->booking($teacher, ['teacher_net_amount' => 60000]);
        $disputed->disputes()->create([
            'kind' => 'session',
            'student_id' => $disputed->student_id,
            'reason' => 'Sesi tidak sesuai',
            'status' => 'open',
            'resolved_at' => null,
        ]);

        $summary = app(TeacherPayoutService::class)->balanceSummary($teacher->id);

        // Hanya booking tanpa sengketa yang dihitung.
        $this->assertSame(80000.0, $summary['available']);

        Sanctum::actingAs($teacher);
        $salary = $this->getJson('/api/teacher/salary')->assertOk()->json('balances');
        $operations = $this->getJson('/api/teacher/dashboard-v2')->assertOk()->json('earnings');

        $this->assertSame(80000.0, (float) $salary['available']);
        $this->assertSame(
            80000.0,
            (float) $operations['available'],
            'dashboard operasional tidak boleh menghitung booking bersengketa'
        );
    }

    public function test_held_never_exceeds_available(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->booking($teacher, ['payout_status' => 'locked', 'teacher_net_amount' => 50000]);

        $summary = app(TeacherPayoutService::class)->balanceSummary($teacher->id);

        $this->assertLessThanOrEqual($summary['available'], $summary['held']);
        $this->assertSame(50000.0, $summary['available']);
        $this->assertSame(50000.0, $summary['held']);
    }

    public function test_overpaid_balance_is_visible_and_never_withdrawable(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        // Sudah dibayar melebihi yang seharusnya diterima.
        $this->booking($teacher, ['teacher_net_amount' => 40000, 'teacher_paid_amount' => 60000]);

        $summary = app(TeacherPayoutService::class)->balanceSummary($teacher->id);

        $this->assertSame(-20000.0, $summary['signed_available']);
        $this->assertSame(0.0, $summary['available']);
        $this->assertSame(20000.0, $summary['overpaid']);
        $this->assertSame(0.0, $summary['held']);

        $this->expectException(\RuntimeException::class);
        app(TeacherPayoutService::class)->request($teacher, 10000);
    }
}
