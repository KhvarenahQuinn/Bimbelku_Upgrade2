<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class SessionSettlementRoundingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_settlement_keeps_two_decimal_cents_in_the_tutor_share(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        $booking->forceFill([
            'status' => 'awaiting_student_approval',
            'objection_deadline' => now()->addHours(12),
            'total_amount' => 33333,
            'gross_amount' => 33333,
            'teacher_net_amount' => 26666.4,
            'commission_percent' => 20,
        ])->save();
        $booking->participants()->update(['status' => 'awaiting_student_approval']);

        Sanctum::actingAs($fixture['student']);
        $this->postJson("/api/student/bookings/{$booking->id}/approve")->assertOk();

        $booking->refresh();
        // 20% dari 33.333 = 6.666,6 sehingga hak tutor 26.666,40 — bukan dibulatkan
        // ke rupiah penuh seperti jalur lama.
        $this->assertSame('33333.00', (string) $booking->gross_amount);
        $this->assertSame('26666.40', (string) $booking->teacher_net_amount);
        $this->assertSame('ready', $booking->payout_status);
        $this->assertSame('completed', $booking->status);
    }
}
