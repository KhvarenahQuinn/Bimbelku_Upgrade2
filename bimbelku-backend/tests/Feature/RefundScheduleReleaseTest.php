<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\LearningPackage;
use App\Models\LearningTimeSlot;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\TeacherOffer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RefundScheduleReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cancelling_no_teacher_package_releases_booking_and_schedule_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        LearningTimeSlot::create(['start_time' => '14:00:00', 'is_active' => true]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);

        [$refundedPackage, $refundedSubject, $refundedSession, $refundedOrder, $refundedRequest] = $this->packageWithSchedule(
            $student,
            'no_teacher',
            '2026-10-05 14:00:00'
        );
        $booking = $this->bookingFor($student, $teacher, $refundedOrder, $refundedRequest, '2026-10-05 14:00:00');
        $refundedSession->update(['booking_id' => $booking->id]);

        Sanctum::actingAs($student);
        $cancelResponse = $this->postJson("/api/student/packages/{$refundedPackage->id}/cancel")
            ->assertOk()
            ->assertJsonPath('needs_destination', true);
        $refundId = (int) $cancelResponse->json('refund_id');
        $this->assertGreaterThan(0, $refundId);

        $this->assertSame('refund_pending', $refundedPackage->fresh()->status);
        $this->assertSame('refund_pending', $refundedSession->fresh()->status);
        $this->assertNull($refundedSession->fresh()->booking_id);
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'order_id' => $refundedOrder->id,
            'status' => 'pending',
        ]);

        // Choosing Saldo BimbelKu settles synchronously without admin approval.
        $this->postJson("/api/student/refunds/{$refundId}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => 'refund-slot-balance-'.$refundId])->assertOk();

        $this->assertDatabaseHas('refunds', ['id' => $refundId, 'status' => 'paid']);
        $this->assertSame('refunded', $refundedPackage->fresh()->status);

        // The same hour must be bookable again while the refund is still pending.
        [$newPackage, $newSubject] = $this->packageWithSchedule($student, 'no_teacher', '2026-10-06 14:00:00');

        $this->postJson("/api/student/packages/{$newPackage->id}/reschedule", [
            'subjects' => [[
                'package_subject_id' => $newSubject->id,
                'schedules' => ['2026-10-05T14:00:00+07:00'],
            ]],
        ])->assertOk();

        $this->assertSame('2026-10-05 14:00:00', $newSubject->fresh()->sessions()->firstOrFail()->scheduled_start_at->format('Y-m-d H:i:s'));
    }

    public function test_reschedule_rejects_overlap_with_another_active_package(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        LearningTimeSlot::create(['start_time' => '14:00:00', 'is_active' => true]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->packageWithSchedule($student, 'active', '2026-10-10 14:00:00');
        [$package, $subject] = $this->packageWithSchedule($student, 'no_teacher', '2026-10-12 14:00:00');

        Sanctum::actingAs($student);
        $this->postJson("/api/student/packages/{$package->id}/reschedule", [
            'subjects' => [[
                'package_subject_id' => $subject->id,
                'schedules' => ['2026-10-10T14:00:00+07:00'],
            ]],
        ])->assertUnprocessable();
    }

    public function test_last_offer_expiry_marks_package_no_teacher_and_offers_refund_choice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        LearningTimeSlot::create(['start_time' => '14:00:00', 'is_active' => true]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);

        [$package, $subject, , $order, $request] = $this->packageWithSchedule(
            $student,
            'teacher_pending',
            '2026-10-05 14:00:00'
        );
        $request->update(['status' => 'teacher_pending', 'next_matching_at' => null]);
        TeacherOffer::create([
            'booking_request_id' => $request->id,
            'teacher_id' => $teacher->id,
            'status' => 'pending',
            'offered_at' => now(),
            'expires_at' => now()->addMinute(),
        ]);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('bookings:expire')->assertSuccessful();

        $this->assertSame('no_teacher', $request->fresh()->status);
        $this->assertSame('no_teacher', $subject->fresh()->status);
        $this->assertSame('no_teacher', $package->fresh()->status);

        Sanctum::actingAs($student);
        $cancelResponse = $this->postJson("/api/student/packages/{$package->id}/cancel")
            ->assertOk()
            ->assertJsonPath('needs_destination', true);
        $refundId = (int) $cancelResponse->json('refund_id');
        $this->assertGreaterThan(0, $refundId);

        $this->postJson("/api/student/refunds/{$refundId}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], ['Idempotency-Key' => 'refund-last-offer-'.$refundId])->assertOk();

        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'order_id' => $order->id,
            'status' => 'paid',
            'destination_method' => 'bimbelku_balance',
        ]);
        $this->assertSame('refunded', $package->fresh()->status);
    }

    public function test_partial_refund_pending_order_keeps_other_package_sessions_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        LearningTimeSlot::create(['start_time' => '14:00:00', 'is_active' => true]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        [$package, $subject, $refundedSession, $order] = $this->packageWithSchedule(
            $student,
            'active',
            '2026-10-10 14:00:00'
        );
        // One session is refunded; the package order becomes refund_pending.
        // The other session is still scheduled and must keep blocking its hour.
        $order->update(['status' => 'refund_pending']);
        $refundedSession->update(['status' => 'refund_pending']);
        $otherStart = Carbon::parse('2026-10-11 14:00:00', 'Asia/Jakarta');
        PackageSession::create([
            'package_subject_id' => $subject->id,
            'sequence' => 2,
            'scheduled_start_at' => $otherStart,
            'scheduled_end_at' => $otherStart->copy()->addHour(),
            'status' => 'planned',
        ]);

        [$newPackage, $newSubject] = $this->packageWithSchedule($student, 'no_teacher', '2026-10-12 14:00:00');
        Sanctum::actingAs($student);

        $this->postJson("/api/student/packages/{$newPackage->id}/reschedule", [
            'subjects' => [[
                'package_subject_id' => $newSubject->id,
                'schedules' => ['2026-10-11T14:00:00+07:00'],
            ]],
        ])->assertUnprocessable();

        // The refunded session's own hour is free.
        $this->postJson("/api/student/packages/{$newPackage->id}/reschedule", [
            'subjects' => [[
                'package_subject_id' => $newSubject->id,
                'schedules' => ['2026-10-10T14:00:00+07:00'],
            ]],
        ])->assertOk();
    }

    public function test_legacy_refunded_package_and_order_do_not_block_the_same_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        LearningTimeSlot::create(['start_time' => '14:00:00', 'is_active' => true]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);

        [$legacyPackage, , $legacySession, $legacyOrder, $legacyRequest] = $this->packageWithSchedule(
            $student,
            'refunded',
            '2026-10-07 14:00:00'
        );
        $legacyOrder->update(['status' => 'refunded']);
        $legacySession->update(['status' => 'refunded']);
        // Simulate legacy stale data: an active-looking booking still points to
        // the refunded order. The order/package status must win.
        $this->bookingFor($student, $teacher, $legacyOrder, $legacyRequest, '2026-10-07 14:00:00');

        [$newPackage, $newSubject] = $this->packageWithSchedule($student, 'no_teacher', '2026-10-08 14:00:00');

        Sanctum::actingAs($student);
        $this->postJson("/api/student/packages/{$newPackage->id}/reschedule", [
            'subjects' => [[
                'package_subject_id' => $newSubject->id,
                'schedules' => ['2026-10-07T14:00:00+07:00'],
            ]],
        ])->assertOk();

        $this->assertSame('2026-10-07 14:00:00', $newSubject->fresh()->sessions()->firstOrFail()->scheduled_start_at->format('Y-m-d H:i:s'));
        $this->assertSame('refunded', $legacyPackage->fresh()->status);
    }

    /**
     * @return array{0: LearningPackage, 1: PackageSubject, 2: PackageSession, 3: Order, 4: BookingRequest}
     */
    private function packageWithSchedule(User $student, string $status, string $sessionAt): array
    {
        $plan = PackagePlan::query()->firstOrCreate(
            ['slug' => 'refund-slot-test'],
            [
                'name' => 'Paket Uji Slot Refund',
                'session_count' => 1,
                'validity_days' => 30,
                'maximum_subjects' => 1,
                'is_active' => true,
            ]
        );
        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-SLOT-'.uniqid(),
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'status' => $status,
            'total_sessions' => 1,
            'used_sessions' => 0,
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'total_amount' => 50000,
        ]);
        $subject = PackageSubject::create([
            'learning_package_id' => $package->id,
            'subject_name' => 'Matematika',
            'allocated_sessions' => 1,
            'learning_goal' => 'Menguji pembebasan slot jadwal.',
            'unit_price' => 50000,
            'subtotal_amount' => 50000,
            'status' => $status,
        ]);
        $start = Carbon::parse($sessionAt, 'Asia/Jakarta');
        $session = PackageSession::create([
            'package_subject_id' => $subject->id,
            'sequence' => 1,
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addHour(),
            'status' => 'planned',
        ]);
        $request = BookingRequest::create([
            'student_id' => $student->id,
            'package_subject_id' => $subject->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMA',
            'learning_goal' => 'Menguji pembebasan slot jadwal.',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addHour()->format('H:i:s'),
            'duration_hours' => 1,
            'status' => $status === 'no_teacher' ? 'no_teacher' : 'expired',
            'search_started_at' => now(),
            'search_expires_at' => now()->addHours(24),
            'next_matching_at' => now()->addMinutes(30),
        ]);
        $order = Order::create([
            'user_id' => $student->id,
            'learning_package_id' => $package->id,
            'order_id' => 'INV-SLOT-'.uniqid(),
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'amount' => 50000,
            'status' => 'paid',
        ]);

        return [$package, $subject, $session, $order, $request];
    }

    private function bookingFor(
        User $student,
        User $teacher,
        Order $order,
        BookingRequest $request,
        string $startAt
    ): Booking {
        $start = Carbon::parse($startAt, 'Asia/Jakarta');

        $booking = Booking::create([
            'booking_request_id' => $request->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'order_id' => $order->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'hourly_rate' => 50000,
            'total_amount' => 50000,
            'status' => 'confirmed',
        ]);
        BookingParticipant::create([
            'booking_id' => $booking->id,
            'booking_request_id' => $request->id,
            'order_id' => $order->id,
            'student_id' => $student->id,
            'amount' => 50000,
            'status' => 'paid',
        ]);

        return $booking;
    }
}
