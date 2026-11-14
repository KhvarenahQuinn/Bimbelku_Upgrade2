<?php

namespace Tests\Support;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\LearningPackage;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\TeacherProfile;
use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Session-level finance fixtures. Every session is paid through one order so a
 * partial refund has a real wallet/external split, and the per-session value
 * comes from `booking_participant.amount` exactly like production.
 */
final class SessionFinanceFixture
{
    public static function make(TestCase $test, int $sessions = 4, float $walletApplied = 0): array
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'Asia/Jakarta'));

        $student = User::factory()->create(['role' => 'student', 'status' => 'active', 'name' => 'Murid Sesi']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
            'name' => 'Tutor Sesi',
            'address' => 'Jl. Merdeka No. 1, Yogyakarta',
        ]);
        TeacherProfile::create([
            'user_id' => $teacher->id,
            'location' => 'Yogyakarta',
            'is_accepting_requests' => true,
            'verified_at' => now()->subDay(),
        ]);

        $plan = PackagePlan::create([
            'name' => 'Paket Sesi Finansial',
            'slug' => 'paket-sesi-finansial-'.uniqid(),
            'description' => 'Fixture sesi',
            'session_count' => $sessions,
            'validity_days' => 30,
            'maximum_subjects' => 1,
            'sort_order' => 1,
            'is_active' => false,
        ]);
        $total = 50000 * $sessions;
        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-SES-'.uniqid(),
            'education_level' => 'SMP',
            'grade' => 'Kelas 8',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'status' => 'active',
            'total_sessions' => $sessions,
            'used_sessions' => 0,
            'subtotal_amount' => $total,
            'discount_amount' => 0,
            'total_amount' => $total,
            'starts_at' => now()->subWeek(),
            'expires_at' => now()->addDays(23),
        ]);
        $subject = PackageSubject::create([
            'learning_package_id' => $package->id,
            'assigned_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'chapter' => 'Persamaan Linear',
            'curriculum_chapter_ids' => [],
            'allocated_sessions' => $sessions,
            'unit_price' => 50000,
            'subtotal_amount' => $total,
            'status' => 'active',
        ]);
        $order = Order::create([
            'user_id' => $student->id,
            'learning_package_id' => $package->id,
            'order_id' => 'ORD-SES-'.uniqid(),
            'amount' => $total,
            'subtotal_amount' => $total,
            'discount_amount' => 0,
            'status' => 'paid',
            'wallet_applied_amount' => $walletApplied,
            'external_received_amount' => round($total - $walletApplied, 2),
        ]);

        $sessionRows = [];
        for ($index = 1; $index <= $sessions; $index++) {
            $start = now()->addDays($index);
            $request = BookingRequest::create([
                'student_id' => $student->id,
                'package_subject_id' => $subject->id,
                'matched_teacher_id' => $teacher->id,
                'subject_name' => 'Matematika',
                'education_level' => 'SMP',
                'grade' => 'Kelas 8',
                'learning_mode' => 'online',
                'class_type' => 'private',
                'scheduled_date' => $start->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $start->copy()->addHour()->format('H:i:s'),
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
                'start_at' => $start,
                'end_at' => $start->copy()->addHour(),
                'duration_hours' => 1,
                'learning_mode' => 'online',
                'class_type' => 'private',
                'hourly_rate' => 50000,
                'total_amount' => 50000,
                'gross_amount' => 50000,
                'teacher_net_amount' => 40000,
                'commission_percent' => 20,
                'status' => 'confirmed',
                'session_flow_version' => 'presence_confirmation_v2',
                'payout_status' => 'locked',
            ]);
            $request->update(['booking_id' => $booking->id]);
            BookingParticipant::create([
                'booking_id' => $booking->id,
                'student_id' => $student->id,
                'booking_request_id' => $request->id,
                'order_id' => $order->id,
                'amount' => 50000,
                'status' => 'paid',
            ]);
            $session = PackageSession::create([
                'package_subject_id' => $subject->id,
                'booking_id' => $booking->id,
                'sequence' => $index,
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $start->copy()->addHour(),
                'status' => 'confirmed',
            ]);
            $sessionRows[] = ['booking' => $booking, 'session' => $session, 'request' => $request];
        }

        return [
            'student' => $student,
            'admin' => $admin,
            'teacher' => $teacher,
            'package' => $package,
            'subject' => $subject,
            'order' => $order,
            'sessions' => $sessionRows,
            'first' => $sessionRows[0],
        ];
    }
}
