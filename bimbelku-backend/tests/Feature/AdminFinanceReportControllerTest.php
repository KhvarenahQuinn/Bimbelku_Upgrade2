<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\FinancialJournal;
use App\Models\Order;
use App\Models\User;
use App\Services\FinancialLedgerService;
use App\Services\PartialPackageRefundService;
use App\Services\RefundSettlementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFinanceReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_platform_revenue_net_zero_after_tutor_share_refund(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-TEST-001',
            'amount' => 50000,
            'status' => 'paid',
            'payment_provider' => 'bimbelku_wallet',
            'external_received_amount' => 50000,
            'wallet_applied_amount' => 0,
        ]);

        $request = BookingRequest::create([
            'student_id' => $student->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 8',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => today()->subDay(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'duration_hours' => 1,
            'status' => 'confirmed',
            'hourly_rate' => 50000,
            'total_amount' => 50000,
        ]);

        $booking = Booking::create([
            'booking_request_id' => $request->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'order_id' => $order->id,
            'start_at' => now()->subDay()->setTime(10, 0),
            'end_at' => now()->subDay()->setTime(11, 0),
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'hourly_rate' => 50000,
            'total_amount' => 50000,
            'gross_amount' => 50000,
            'teacher_net_amount' => 40000,
            'commission_percent' => 20,
            'teacher_paid_amount' => 0,
            'payout_status' => 'unpaid',
            'status' => 'confirmed',
        ]);

        $participant = BookingParticipant::create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'amount' => 50000,
            'status' => 'approved',
        ]);

        app(FinancialLedgerService::class)->recordBookingSettled($booking);

        $refund = app(PartialPackageRefundService::class)->queueSessionRefund(
            $booking->fresh(),
            $participant->fresh(),
            50000,
            'tutor_share',
            'booking_case',
            1,
            'Tutor tidak hadir',
            'admin',
            $admin->id,
            null,
            'Pengujian keputusan admin atas sesi yang tidak terlaksana.',
        );

        app(RefundSettlementService::class)->settle($refund->fresh(), 'bimbelku_balance', $admin->id);

        $journal = FinancialJournal::query()
            ->where('event_type', 'refund_session_adjusted')
            ->with('entries')
            ->firstOrFail();

        $this->assertSame(3, $journal->entries->count());

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/admin/finance/report')
            ->assertOk();

        $summary = $response->json('summary');

        $this->assertArrayHasKey('platform_revenue', $summary);
        $this->assertSame(0, $summary['platform_revenue'], 'Net platform_revenue should be 0 after tutor_share refund cancels the commission');
    }

    public function test_platform_revenue_negative_after_platform_borne_refund(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $order = Order::create([
            'user_id' => $student->id,
            'order_id' => 'INV-TEST-002',
            'amount' => 50000,
            'status' => 'paid',
            'payment_provider' => 'bimbelku_wallet',
            'external_received_amount' => 50000,
            'wallet_applied_amount' => 0,
        ]);

        $request = BookingRequest::create([
            'student_id' => $student->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 8',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => today()->subDay(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'duration_hours' => 1,
            'status' => 'confirmed',
            'hourly_rate' => 50000,
            'total_amount' => 50000,
        ]);

        $booking = Booking::create([
            'booking_request_id' => $request->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'order_id' => $order->id,
            'start_at' => now()->subDay()->setTime(10, 0),
            'end_at' => now()->subDay()->setTime(11, 0),
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'hourly_rate' => 50000,
            'total_amount' => 50000,
            'gross_amount' => 50000,
            'teacher_net_amount' => 40000,
            'commission_percent' => 20,
            'teacher_paid_amount' => 0,
            'payout_status' => 'unpaid',
            'status' => 'confirmed',
        ]);

        $participant = BookingParticipant::create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'amount' => 50000,
            'status' => 'approved',
        ]);

        app(FinancialLedgerService::class)->recordBookingSettled($booking);

        $refund = app(PartialPackageRefundService::class)->queueSessionRefund(
            $booking->fresh(),
            $participant->fresh(),
            50000,
            'platform',
            'booking_case',
            2,
            'Platform error',
            'admin',
            $admin->id,
            null,
            'Platform borne refund',
        );

        app(RefundSettlementService::class)->settle($refund->fresh(), 'bimbelku_balance', $admin->id);

        $journal = FinancialJournal::query()
            ->where('event_type', 'refund_session_adjusted')
            ->with('entries')
            ->firstOrFail();

        $this->assertSame(2, $journal->entries->count());

        $platformRevenueEntry = $journal->entries
            ->where('account_code', 'platform_revenue')
            ->first();
        $this->assertNotNull($platformRevenueEntry);
        $this->assertSame('debit', $platformRevenueEntry->side);
        $this->assertSame(50000.0, (float) $platformRevenueEntry->amount);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/admin/finance/report')
            ->assertOk();

        $summary = $response->json('summary');

        $this->assertArrayHasKey('platform_revenue', $summary);
        $this->assertSame(-40000, $summary['platform_revenue'], 'Net platform_revenue should be -40000 after platform-borne refund (gross 50000 debited, commission 10000 credited at settlement)');
    }
}
