<?php

namespace App\Console\Commands;

use App\Models\BookingRequest;
use App\Models\CustomerWallet;
use App\Models\LearningPackage;
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
use App\Services\CustomerWalletService;
use App\Services\PackageCheckoutService;
use App\Services\RefundService;
use App\Services\RefundSettlementService;
use App\Services\TeacherMatchingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SimulateMatchingScenario extends Command
{
    protected $signature = 'matching:simulate-scenario
        {--scenario= : Scenario to run: expire-60min, auto-refund-wallet, manual-refund-bank, race-condition, or all}';

    protected $description = 'Simulate matching engine edge cases, refunds, and tutor acceptance scenarios for Stage 3';

    public function handle(
        TeacherMatchingService $matchingService,
        RefundService $refundService,
        RefundSettlementService $settlementService,
        PackageCheckoutService $checkoutService,
        CustomerWalletService $walletService
    ): int {
        $scenario = $this->option('scenario') ?: 'all';

        $this->info("=== Stage 3 Matching & Refund Simulation (Scenario: {$scenario}) ===");

        $results = [];

        switch ($scenario) {
            case 'expire-60min':
                $results['expire-60min'] = $this->simulateExpire60Min($matchingService);
                break;
            case 'auto-refund-wallet':
                $results['auto-refund-wallet'] = $this->simulateAutoRefundWallet($refundService, $walletService);
                break;
            case 'manual-refund-bank':
                $results['manual-refund-bank'] = $this->simulateManualRefundBank($refundService, $settlementService);
                break;
            case 'race-condition':
                $results['race-condition'] = $this->simulateRaceCondition($checkoutService);
                break;
            case 'all':
                $results['expire-60min'] = $this->simulateExpire60Min($matchingService);
                $this->newLine();
                $results['auto-refund-wallet'] = $this->simulateAutoRefundWallet($refundService, $walletService);
                $this->newLine();
                $results['manual-refund-bank'] = $this->simulateManualRefundBank($refundService, $settlementService);
                $this->newLine();
                $results['race-condition'] = $this->simulateRaceCondition($checkoutService);
                break;
            default:
                $this->error("Unknown scenario: {$scenario}");
                $this->info("Available scenarios: expire-60min, auto-refund-wallet, manual-refund-bank, race-condition, all");
                return self::FAILURE;
        }

        $allPassed = !in_array(false, $results, true);
        if ($allPassed) {
            $this->info("=== All simulated scenarios completed successfully! ===");
            return self::SUCCESS;
        }

        $this->error("=== Some simulated scenarios failed! ===");
        return self::FAILURE;
    }

    /**
     * Scenario 1: Adjust package timestamps to 61 minutes ago, run expiry checker,
     * verify transition to no_teacher, and check that offer_expired is logged.
     */
    private function simulateExpire60Min(TeacherMatchingService $matchingService): bool
    {
        $this->line("<comment>[1/4] Scenario: expire-60min</comment>");
        $this->line("Creating test package, booking request, and teacher offer...");

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = $this->createTeacher('Fisika');

        [$package, $subject, $request] = $this->createPackageSearch($student, 'Fisika');

        // Dispatch initial offer
        $offer = $matchingService->dispatchNextOffer($request->fresh());
        if (! $offer) {
            $offer = TeacherOffer::create([
                'booking_request_id' => $request->id,
                'teacher_id' => $teacher->id,
                'status' => 'pending',
                'offered_at' => now(),
                'expires_at' => now()->addMinutes(30),
            ]);
            $request->update(['status' => 'teacher_pending', 'matched_teacher_id' => $teacher->id]);
            $subject->update(['status' => 'teacher_pending']);
            $package->update(['status' => 'teacher_pending']);
        }

        $this->line("Adjusting timestamps to 61 minutes ago (exceeding strict 60-minute window)...");
        $sixtyOneMinsAgo = now()->subMinutes(61);
        $oneMinAgo = now()->subMinute();

        $request->update([
            'status' => 'teacher_pending',
            'search_started_at' => $sixtyOneMinsAgo,
            'search_expires_at' => $oneMinAgo,
        ]);
        $subject->update(['status' => 'teacher_pending']);
        $package->update(['status' => 'teacher_pending']);

        $offer->update([
            'status' => 'pending',
            'offered_at' => $sixtyOneMinsAgo,
            'expires_at' => $oneMinAgo,
        ]);

        $this->line("Running expiry checker (expireOfferAndContinue & dispatchNextOffer)...");
        $matchingService->expireOfferAndContinue($offer->fresh());

        // Check the updated states
        $request->refresh();
        $subject->refresh();
        $package->refresh();
        $offer->refresh();

        $this->line("Verification Results:");
        $this->line(" - Offer status: <info>{$offer->status}</info> (expected: expired)");
        $this->line(" - Request status: <info>{$request->status}</info> (expected: no_teacher)");
        $this->line(" - Subject status: <info>{$subject->status}</info> (expected: no_teacher)");
        $this->line(" - Package status: <info>{$package->status}</info> (expected: no_teacher)");

        $offerExpiredLogged = MatchingOperationLog::query()
            ->where('booking_request_id', $request->id)
            ->where('action', 'offer_expired')
            ->exists();

        $matchingExhaustedLogged = MatchingOperationLog::query()
            ->where('booking_request_id', $request->id)
            ->where('action', 'matching_exhausted')
            ->exists();

        $this->line(" - Audit log 'offer_expired': ".($offerExpiredLogged ? "<info>YES</info>" : "<error>NO</error>"));
        $this->line(" - Audit log 'matching_exhausted': ".($matchingExhaustedLogged ? "<info>YES</info>" : "<error>NO</error>"));

        $searchAgeSeconds = $matchingService->searchAgeSeconds($request);
        $cappedAt60Min = $searchAgeSeconds <= 3600;
        $this->line(" - Search duration capped at 60 mins: {$searchAgeSeconds}s ".($cappedAt60Min ? "<info>(Capped)</info>" : "<error>(Not capped)</error>"));

        $success = $offer->status === 'expired'
            && $request->status === 'no_teacher'
            && $package->status === 'no_teacher'
            && $offerExpiredLogged
            && $cappedAt60Min;

        if ($success) {
            $this->info("✔ Scenario expire-60min: PASSED");
        } else {
            $this->error("✘ Scenario expire-60min: FAILED");
        }

        return $success;
    }

    /**
     * Scenario 2: Execute cancellation/refund targeting BimbelKu Saldo,
     * verify instant balance addition & refunded status.
     */
    private function simulateAutoRefundWallet(
        RefundService $refundService,
        CustomerWalletService $walletService
    ): bool {
        $this->line("<comment>[2/4] Scenario: auto-refund-wallet</comment>");
        $this->line("Creating student with initial wallet balance and paid package...");

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $initialBalance = 75000.0;
        $wallet = CustomerWallet::forceCreate([
            'user_id' => $student->id,
            'current_balance' => $initialBalance,
            'reserved_balance' => 0,
        ]);
        \App\Models\CustomerWalletTransaction::unguarded(fn () => \App\Models\CustomerWalletTransaction::create([
            'customer_wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'event_key' => 'sim-wallet-init-'.$student->id,
            'type' => 'topup',
            'direction' => 'credit',
            'amount' => $initialBalance,
            'balance_before' => 0,
            'balance_after' => $initialBalance,
            'description' => 'Saldo awal simulasi',
        ]));

        [$package, , , $order] = $this->createPaidPackage($student, 120000.0);

        $this->line("Initial student balance: Rp ".number_format($initialBalance, 0, ',', '.'));
        $this->line("Package order amount: Rp ".number_format(120000, 0, ',', '.'));
        $this->line("Executing cancel & refund to wallet (destination: wallet)...");

        $eventDispatched = false;
        \Illuminate\Support\Facades\Event::listen('auto_wallet_refund_processed', function () use (&$eventDispatched) {
            $eventDispatched = true;
        });

        $result = $refundService->cancelPackage($package, 'wallet', [], $student->id);

        $package->refresh();
        $order->refresh();
        $refund = $result['refund']?->fresh();
        $newBalance = (float) $wallet->fresh()->current_balance;

        $this->line("Verification Results:");
        $this->line(" - Package status: <info>{$package->status}</info> (expected: refunded)");
        $this->line(" - Order status: <info>{$order->status}</info> (expected: refunded)");
        $this->line(" - Refund status: <info>{$refund?->status}</info> (expected: paid)");
        $this->line(" - Refund destination: <info>{$refund?->destination_method}</info> (expected: bimbelku_balance)");
        $this->line(" - New wallet balance: Rp ".number_format($newBalance, 0, ',', '.')." (expected: Rp ".number_format($initialBalance + 120000, 0, ',', '.').")");
        $this->line(" - Audit event 'auto_wallet_refund_processed': ".($eventDispatched ? "<info>DISPATCHED</info>" : "<error>NOT DISPATCHED</error>"));

        $success = $package->status === 'refunded'
            && $order->status === 'refunded'
            && $refund?->status === 'paid'
            && abs($newBalance - ($initialBalance + 120000.0)) < 0.01
            && $eventDispatched;

        if ($success) {
            $this->info("✔ Scenario auto-refund-wallet: PASSED");
        } else {
            $this->error("✘ Scenario auto-refund-wallet: FAILED");
        }

        return $success;
    }

    /**
     * Scenario 3: Execute refund targeting Original Payment,
     * verify state stays refund_pending until Admin approval.
     */
    private function simulateManualRefundBank(
        RefundService $refundService,
        RefundSettlementService $settlementService
    ): bool {
        $this->line("<comment>[3/4] Scenario: manual-refund-bank</comment>");
        $this->line("Creating student, admin, and paid package...");

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $initialBalance = 50000.0;
        $wallet = CustomerWallet::forceCreate([
            'user_id' => $student->id,
            'current_balance' => $initialBalance,
            'reserved_balance' => 0,
        ]);
        \App\Models\CustomerWalletTransaction::unguarded(fn () => \App\Models\CustomerWalletTransaction::create([
            'customer_wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'event_key' => 'sim-wallet-init-'.$student->id,
            'type' => 'topup',
            'direction' => 'credit',
            'amount' => $initialBalance,
            'balance_before' => 0,
            'balance_after' => $initialBalance,
            'description' => 'Saldo awal simulasi',
        ]));

        [$package, , , $order] = $this->createPaidPackage($student, 150000.0);

        $this->line("Executing cancel & refund targeting Original Payment (bank)...");

        $result = $refundService->cancelPackage($package, 'bank', [
            'bank_name' => 'BCA',
            'account_name' => 'Budi Santoso',
            'account_number' => '1234567890',
        ], $student->id);

        $package->refresh();
        $order->refresh();
        $refund = $result['refund']?->fresh();
        $walletBalanceBeforeAdmin = (float) $wallet->fresh()->current_balance;

        $this->line("Verification Results (Before Admin Approval):");
        $this->line(" - Package status: <info>{$package->status}</info> (expected: refund_pending)");
        $this->line(" - Order status: <info>{$order->status}</info> (expected: refund_pending)");
        $this->line(" - Refund status: <info>{$refund?->status}</info> (expected: pending)");
        $this->line(" - Student wallet balance unchanged: Rp ".number_format($walletBalanceBeforeAdmin, 0, ',', '.'));

        $preApprovalOk = $package->status === 'refund_pending'
            && $order->status === 'refund_pending'
            && $refund?->status === 'pending'
            && abs($walletBalanceBeforeAdmin - $initialBalance) < 0.01;

        $this->line("Simulating manual Admin approval & settlement in Admin Dashboard...");
        $settlementService->settle($refund, 'bank_transfer', $admin->id, 'transfer_proof.jpg', 'Approved by admin finance');

        $package->refresh();
        $order->refresh();
        $refund->refresh();

        $this->line("Verification Results (After Admin Approval):");
        $this->line(" - Package status: <info>{$package->status}</info> (expected: refunded)");
        $this->line(" - Order status: <info>{$order->status}</info> (expected: refunded)");
        $this->line(" - Refund status: <info>{$refund->status}</info> (expected: paid)");

        $postApprovalOk = $package->status === 'refunded'
            && $order->status === 'refunded'
            && $refund->status === 'paid';

        $success = $preApprovalOk && $postApprovalOk;

        if ($success) {
            $this->info("✔ Scenario manual-refund-bank: PASSED");
        } else {
            $this->error("✘ Scenario manual-refund-bank: FAILED");
        }

        return $success;
    }

    /**
     * Scenario 4: Simulate 2 Tutors accepting 1 offer simultaneously.
     * Ensure 1 Tutor locks the booking and the 2nd receives a 409/422 response.
     */
    private function simulateRaceCondition(PackageCheckoutService $checkoutService): bool
    {
        $this->line("<comment>[4/4] Scenario: race-condition</comment>");
        $this->line("Creating student, package search, and 2 eligible tutors...");

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher1 = $this->createTeacher('Biologi');
        $teacher2 = $this->createTeacher('Biologi');

        [$package, $subject, $request, $order] = $this->createPaidPackage($student, 100000.0, 'Biologi');
        $request->update(['status' => 'teacher_pending']);
        $subject->update(['status' => 'teacher_pending']);
        $package->update(['status' => 'teacher_pending']);

        // Create 2 offers for the same booking request
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

        $this->line("Tutor 1 accepting offer...");
        $tutor1Result = $checkoutService->acceptPackageOffer($offer1, $teacher1);
        $this->line(" - Tutor 1 accept: <info>SUCCESS</info>");

        $this->line("Tutor 2 attempting to accept offer concurrently...");
        $tutor2ErrorStatus = null;
        $tutor2ErrorMessage = null;

        try {
            $checkoutService->acceptPackageOffer($offer2->fresh(), $teacher2);
        } catch (HttpException $e) {
            $tutor2ErrorStatus = $e->getStatusCode();
            $tutor2ErrorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            $tutor2ErrorMessage = $e->getMessage();
        }

        $this->line("Verification Results:");
        $this->line(" - Tutor 2 HTTP response code: <info>{$tutor2ErrorStatus}</info> (expected: 409 or 422)");
        $this->line(" - Tutor 2 message: '{$tutor2ErrorMessage}'");
        $this->line(" - Tutor 1 offer status: <info>{$offer1->fresh()->status}</info> (expected: accepted)");
        $this->line(" - Tutor 2 offer status: <info>{$offer2->fresh()->status}</info> (expected: cancelled)");
        $this->line(" - Assigned teacher ID: <info>{$subject->fresh()->assigned_teacher_id}</info> (expected: {$teacher1->id})");

        $expectedStatus = in_array($tutor2ErrorStatus, [409, 422], true);
        $success = $expectedStatus
            && $offer1->fresh()->status === 'accepted'
            && $offer2->fresh()->status === 'cancelled'
            && (int) $subject->fresh()->assigned_teacher_id === (int) $teacher1->id;

        if ($success) {
            $this->info("✔ Scenario race-condition: PASSED");
        } else {
            $this->error("✘ Scenario race-condition: FAILED");
        }

        return $success;
    }

    private function createTeacher(string $subjectName = 'Matematika'): User
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

    private function createPackageSearch(User $student, string $subjectName = 'Matematika'): array
    {
        $plan = PackagePlan::query()->firstOrCreate(
            ['slug' => 'sim-plan-test'],
            [
                'name' => 'Paket Simulasi',
                'session_count' => 1,
                'validity_days' => 30,
                'maximum_subjects' => 1,
                'is_active' => true,
            ]
        );

        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-SIM-'.uniqid(),
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

    private function createPaidPackage(User $student, float $amount = 100000.0, string $subjectName = 'Matematika'): array
    {
        [$package, $subject, $request] = $this->createPackageSearch($student, $subjectName);

        $order = Order::create([
            'user_id' => $student->id,
            'learning_package_id' => $package->id,
            'order_id' => 'INV-SIM-'.uniqid(),
            'subtotal_amount' => $amount,
            'discount_amount' => 0,
            'amount' => $amount,
            'status' => 'paid',
        ]);

        return [$package, $subject, $request, $order];
    }
}
