<?php

namespace Tests\Feature;

use App\Models\BookingRequest;
use App\Models\LearningPackage;
use App\Models\LearningTimeSlot;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\TeacherAvailability;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\PackageCheckoutService;
use App\Services\TeacherMatchingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PackageRescheduleMatchingRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_changing_unavailable_schedule_offers_the_matching_tutor_immediately(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        [$student, $teacher, $package, $subject, $request] = $this->fixture('2026-10-03 14:00:00');
        Sanctum::actingAs($student);

        $this->postJson("/api/student/packages/{$package->id}/reschedule", [
            'subjects' => [[
                'package_subject_id' => $subject->id,
                'schedules' => ['2026-10-05T14:00:00+07:00'],
            ]],
        ])->assertOk();

        $request->refresh();
        $this->assertSame('teacher_pending', $request->status);
        $this->assertNull($request->next_matching_at);
        $this->assertSame('teacher_pending', $subject->fresh()->status);
        $this->assertSame('teacher_pending', $package->fresh()->status);
        $this->assertSame('2026-10-05', $request->scheduled_date->format('Y-m-d'));
        $this->assertSame('Latihan membaca dan memahami teks.', $request->learning_goal);
        $this->assertSame('Latihan membaca dan memahami teks.', $subject->fresh()->learning_goal);
        $this->assertDatabaseHas('teacher_offers', [
            'booking_request_id' => $request->id,
            'teacher_id' => $teacher->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('matching_operation_logs', [
            'booking_request_id' => $request->id,
            'action' => 'schedule_changed',
        ]);
    }

    public function test_manual_retry_ignores_the_old_waiting_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        [$student, $teacher, $package, $subject, $request] = $this->fixture('2026-10-05 14:00:00');
        Sanctum::actingAs($student);

        $this->postJson("/api/student/packages/{$package->id}/retry")
            ->assertOk();

        $this->assertSame('teacher_pending', $request->fresh()->status);
        $this->assertNull($request->fresh()->next_matching_at);
        $this->assertSame('teacher_pending', $subject->fresh()->status);
        $this->assertSame('teacher_pending', $package->fresh()->status);
        $this->assertSame($teacher->id, TeacherOffer::query()->firstOrFail()->teacher_id);

        $this->getJson("/api/student/packages/{$package->id}")
            ->assertOk()
            ->assertJsonPath('subjects.0.status', 'teacher_pending')
            ->assertJsonPath('subjects.0.matching.active_offer_count', 1)
            ->assertJsonPath('subjects.0.matching.search_state', 'offers_pending');
        $this->assertNotNull(
            $this->getJson("/api/student/packages/{$package->id}")->json('subjects.0.matching.teacher_response_deadline')
        );
    }

    public function test_package_retry_broadcasts_to_an_eligible_tutor_pool(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        [$student, $teacher, $package, $subject, $request] = $this->fixture('2026-10-05 14:00:00');
        $this->addEligibleTeacher();
        $this->addEligibleTeacher();
        Sanctum::actingAs($student);

        $this->postJson("/api/student/packages/{$package->id}/retry")->assertOk();

        $this->assertSame(3, TeacherOffer::query()
            ->where('booking_request_id', $request->id)
            ->where('status', 'pending')
            ->count());
        $this->assertSame('teacher_pending', $subject->fresh()->status);
        $this->assertSame('teacher_pending', $package->fresh()->status);

        $matching = $this->getJson("/api/student/packages/{$package->id}")
            ->assertOk()
            ->json('subjects.0.matching');
        $this->assertSame(3, $matching['active_offer_count']);
        $this->assertSame('offers_pending', $matching['search_state']);
        $this->assertNotNull($matching['teacher_response_deadline']);
    }

    public function test_package_activation_broadcasts_and_notifies_only_eligible_tutors(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        [$student, $eligibleTeacher, $package, $subject, $request] = $this->fixture('2026-10-05 14:00:00');
        $secondEligible = $this->addEligibleTeacher();
        $thirdEligible = $this->addEligibleTeacher();
        $wrongLevel = $this->addEligibleTeacher('Bahasa Indonesia', ['SD']);
        $wrongSubject = $this->addEligibleTeacher('Matematika', ['SMA']);

        $order = Order::query()->where('learning_package_id', $package->id)->firstOrFail();
        $order->update(['status' => 'submitted']);

        $status = app(PackageCheckoutService::class)->activatePaidPackage($order->fresh(), null);

        $this->assertSame('teacher_pending', $status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('teacher_pending', $package->fresh()->status);

        $offers = TeacherOffer::query()
            ->where('booking_request_id', $request->id)
            ->where('status', 'pending')
            ->get();
        $this->assertCount(3, $offers);
        $this->assertEqualsCanonicalizing(
            [$eligibleTeacher->id, $secondEligible->id, $thirdEligible->id],
            $offers->pluck('teacher_id')->all()
        );

        foreach ([$eligibleTeacher, $secondEligible, $thirdEligible] as $teacher) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $teacher->id,
                'title' => 'Permintaan bimbel baru',
            ]);
        }
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $wrongLevel->id,
            'title' => 'Permintaan bimbel baru',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $wrongSubject->id,
            'title' => 'Permintaan bimbel baru',
        ]);

        Sanctum::actingAs($student);
        $matching = $this->getJson("/api/student/packages/{$package->id}")
            ->assertOk()
            ->json('subjects.0.matching');
        $this->assertSame(3, $matching['active_offer_count']);
        $this->assertSame('offers_pending', $matching['search_state']);
        $this->assertTrue($matching['can_change_schedule']);
        $this->assertNotNull($matching['teacher_response_deadline']);
    }

    public function test_matching_package_can_change_schedule_and_old_offers_are_expired(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        [$student, , $package, $subject, $request] = $this->fixture('2026-10-05 14:00:00');
        $this->addEligibleTeacher();
        $this->addEligibleTeacher();

        $request->update([
            'status' => 'matching',
            'matched_teacher_id' => null,
            'next_matching_at' => null,
            'search_started_at' => now(),
            'search_expires_at' => now()->addHours(24),
        ]);
        $subject->update(['status' => 'matching']);
        $package->update(['status' => 'matching']);

        app(TeacherMatchingService::class)->dispatchNextOffer($request->fresh());
        $oldOfferIds = TeacherOffer::query()
            ->where('booking_request_id', $request->id)
            ->where('status', 'pending')
            ->pluck('id')
            ->all();
        $this->assertCount(3, $oldOfferIds);
        $oldDeadline = TeacherOffer::query()->whereIn('id', $oldOfferIds)->max('expires_at');

        Sanctum::actingAs($student);
        $matching = $this->getJson("/api/student/packages/{$package->id}")
            ->assertOk()
            ->json('subjects.0.matching');
        $this->assertTrue($matching['can_change_schedule']);
        $this->assertSame('offers_pending', $matching['search_state']);

        Carbon::setTestNow(now()->addMinutes(5));
        $this->postJson("/api/student/packages/{$package->id}/reschedule", [
            'subjects' => [[
                'package_subject_id' => $subject->id,
                'schedules' => ['2026-10-12T14:00:00+07:00'],
            ]],
        ])->assertOk();

        // Re-offering reuses the existing teacher/request row instead of
        // inserting a duplicate, so the refreshed wave stays pending.
        $this->assertSame(
            count($oldOfferIds),
            TeacherOffer::query()->whereIn('id', $oldOfferIds)->where('status', 'pending')->count()
        );
        $this->assertSame(0, TeacherOffer::query()->whereIn('id', $oldOfferIds)->where('status', 'expired')->count());
        $this->assertGreaterThan($oldDeadline, TeacherOffer::query()->whereIn('id', $oldOfferIds)->max('expires_at'));
        $this->assertSame('2026-10-12', $request->fresh()->scheduled_date->format('Y-m-d'));
        $this->assertSame('teacher_pending', $request->fresh()->status);
        $this->assertSame('teacher_pending', $subject->fresh()->status);
        $this->assertSame('teacher_pending', $package->fresh()->status);
        $this->assertGreaterThan(0, TeacherOffer::query()
            ->where('booking_request_id', $request->id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_accepting_an_offer_locks_the_request_and_cancels_other_pending_offers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));
        [$student, , $package, $subject, $request] = $this->fixture('2026-10-05 14:00:00');
        $this->addEligibleTeacher();
        $this->addEligibleTeacher();
        Sanctum::actingAs($student);

        $this->postJson("/api/student/packages/{$package->id}/retry")->assertOk();
        $offers = TeacherOffer::query()
            ->where('booking_request_id', $request->id)
            ->where('status', 'pending')
            ->get();
        $this->assertCount(3, $offers);

        $accepted = $offers->first();
        $losingOffers = $offers->slice(1)->values();
        Sanctum::actingAs(User::query()->findOrFail($accepted->teacher_id));

        $this->postJson("/api/teacher/offers/{$accepted->id}/accept")->assertOk();

        $this->assertSame('accepted', $accepted->fresh()->status);
        $this->assertSame($accepted->teacher_id, $request->fresh()->matched_teacher_id);
        $this->assertSame($accepted->teacher_id, $subject->fresh()->assigned_teacher_id);
        $this->assertSame('confirmed', $request->fresh()->status);
        $this->assertSame('active', $package->fresh()->status);

        foreach ($losingOffers as $offer) {
            $this->assertSame('cancelled', $offer->fresh()->status);
            $this->assertDatabaseHas('notifications', [
                'user_id' => $offer->teacher_id,
                'title' => 'Permintaan sudah diambil tutor lain',
            ]);
        }

        // The request is locked: a losing tutor can no longer accept.
        Sanctum::actingAs(User::query()->findOrFail($losingOffers->first()->teacher_id));
        $this->postJson("/api/teacher/offers/{$losingOffers->first()->id}/accept")
            ->assertUnprocessable();
    }

    private function addEligibleTeacher(
        string $subject = 'Bahasa Indonesia',
        array $levels = ['SMA']
    ): User {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_accepting_requests' => true,
            'verified_at' => now()->subDay(),
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'name' => $subject,
            'levels' => $levels,
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);
        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => 'Senin',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);

        return $teacher;
    }

    private function fixture(string $sessionAt): array
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_accepting_requests' => true,
            'verified_at' => now()->subDay(),
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'name' => 'Bahasa Indonesia',
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
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);
        LearningTimeSlot::create(['start_time' => '14:00:00', 'is_active' => true]);

        $plan = PackagePlan::create([
            'name' => 'Paket Uji Pencarian Ulang',
            'slug' => 'uji-pencarian-ulang',
            'session_count' => 1,
            'validity_days' => 30,
            'maximum_subjects' => 1,
            'is_active' => true,
        ]);
        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-RETRY-'.uniqid(),
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'status' => 'no_teacher',
            'total_sessions' => 1,
            'used_sessions' => 0,
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'total_amount' => 50000,
        ]);
        $subject = PackageSubject::create([
            'learning_package_id' => $package->id,
            'subject_name' => 'Bahasa Indonesia',
            'allocated_sessions' => 1,
            'learning_goal' => 'Latihan membaca dan memahami teks.',
            'unit_price' => 50000,
            'subtotal_amount' => 50000,
            'status' => 'no_teacher',
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
            'subject_name' => 'Bahasa Indonesia',
            'education_level' => 'SMA',
            'learning_goal' => 'Latihan membaca dan memahami teks.',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addHour()->format('H:i:s'),
            'duration_hours' => 1,
            'status' => 'no_teacher',
            'search_started_at' => now(),
            'search_expires_at' => now()->addHours(24),
            'next_matching_at' => now()->addMinutes(30),
        ]);
        Order::create([
            'user_id' => $student->id,
            'learning_package_id' => $package->id,
            'order_id' => 'INV-RETRY-'.uniqid(),
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'amount' => 50000,
            'status' => 'paid',
        ]);

        return [$student, $teacher, $package, $subject, $request];
    }
}
