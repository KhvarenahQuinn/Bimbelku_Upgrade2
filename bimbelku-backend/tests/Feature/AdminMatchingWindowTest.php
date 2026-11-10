<?php

namespace Tests\Feature;

use App\Models\BookingRequest;
use App\Models\LearningPackage;
use App\Models\MatchingOperationLog;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\TeacherAvailability;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\TeacherMatchingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMatchingWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_detail_uses_the_same_strict_sixty_minute_window_as_the_engine(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->makeEligibleTeacher();
        [$package, $subject, $request] = $this->makePackageSearch($student);
        $request->update([
            'search_started_at' => now()->subMinutes(30),
            // Legacy value that used to leak a 24-hour span into the admin view.
            'search_expires_at' => now()->addHours(24),
        ]);

        app(TeacherMatchingService::class)->dispatchNextOffer($request->fresh());

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/tutor-searches/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.search_window_minutes', 60)
            ->assertJsonPath('data.search_age_seconds', 1800)
            ->assertJsonPath('data.search_expires_at', now()->subMinutes(30)->addHour()->toIso8601String())
            ->assertJsonPath('data.automatic_candidate_count', 1)
            ->assertJsonPath('data.available_candidate_count', 0)
            ->assertJsonPath('data.offer_history.0.id', TeacherOffer::query()->firstOrFail()->id)
            ->assertJsonPath('data.offer_history.0.status', 'pending');

        $this->assertSame('teacher_pending', $request->fresh()->status);
        $this->assertSame('teacher_pending', $subject->fresh()->status);
        $this->assertSame('teacher_pending', $package->fresh()->status);
    }

    public function test_admin_duration_is_capped_at_sixty_minutes_for_legacy_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        [, , $request] = $this->makePackageSearch($student);
        $request->update([
            'search_started_at' => now()->subHours(2),
            'search_expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/tutor-searches/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.search_age_seconds', 3600)
            ->assertJsonPath('data.search_age_minutes', 60)
            ->assertJsonPath('data.search_expires_at', now()->subHour()->toIso8601String())
            ->assertJsonPath('data.available_candidate_count', 0)
            ->assertJsonPath('data.automatic_candidate_count', 0);
    }

    public function test_live_sixty_minute_window_is_not_flagged_before_it_expires(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = $this->makeEligibleTeacher();
        [$package, $subject, $request] = $this->makePackageSearch($student);

        // 56 minutes into the window: the previous wave has lapsed, but the
        // engine can still dispatch another wave for the remaining 4 minutes.
        $request->update([
            'status' => 'teacher_pending',
            'matched_teacher_id' => null,
            'search_started_at' => now()->subMinutes(56),
            'search_expires_at' => now()->addMinutes(4),
            'teacher_response_deadline' => now()->subMinute(),
        ]);
        $subject->update(['status' => 'teacher_pending']);
        $package->update(['status' => 'teacher_pending']);
        TeacherOffer::create([
            'booking_request_id' => $request->id,
            'teacher_id' => $teacher->id,
            'status' => 'pending',
            'offered_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/tutor-searches/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.search_age_seconds', 3360)
            ->assertJsonPath('data.needs_attention', false)
            ->assertJsonPath('data.attention_reason', null);

        $this->getJson('/api/admin/tutor-searches?status=attention')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('summary.attention', 0);

        // Once the strict window is truly over the same row becomes actionable.
        Carbon::setTestNow(now()->addMinutes(5));

        $this->getJson("/api/admin/tutor-searches/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.needs_attention', true)
            ->assertJsonPath('data.attention_reason', 'Batas jawaban tutor telah terlewati dan perlu disinkronkan.');

        $this->getJson('/api/admin/tutor-searches?status=attention')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.attention', 1);
    }

    public function test_status_transitions_write_audit_logs_and_package_status_together(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->makeEligibleTeacher();
        [$package, $subject, $request] = $this->makePackageSearch($student);
        $request->update([
            'search_started_at' => now()->subMinutes(15),
            'search_expires_at' => now()->addDay(),
        ]);

        $matchingService = app(TeacherMatchingService::class);
        $offer = $matchingService->dispatchNextOffer($request->fresh());

        $this->assertNotNull($offer);
        $dispatched = MatchingOperationLog::query()
            ->where('booking_request_id', $request->id)
            ->where('action', 'offers_dispatched')
            ->firstOrFail();
        $this->assertSame('matching', $dispatched->before_state['status']);
        $this->assertSame('teacher_pending', $dispatched->after_state['status']);
        $this->assertSame('teacher_pending', $dispatched->after_state['package_status']);
        $this->assertSame(1, $dispatched->metadata['offer_count']);

        Carbon::setTestNow(now()->addMinutes(46));
        $matchingService->expireOfferAndContinue($offer);

        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertSame('no_teacher', $request->fresh()->status);
        $this->assertSame('no_teacher', $subject->fresh()->status);
        $this->assertSame('no_teacher', $package->fresh()->status);

        $expired = MatchingOperationLog::query()
            ->where('booking_request_id', $request->id)
            ->where('action', 'offer_expired')
            ->firstOrFail();
        $this->assertSame('teacher_pending', $expired->before_state['status']);
        $this->assertSame('matching', $expired->after_state['status']);

        $this->assertDatabaseHas('matching_operation_logs', [
            'booking_request_id' => $request->id,
            'action' => 'matching_exhausted',
        ]);
    }

    private function makeEligibleTeacher(): User
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'is_accepting_requests' => true,
            'verified_at' => now()->subDay(),
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'name' => 'Matematika',
            'levels' => ['SMA'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);
        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => 'Rabu',
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
            'is_active' => true,
        ]);

        return $teacher;
    }

    private function makePackageSearch(User $student): array
    {
        $plan = PackagePlan::create([
            'name' => 'Paket Uji Jendela Pencarian',
            'slug' => 'uji-jendela-pencarian',
            'session_count' => 1,
            'validity_days' => 30,
            'maximum_subjects' => 1,
            'is_active' => false,
        ]);
        $package = LearningPackage::create([
            'student_id' => $student->id,
            'package_plan_id' => $plan->id,
            'package_code' => 'PKG-WINDOW-'.uniqid(),
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'status' => 'matching',
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
            'unit_price' => 50000,
            'subtotal_amount' => 50000,
            'status' => 'matching',
        ]);
        $start = Carbon::parse('2026-09-30 14:00:00', 'Asia/Jakarta');
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
            'subject_name' => 'Matematika',
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => '2026-09-30',
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
            'duration_hours' => 1,
            'status' => 'matching',
            'search_started_at' => now(),
            'search_expires_at' => now()->addHour(),
        ]);

        return [$package, $subject, $request];
    }
}
