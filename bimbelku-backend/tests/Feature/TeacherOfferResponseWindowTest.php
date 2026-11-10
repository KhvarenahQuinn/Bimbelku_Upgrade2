<?php

namespace Tests\Feature;

use App\Models\BookingRequest;
use App\Models\Classroom;
use App\Models\Notification;
use App\Models\Rating;
use App\Models\Setting;
use App\Models\TeacherAvailability;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\TeacherMatchingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherOfferResponseWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->updateOrCreate(
            ['key' => 'teacher_response_online_minutes'],
            ['value' => '60']
        );
        Setting::query()->updateOrCreate(
            ['key' => 'teacher_response_offline_minutes'],
            ['value' => '60']
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_online_offer_uses_sixty_minute_response_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->makeEligibleTeacher(online: true, offline: false);
        $bookingRequest = $this->makeRequest($student, 'online');

        app(TeacherMatchingService::class)->dispatchNextOffer($bookingRequest);

        $offer = TeacherOffer::query()->firstOrFail();
        $this->assertTrue($offer->expires_at->equalTo(now()->addMinutes(60)));
        $this->assertTrue(
            $bookingRequest->fresh()->teacher_response_deadline->equalTo($offer->expires_at)
        );
    }

    public function test_expired_offer_is_immediately_forwarded_to_the_next_ranked_teacher(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'Asia/Jakarta'));
        // The strict search window is one hour, so shorten this offer window
        // to leave room for the engine to forward a no-response offer.
        Setting::query()
            ->where('key', 'teacher_response_online_minutes')
            ->update(['value' => '30']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        foreach (range(1, 4) as $_) {
            $this->makeEligibleTeacher(online: true, offline: false);
        }
        $bookingRequest = $this->makeRequest($student, 'online');
        $matchingService = app(TeacherMatchingService::class);

        $matchingService->dispatchNextOffer($bookingRequest);
        $this->assertCount(1, TeacherOffer::query()->where('status', 'pending')->get());

        Carbon::setTestNow(now()->addMinutes(31));
        $matchingService->dispatchNextOffer($bookingRequest);

        $offers = TeacherOffer::query()->orderBy('id')->get();
        $this->assertCount(2, $offers);
        $this->assertCount(1, $offers->where('status', 'expired'));
        $this->assertCount(1, $offers->where('status', 'pending'));
        $this->assertTrue(
            $offers->last()->expires_at->equalTo($bookingRequest->fresh()->search_started_at->addHour())
        );
    }

    public function test_rejected_offer_is_immediately_replaced_by_next_ranked_teacher(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        foreach (range(1, 4) as $_) {
            $this->makeEligibleTeacher(online: true, offline: false);
        }
        $bookingRequest = $this->makeRequest($student, 'online');
        $matchingService = app(TeacherMatchingService::class);

        $matchingService->dispatchNextOffer($bookingRequest);
        $rejectedOffer = TeacherOffer::query()->orderBy('id')->firstOrFail();
        $rejectedOffer->update(['status' => 'rejected', 'responded_at' => now()]);

        $matchingService->dispatchNextOffer($bookingRequest->fresh());

        $this->assertSame(2, TeacherOffer::query()->count());
        $this->assertSame(1, TeacherOffer::query()->where('status', 'pending')->count());
        $this->assertSame(1, TeacherOffer::query()->where('status', 'rejected')->count());
    }

    public function test_teacher_waiting_longer_is_prioritized_before_a_higher_rating(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $lowerRated = $this->makeEligibleTeacher(online: true, offline: false);
        $higherRated = $this->makeEligibleTeacher(online: true, offline: false);

        foreach ([[$lowerRated, 2], [$higherRated, 5]] as [$teacher, $stars]) {
            $reviewer = User::factory()->create(['role' => 'student', 'status' => 'active']);
            $classroom = Classroom::create([
                'user_id' => $teacher->id,
                'title' => 'Kelas rating '.$teacher->id,
                'subject' => 'Matematika',
                'type' => 'private',
            ]);
            Rating::create([
                'classroom_id' => $classroom->id,
                'student_id' => $reviewer->id,
                'teacher_id' => $teacher->id,
                'rating' => $stars,
            ]);
        }

        $previousRequest = $this->makeRequest($student, 'online');
        TeacherOffer::create([
            'booking_request_id' => $previousRequest->id,
            'teacher_id' => $higherRated->id,
            'status' => 'rejected',
            'offered_at' => now()->subDay(),
            'expires_at' => now()->subDay()->addHour(),
            'responded_at' => now()->subDay()->addMinutes(10),
        ]);

        $offer = app(TeacherMatchingService::class)->dispatchNextOffer(
            $this->makeRequest($student, 'online')
        );

        $this->assertNotNull($offer);
        $this->assertSame($lowerRated->id, $offer->teacher_id);
        $this->assertSame(1, TeacherOffer::query()->where('status', 'pending')->count());
    }

    public function test_first_no_response_does_not_suspend_teacher(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = $this->makeEligibleTeacher(online: true, offline: false);
        $bookingRequest = $this->makeRequest($student, 'online');
        $matchingService = app(TeacherMatchingService::class);

        $offer = $matchingService->dispatchNextOffer($bookingRequest);
        Carbon::setTestNow(now()->addMinutes(61));
        $matchingService->expireOfferAndContinue($offer);

        $profile = $teacher->teacherProfile()->firstOrFail();
        $this->assertSame(1, $profile->no_response_streak);
        $this->assertNull($profile->suspended_until);
    }

    public function test_scheduler_sends_only_one_mid_window_reminder_and_records_heartbeat(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->makeEligibleTeacher(online: true, offline: false);
        $bookingRequest = $this->makeRequest($student, 'online');
        app(TeacherMatchingService::class)->dispatchNextOffer($bookingRequest);

        Carbon::setTestNow(now()->addMinutes(31));
        $this->artisan('bookings:expire')->assertSuccessful();
        $this->artisan('bookings:expire')->assertSuccessful();

        $offer = TeacherOffer::query()->firstOrFail();
        $this->assertNotNull($offer->reminder_sent_at);
        $this->assertSame(1, Notification::query()
            ->where('user_id', $offer->teacher_id)
            ->where('title', 'Pengingat permintaan bimbel')
            ->count());
        $this->assertNotNull(Setting::query()
            ->where('key', 'booking_workflow_last_heartbeat_at')
            ->value('value'));
    }

    public function test_exhausted_waiting_request_clears_next_retry_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $bookingRequest = $this->makeRequest($student, 'online');
        $bookingRequest->update([
            'status' => 'no_teacher',
            'search_started_at' => now()->subHours(25),
            'next_matching_at' => now()->subMinute(),
        ]);
        $matchingService = app(TeacherMatchingService::class);

        $matchingService->dispatchNextOffer($bookingRequest);
        $matchingService->dispatchNextOffer($bookingRequest->fresh());

        $this->assertNull($bookingRequest->fresh()->next_matching_at);
        $this->assertSame(1, $bookingRequest->matchingOperationLogs()
            ->where('action', 'matching_exhausted')
            ->count());
    }

    public function test_offline_search_expands_radius_one_step_before_scheduled_retry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $bookingRequest = $this->makeRequest($student, 'offline');

        app(TeacherMatchingService::class)->dispatchNextOffer($bookingRequest);

        $bookingRequest->refresh();
        $this->assertSame('no_teacher', $bookingRequest->status);
        $this->assertSame(5, $bookingRequest->search_radius_km);
        $this->assertTrue($bookingRequest->next_matching_at->isFuture());
        $this->assertDatabaseHas('matching_operation_logs', [
            'booking_request_id' => $bookingRequest->id,
            'action' => 'waiting_for_availability',
        ]);
    }

    public function test_offline_offer_uses_sixty_minute_response_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->makeEligibleTeacher(online: false, offline: true);
        $bookingRequest = $this->makeRequest($student, 'offline');

        app(TeacherMatchingService::class)->dispatchNextOffer($bookingRequest);

        $offer = TeacherOffer::query()->firstOrFail();
        $this->assertTrue($offer->expires_at->equalTo(now()->addMinutes(60)));
        $this->assertTrue(
            $bookingRequest->fresh()->teacher_response_deadline->equalTo($offer->expires_at)
        );
    }

    private function makeEligibleTeacher(bool $online, bool $offline): User
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'latitude' => -7.795580,
            'longitude' => 110.369490,
            'max_travel_km' => 12,
            'is_accepting_requests' => true,
            'verified_at' => now()->subDay(),
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'name' => 'Matematika',
            'levels' => ['SMA'],
            'is_active' => true,
            'is_online' => $online,
            'is_offline' => $offline,
            'is_private_active' => true,
        ]);
        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => 'Senin',
            'slots' => [],
            'start_time' => '14:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
        ]);

        return $teacher;
    }

    private function makeRequest(User $student, string $mode): BookingRequest
    {
        return BookingRequest::create([
            'student_id' => $student->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMA',
            'grade' => 'Kelas 12',
            'learning_mode' => $mode,
            'class_type' => 'private',
            'scheduled_date' => '2026-08-17',
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'duration_hours' => 1,
            'address' => $mode === 'offline' ? 'Alamat uji di Yogyakarta' : null,
            'latitude' => $mode === 'offline' ? -7.795580 : null,
            'longitude' => $mode === 'offline' ? 110.369490 : null,
            'status' => 'matching',
            'search_radius_km' => $mode === 'offline' ? 3 : null,
            'search_started_at' => now(),
            'search_expires_at' => now()->addHours(48),
        ]);
    }
}
