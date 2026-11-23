<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Refund;
use App\Services\FinancialLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class LedgerSourceDriftTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_consistent_ledger_passes_the_cross_table_checks(): void
    {
        [$booking, $refund] = $this->fixtures();

        $this->assertNotNull($booking->fresh()->payout_status);
        $this->assertSame(20000.0, (float) $refund->fresh()->amount);
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_drift_between_a_settled_session_and_its_journal_fails_the_check(): void
    {
        [$booking] = $this->fixtures();

        DB::table('bookings')->where('id', $booking->id)->update(['teacher_net_amount' => 39000]);

        $this->artisan('finance:verify-ledger')
            ->expectsOutputToContain('teacher_net_amount')
            ->assertFailed();
    }

    public function test_drift_between_a_queued_refund_and_its_journal_fails_the_check(): void
    {
        [, $refund] = $this->fixtures();

        DB::table('refunds')->where('id', $refund->id)->update(['amount' => 11111]);

        $this->artisan('finance:verify-ledger')
            ->expectsOutputToContain('refunds_payable')
            ->assertFailed();
    }

    /** @return array{0: Booking, 1: Refund} */
    private function fixtures(): array
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        $ledger = app(FinancialLedgerService::class);
        $ledger->recordPaymentReceived($fixture['order']->fresh());
        $booking->forceFill(['payout_status' => 'ready'])->save();

        $refund = Refund::create([
            'order_id' => $fixture['order']->id,
            'user_id' => $fixture['student']->id,
            'amount' => 20000,
            'reason' => 'Kelebihan bayar pada paket uji.',
            'status' => 'pending',
        ]);

        return [$booking, $refund];
    }
}
