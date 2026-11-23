<?php

namespace Tests\Feature;

use App\Services\TeacherMatchingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class MatchingAvailabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_session_waiting_for_admin_review_still_blocks_the_teacher_slot(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        $booking->forceFill(['status' => 'admin_review_required'])->save();

        $matching = app(TeacherMatchingService::class);
        $teacherId = (int) $fixture['teacher']->id;

        $this->assertTrue(
            $matching->teacherHasConflict($teacherId, $booking->start_at->copy()->addMinutes(30), $booking->end_at->copy()->addMinutes(30)),
            'Sesi yang menunggu keputusan admin harus tetap dihitung sebagai bentrok.'
        );

        $this->assertFalse($matching->teacherHasConflict(
            $teacherId,
            $booking->start_at->copy()->addHours(3),
            $booking->end_at->copy()->addHours(4)
        ));

        $this->assertFalse($matching->teacherHasConflict(
            $teacherId,
            $booking->start_at->copy(),
            $booking->end_at->copy(),
            $booking->id
        ));
    }
}
