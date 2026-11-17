<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Order;
use App\Models\Refund;
use App\Models\TeacherAvailability;
use App\Models\TeacherAvailabilityException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFinanceAndTeacherScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_teacher_weekly_schedule_exceptions_and_upcoming_bookings(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => 'Senin',
            'slots' => [['start_time' => '08:00', 'end_time' => '16:00']],
            'is_active' => true,
        ]);
        TeacherAvailabilityException::create([
            'user_id' => $teacher->id,
            'start_date' => today()->addDays(3),
            'end_date' => today()->addDays(3),
            'reason' => 'Keperluan keluarga',
        ]);
        $request = BookingRequest::create([
            'student_id' => $student->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 8',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => today()->addDay(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'duration_hours' => 1,
            'status' => 'confirmed',
            'search_radius_km' => null,
            'hourly_rate' => 100000,
            'total_amount' => 100000,
        ]);
        Booking::create([
            'booking_request_id' => $request->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'start_at' => now()->addDay()->setTime(10, 0),
            'end_at' => now()->addDay()->setTime(11, 0),
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'hourly_rate' => 100000,
            'total_amount' => 100000,
            'gross_amount' => 100000,
            'teacher_net_amount' => 80000,
            'commission_percent' => 20,
            'status' => 'confirmed',
        ]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/teachers/{$teacher->id}/schedule")
            ->assertOk()
            ->assertJsonPath('weekly.0.day', 'Senin')
            ->assertJsonPath('weekly.0.ranges.0.start_time', '08:00')
            ->assertJsonPath('exceptions.0.reason', 'Keperluan keluarga')
            ->assertJsonPath('upcoming_bookings.0.subject_name', 'Matematika')
            ->assertJsonPath('upcoming_bookings.0.learning_mode', 'online');
    }

    public function test_finance_report_is_paginated_and_exports_a_real_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-REPORT-001',
            'amount' => 175000,
            'status' => 'submitted',
            'payment_proof' => 'payment_proofs/example.jpg',
        ]);
        $order->update(['status' => 'paid']);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/finance/report?per_page=25')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('data.0.event_type', 'payment_received')
            ->assertJsonPath('summary.payments_received', 175000);

        $pdf = $this->get('/api/admin/finance/report.pdf');
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_online_request_keeps_radius_and_location_empty(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $request = BookingRequest::create([
            'student_id' => $student->id,
            'subject_name' => 'Bahasa Inggris',
            'education_level' => 'SMA',
            'grade' => 'Kelas 11',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => today()->addDay(),
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
            'duration_hours' => 1,
            'status' => 'matching',
            'search_radius_km' => null,
        ]);

        $this->assertNull($request->fresh()->search_radius_km);
        $this->assertNull($request->fresh()->address);
        $this->assertNull($request->fresh()->latitude);
    }

    public function test_refund_attention_only_counts_cases_requiring_admin_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-REFUND-ATTENTION-001',
            'amount' => 100000,
            'status' => 'refund_pending',
        ]);
        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $student->id,
            'amount' => 100000,
            'reason' => 'Tutor tidak ditemukan',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/dashboard-stats')->assertOk()->assertJsonPath('counts.refunds', 0);

        $refund->forceFill(['destination_method' => 'bank_transfer', 'destination_selected_at' => now()])->save();
        $this->getJson('/api/admin/dashboard-stats')->assertOk()->assertJsonPath('counts.refunds', 1);

        $refund->forceFill(['destination_method' => 'xendit_original', 'gateway_status' => 'PENDING'])->save();
        $this->getJson('/api/admin/dashboard-stats')->assertOk()->assertJsonPath('counts.refunds', 0);

        $refund->forceFill(['gateway_status' => 'FAILED'])->save();
        $this->getJson('/api/admin/dashboard-stats')->assertOk()->assertJsonPath('counts.refunds', 1);
    }

    public function test_refunds_needing_action_appear_first_without_reordering_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-REFUND-ORDER-001',
            'amount' => 100000,
            'status' => 'refund_pending',
        ]);
        // `status` dan `gateway_status` dijaga pada model Refund, jadi fixture
        // menyimpannya lewat forceFill() seperti service.
        $createRefund = function (array $attributes) use ($order, $student) {
            $guarded = array_intersect_key($attributes, array_flip(['status', 'gateway_status', 'gateway_refund_id', 'processed_at', 'proof']));
            $refund = Refund::create(array_merge([
                'order_id' => $order->id,
                'user_id' => $student->id,
                'amount' => 100000,
                'reason' => 'Uji urutan refund',
            ], array_diff_key($attributes, $guarded)));
            if ($guarded !== []) {
                $refund->forceFill($guarded)->save();
            }

            return $refund;
        };

        $waiting = $createRefund(['created_at' => now(), 'updated_at' => now()]);
        $transfer = $createRefund([
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
            'destination_method' => 'bank_transfer',
            'destination_selected_at' => now()->subHour(),
        ]);
        $failed = $createRefund([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
            'destination_method' => 'xendit_original',
            'destination_selected_at' => now()->subHours(2),
            'gateway_status' => 'FAILED',
        ]);
        $historyOlder = $createRefund(['status' => 'paid', 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
        $historyNewer = $createRefund(['status' => 'paid', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/finance/refunds')->assertOk()
            ->assertJsonPath('pending.0.id', $transfer->id)
            ->assertJsonPath('pending.0.needs_action', true)
            ->assertJsonPath('pending.0.action_label', 'Perlu transfer')
            ->assertJsonPath('pending.1.id', $failed->id)
            ->assertJsonPath('pending.1.action_label', 'Pengiriman gagal')
            ->assertJsonPath('pending.2.id', $waiting->id)
            ->assertJsonPath('pending.2.needs_action', false)
            ->assertJsonPath('history.0.id', $historyNewer->id)
            ->assertJsonPath('history.1.id', $historyOlder->id);
    }
}
