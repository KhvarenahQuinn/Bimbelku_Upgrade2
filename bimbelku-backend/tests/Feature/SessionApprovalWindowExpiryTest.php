<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class SessionApprovalWindowExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_lapsed_approval_window_locks_the_payout_and_notifies_everyone(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        $booking->forceFill([
            'status' => 'awaiting_student_approval',
            'objection_deadline' => now()->subMinutes(5),
            'payout_status' => 'ready',
        ])->save();

        $this->artisan('bookings:expire')->assertSuccessful();

        $booking->refresh();
        $this->assertSame('admin_review_required', $booking->status);
        // Sesi yang belum disetujui tidak boleh menyisakan hak tutor yang siap cair.
        $this->assertSame('locked', $booking->payout_status);
        $this->assertNotNull($booking->admin_review_required_at);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $fixture['teacher']->id,
            'title' => 'Sesi menunggu pemeriksaan admin',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $fixture['student']->id,
            'title' => 'Sesi menunggu pemeriksaan admin',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $fixture['admin']->id,
            'title' => 'Sesi telat disetujui',
        ]);
    }
}
