<?php

namespace Tests\Feature;

use App\Events\AutoWalletRefundProcessed;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\CustomerWallet;
use App\Models\CustomerWalletTransaction;
use App\Models\LearningPackage;
use App\Models\LearningTimeSlot;
use App\Models\MatchingOperationLog;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\Refund;
use App\Models\TeacherAvailability;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\TeacherMatchingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageThreeMatchingSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test 1: BimbelKu Saldo Refund (Internal Wallet) MUST be INSTANT and AUTOMATIC,
     * immediately transitioning to 'refunded' and bypassing 'refund_pending'.
     */
    public function test_instant_automatic_refund_to_bimbelku_saldo(): void
    {
        Event::fake([AutoWalletRefundProcessed::class]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $initialBalance = 50000.0;
        $wallet = CustomerWallet::forceCreate([
            'user_id' => $student->id,
            'current_balance' => $initialBalance,
            'reserved_balance' => 0,
        ]);
        CustomerWalletTransaction::unguarded(fn () => CustomerWalletTransaction::create([
            'customer_wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'event_key' => 'init-saldo-'.$student->id,
            'type' => 'topup',
            'direction' => 'credit',
            'amount' => $initialBalance,
            'balance_before' => 0,
            'balance_after' => $initialBalance,
            'description' => 'Saldo awal murid',
        ]));

        $refundAmount = 150000.0;
        [$package, $subject, $request, $order] = $this->makePaidPackage($student, $refundAmount);

        Sanctum::actingAs($student);

        // Cancel with explicit wallet destination
        $response = $this->postJson("/api/student/packages/{$package->id}/cancel", [
            'destination' => 'wallet',
        ]);

        $response->assertOk()
            ->assertJsonPath('package_status', 'refunded')
            ->assertJsonPath('refund_status', 'paid')
            ->assertJsonPath('needs_destination', false);

        // Verify package, order, and refund statuses transitioned immediately to refunded/paid
        $package->refresh();
        $order->refresh();
        $refund = Refund::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame('refunded', $package->status);
        $this->assertSame('refunded', $order->status);
        $this->assertSame('paid', $refund->status);
        $this->assertSame('bimbelku_balance', $refund->destination_method);

        // Verify wallet balance increased instantly without admin intervention
        $expectedBalance = $initialBalance + $refundAmount;
        $this->assertEquals($expectedBalance, (float) $wallet->fresh()->current_balance);

        // Verify wallet transaction record
        $this->assertDatabaseHas('customer_wallet_transactions', [
            'user_id' => $student->id,
            'type' => 'refund_credit',
            'direction' => 'credit',
            'amount' => $refundAmount,
            'balance_after' => $expectedBalance,
        ]);

        // Verify audit log event auto_wallet_refund_processed was dispatched
        Event::assertDispatched(AutoWalletRefundProcessed::class);

        // Verify matching operation log
        $this->assertDatabaseHas('matching_operation_logs', [
            'booking_request_id' => $request->id,
            'action' => 'auto_wallet_refund_processed',
        ]);
    }

    /**
     * Test 2: Original Payment / External Gateway Refund retains manual Admin verification
     * workflow ('refund_pending' -> Admin Approval -> 'refunded').
     */
    public function test_external_gateway_refund_requires_admin_approval(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'admin_permissions' => ['finance_manage', 'financial_approvals_manage'],
        ]);

        $initialBalance = 30000.0;
        $wallet = CustomerWallet::forceCreate([
            'user_id' => $student->id,
            'current_balance' => $initialBalance,
            'reserved_balance' => 0,
        ]);
        CustomerWalletTransaction::unguarded(fn () => CustomerWalletTransaction::create([
            'customer_wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'event_key' => 'init-ext-'.$student->id,
            'type' => 'topup',
            'direction' => 'credit',
            'amount' => $initialBalance,
            'balance_before' => 0,
            'balance_after' => $initialBalance,
            'description' => 'Saldo awal murid',
        ]));

        $refundAmount = 150000.0;
        [$package, , , $order] = $this->makePaidPackage($student, $refundAmount);

        Sanctum::actingAs($student);

        // Cancel with bank / external payout destination
        $cancelResponse = $this->postJson("/api/student/packages/{$package->id}/cancel", [
            'destination' => 'bank',
            'bank_name' => 'BCA',
            'account_name' => 'Budi Murid',
            'account_number' => '0123456789',
        ]);

        $cancelResponse->assertOk()
            ->assertJsonPath('package_status', 'refund_pending')
            ->assertJsonPath('refund_status', 'pending');

        $package->refresh();
        $order->refresh();
        $refund = Refund::query()->where('order_id', $order->id)->firstOrFail();

        // Must stay in pending / refund_pending awaiting manual admin verification
        $this->assertSame('refund_pending', $package->status);
        $this->assertSame('refund_pending', $order->status);
        $this->assertSame('pending', $refund->status);
        $this->assertSame('bank_transfer', $refund->destination_method);

        // Student wallet balance must NOT be credited yet
        $this->assertEquals($initialBalance, (float) $wallet->fresh()->current_balance);

        // Admin verifies and completes the refund with transfer proof
        Sanctum::actingAs($admin);

        $completeResponse = $this->post("/api/admin/refunds/{$refund->id}/complete", [
            'proof' => UploadedFile::fake()->image('refund_transfer_proof.jpg'),
            'destination_selection_version' => $refund->destination_selection_version ?: 1,
            'notes' => 'Transfer manual pengembalian dana telah diverifikasi oleh tim finance.',
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'admin-approve-ext-'.$refund->id,
        ]);

        $completeResponse->assertOk();

        // After admin approval, states must transition to refunded / paid
        $this->assertSame('refunded', $package->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('paid', $refund->fresh()->status);
    }

    /**
     * Test 3: Automatic 60-minute timeout ('matching' -> 'no_teacher') and Admin UI
     * search duration display strictly capped at 60 minutes.
     */
    public function test_strict_60_minute_expiry_transition_and_admin_audit_log(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00:00', 'Asia/Jakarta'));

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->makeEligibleTeacher('Kimia');

        [$package, $subject, $request] = $this->makePackageSearchWithTime(
            $student,
            'Kimia',
            '2026-11-09 14:00:00'
        );
        $request->update([
            'search_started_at' => now()->subMinutes(15),
            'search_expires_at' => now()->addMinutes(45),
        ]);

        $matchingService = app(TeacherMatchingService::class);
        $offer = $matchingService->dispatchNextOffer($request->fresh());

        $this->assertNotNull($offer);
        $this->assertSame('teacher_pending', $request->fresh()->status);
        $this->assertSame('teacher_pending', $package->fresh()->status);

        // Fast-forward 46 minutes (now is 09:46, searchStartedAt was 08:45, total 61 mins past)
        Carbon::setTestNow(now()->addMinutes(46));

        $matchingService->expireOfferAndContinue($offer->fresh());

        // Verify status transition to no_teacher
        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertSame('no_teacher', $request->fresh()->status);
        $this->assertSame('no_teacher', $subject->fresh()->status);
        $this->assertSame('no_teacher', $package->fresh()->status);

        // Verify offer_expired and matching_exhausted audit logs
        $this->assertDatabaseHas('matching_operation_logs', [
            'booking_request_id' => $request->id,
            'action' => 'offer_expired',
        ]);
        $this->assertDatabaseHas('matching_operation_logs', [
            'booking_request_id' => $request->id,
            'action' => 'matching_exhausted',
        ]);

        // Verify Admin search monitoring strictly caps search duration at 60 minutes (3600 seconds)
        Sanctum::actingAs($admin);

        $adminResponse = $this->getJson("/api/admin/tutor-searches/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.search_window_minutes', 60)
            ->assertJsonPath('data.search_age_seconds', 3600)
            ->assertJsonPath('data.search_age_minutes', 60);

        $this->assertTrue($adminResponse->json('data.search_age_seconds') <= 3600);
    }

    /**
     * Test 4: Manual cancellation during matching immediately opens refund and frees schedule.
     */
    public function test_manual_cancellation_during_matching_opens_refund_immediately(): void
    {
        LearningTimeSlot::create(['start_time' => '14:00:00', 'is_active' => true]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $initialBalance = 20000.0;
        $wallet = CustomerWallet::forceCreate([
            'user_id' => $student->id,
            'current_balance' => $initialBalance,
            'reserved_balance' => 0,
        ]);
        CustomerWalletTransaction::unguarded(fn () => CustomerWalletTransaction::create([
            'customer_wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'event_key' => 'init-manual-'.$student->id,
            'type' => 'topup',
            'direction' => 'credit',
            'amount' => $initialBalance,
            'balance_before' => 0,
            'balance_after' => $initialBalance,
            'description' => 'Saldo awal murid',
        ]));

        $packageAmount = 100000.0;
        [$package, , $session, $order] = $this->makePaidPackageWithSession(
            $student,
            $packageAmount,
            '2026-11-15 14:00:00'
        );

        Sanctum::actingAs($student);

        // Cancel during matching without specifying destination yet
        $response = $this->postJson("/api/student/packages/{$package->id}/cancel")
            ->assertOk()
            ->assertJsonPath('needs_destination', true);

        $refundId = (int) $response->json('refund_id');
        $this->assertGreaterThan(0, $refundId);

        // Verify refund row was created immediately
        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'order_id' => $order->id,
            'amount' => $packageAmount,
            'status' => 'pending',
        ]);

        // Schedule slot is released immediately
        $this->assertSame('refund_pending', $session->fresh()->status);

        // Student immediately selects Saldo BimbelKu as destination
        $destResponse = $this->postJson("/api/student/refunds/{$refundId}/destination", [
            'destination_method' => 'bimbelku_balance',
        ], [
            'Idempotency-Key' => 'refund-dest-wallet-'.$refundId,
        ]);

        $destResponse->assertOk();

        // Verify immediate settlement to wallet
        $this->assertSame('paid', Refund::query()->findOrFail($refundId)->status);
        $this->assertSame('refunded', $package->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertEquals($initialBalance + $packageAmount, (float) $wallet->fresh()->current_balance);
    }

    /**
     * Test 5: Preventing race conditions when 2 tutors accept an offer simultaneously.
     * Ensure 1 Tutor locks the booking and the 2nd receives a 409 or 422 response.
     */
    public function test_concurrent_tutor_acceptance_lock(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher1 = $this->makeEligibleTeacher('Sosiologi');
        $teacher2 = $this->makeEligibleTeacher('Sosiologi');

        [$package, $subject, $request, $order] = $this->makePaidPackage($student, 100000.0, 'Sosiologi');
        $request->update(['status' => 'teacher_pending']);
        $subject->update(['status' => 'teacher_pending']);
        $package->update(['status' => 'teacher_pending']);

        // Generate concurrent offers for the same request
        $offer1 = TeacherOffer::create([
            'booking_request_id' => $request->id,
            'teacher_id' => $teacher1->id,
            'status' => 'pending',
            'offered_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        $offer2 = TeacherOffer::create([
            'booking_request_id' => $request->id,
            'teacher_id' => $teacher2->id,
            'status' => 'pending',
            'offered_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        // Tutor 1 accepts first
        Sanctum::actingAs($teacher1);
        $tutor1Response = $this->postJson("/api/teacher/offers/{$offer1->id}/accept");
        $tutor1Response->assertOk();

        // Verify Tutor 1 locked the subject & booking
        $this->assertSame('accepted', $offer1->fresh()->status);
        $this->assertSame($teacher1->id, (int) $subject->fresh()->assigned_teacher_id);
        $this->assertSame($teacher1->id, (int) $request->fresh()->matched_teacher_id);

        // Verify other pending offers are cancelled
        $this->assertSame('cancelled', $offer2->fresh()->status);

        // Tutor 2 attempts to accept concurrently
        Sanctum::actingAs($teacher2);
        $tutor2Response = $this->postJson("/api/teacher/offers/{$offer2->id}/accept");

        // Tutor 2 must receive 409 or 422
        $this->assertTrue(in_array($tutor2Response->status(), [409, 422], true));

        // Verify Tutor 1 remains the sole assigned teacher
        $this->assertSame($teacher1->id, (int) $subject->fresh()->assigned_teacher_id);
    }

    private function makeEligibleTeacher(string $subjectName = 'Matematika'): User
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_accepting_requests' => true,
            'verified_at' => now()->subDay(),
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'name' => $subjectName,
            'levels' => ['SMA'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);
        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => 'Senin',
            'start_time' => '08:00:00',
            'end_time' => '20:00:00',
            'is_active' => true,
        ]);

        return $teacher;
    }

    private function makePackageSearch(User $student, string $subjectName = 'Matematika'): array
    {
        $plan = PackagePlan::query()->firstOrCreate(
            ['slug' => 'test-sim-plan'],
            [
                'name' => 'Paket Uji',
                'session_count' => 1,
                'validity_days' => 30,
                'maximum_subjects' => 1,
                'is_active' => true,
            ]
        );

        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-TEST-'.uniqid(),
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'status' => 'matching',
            'total_sessions' => 1,
            'used_sessions' => 0,
            'subtotal_amount' => 100000,
            'discount_amount' => 0,
            'total_amount' => 100000,
        ]);

        $subject = PackageSubject::create([
            'learning_package_id' => $package->id,
            'subject_name' => $subjectName,
            'allocated_sessions' => 1,
            'unit_price' => 100000,
            'subtotal_amount' => 100000,
            'status' => 'matching',
        ]);

        $start = Carbon::parse('2026-11-09 10:00:00', 'Asia/Jakarta');
        PackageSession::create([
            'package_subject_id' => $subject->id,
            'sequence' => 1,
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addHour(),
            'status' => 'planned',
        ]);

        $request = BookingRequest::create([
            'student_id' => $student->id,
            'package_subject_id' => $subject->id,
            'subject_name' => $subjectName,
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $start->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'duration_hours' => 1,
            'status' => 'matching',
            'search_started_at' => now(),
            'search_expires_at' => now()->addHour(),
        ]);

        return [$package, $subject, $request];
    }

    private function makePackageSearchWithTime(User $student, string $subjectName, string $sessionAt): array
    {
        $plan = PackagePlan::query()->firstOrCreate(
            ['slug' => 'test-sim-plan'],
            [
                'name' => 'Paket Uji',
                'session_count' => 1,
                'validity_days' => 30,
                'maximum_subjects' => 1,
                'is_active' => true,
            ]
        );

        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-TEST-'.uniqid(),
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'status' => 'matching',
            'total_sessions' => 1,
            'used_sessions' => 0,
            'subtotal_amount' => 100000,
            'discount_amount' => 0,
            'total_amount' => 100000,
        ]);

        $subject = PackageSubject::create([
            'learning_package_id' => $package->id,
            'subject_name' => $subjectName,
            'allocated_sessions' => 1,
            'unit_price' => 100000,
            'subtotal_amount' => 100000,
            'status' => 'matching',
        ]);

        $start = Carbon::parse($sessionAt, 'Asia/Jakarta');
        PackageSession::create([
            'package_subject_id' => $subject->id,
            'sequence' => 1,
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addHour(),
            'status' => 'planned',
        ]);

        $request = BookingRequest::create([
            'student_id' => $student->id,
            'package_subject_id' => $subject->id,
            'subject_name' => $subjectName,
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addHour()->format('H:i:s'),
            'duration_hours' => 1,
            'status' => 'matching',
            'search_started_at' => now(),
            'search_expires_at' => now()->addHour(),
        ]);

        return [$package, $subject, $request];
    }

    private function makePaidPackage(User $student, float $amount = 100000.0, string $subjectName = 'Matematika'): array
    {
        [$package, $subject, $request] = $this->makePackageSearch($student, $subjectName);

        $order = Order::create([
            'user_id' => $student->id,
            'learning_package_id' => $package->id,
            'order_id' => 'INV-TEST-'.uniqid(),
            'subtotal_amount' => $amount,
            'discount_amount' => 0,
            'amount' => $amount,
            'status' => 'paid',
        ]);

        return [$package, $subject, $request, $order];
    }

    private function makePaidPackageWithSession(User $student, float $amount, string $sessionAt): array
    {
        $plan = PackagePlan::query()->firstOrCreate(
            ['slug' => 'test-session-plan'],
            [
                'name' => 'Paket Sesi Uji',
                'session_count' => 1,
                'validity_days' => 30,
                'maximum_subjects' => 1,
                'is_active' => true,
            ]
        );

        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-SESS-'.uniqid(),
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'status' => 'matching',
            'total_sessions' => 1,
            'used_sessions' => 0,
            'subtotal_amount' => $amount,
            'discount_amount' => 0,
            'total_amount' => $amount,
        ]);

        $subject = PackageSubject::create([
            'learning_package_id' => $package->id,
            'subject_name' => 'Matematika',
            'allocated_sessions' => 1,
            'unit_price' => $amount,
            'subtotal_amount' => $amount,
            'status' => 'matching',
        ]);

        $start = Carbon::parse($sessionAt, 'Asia/Jakarta');
        $session = PackageSession::create([
            'package_subject_id' => $subject->id,
            'sequence' => 1,
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addHour(),
            'status' => 'planned',
        ]);

        $order = Order::create([
            'user_id' => $student->id,
            'learning_package_id' => $package->id,
            'order_id' => 'INV-SESS-'.uniqid(),
            'subtotal_amount' => $amount,
            'discount_amount' => 0,
            'amount' => $amount,
            'status' => 'paid',
        ]);

        return [$package, $subject, $session, $order];
    }
}
