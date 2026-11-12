<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\FinancialJournal;
use App\Models\FinancialLedgerEntry;
use App\Models\Refund;
use App\Services\PartialPackageRefundService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class RefundAdjustmentLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tutor_share_refund_before_payout_posts_three_balanced_entries(): void
    {
        $fixture = SessionFinanceFixture::make($this, 2, 20000);
        ['booking' => $booking] = $fixture['first'];
        $this->recordSettlement($booking);

        $before = $this->balances();
        $refund = $this->queue($fixture['first'], 'booking_case', 1, 50000, 'tutor_share');
        $after = $this->balances();

        $this->assertSame(round($before['tutor_payable'] - 40000, 2), $after['tutor_payable']);
        $this->assertSame(round($before['platform_revenue'] - 10000, 2), $after['platform_revenue']);
        $this->assertSame($before['tutor_receivable'], $after['tutor_receivable']);

        $journal = FinancialJournal::query()
            ->where('event_key', "refund:{$refund->id}:adjust:{$booking->id}")
            ->with('entries')
            ->firstOrFail();
        $this->assertSame(3, $journal->entries->count());
        $this->assertSame(
            ['customer_funds', 'platform_revenue', 'tutor_payable'],
            $journal->entries->pluck('account_code')->sort()->values()->all()
        );

        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_tutor_share_refund_after_payout_books_a_receivable_instead_of_negative_payable(): void
    {
        $fixture = SessionFinanceFixture::make($this, 2);
        ['booking' => $booking] = $fixture['first'];
        $this->recordSettlement($booking);
        $booking->forceFill(['teacher_paid_amount' => 40000, 'payout_status' => 'paid'])->save();
        $booking->refresh();

        $before = $this->balances();
        $this->queue($fixture['first'], 'booking_case', 2, 50000, 'tutor_share');
        $after = $this->balances();

        $this->assertSame(round($before['tutor_receivable'] - 40000, 2), $after['tutor_receivable']);
        $this->assertSame($before['tutor_payable'], $after['tutor_payable']);
        $this->assertSame(round($before['platform_revenue'] - 10000, 2), $after['platform_revenue']);

        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_platform_bearer_only_touches_revenue_and_customer_funds(): void
    {
        $fixture = SessionFinanceFixture::make($this, 2);
        $this->recordSettlement($fixture['first']['booking']);

        $before = $this->balances();
        $this->queue($fixture['first'], 'booking_case', 3, 50000, 'platform');
        $after = $this->balances();

        $this->assertSame(round($before['platform_revenue'] - 50000, 2), $after['platform_revenue']);
        $this->assertSame($before['tutor_payable'], $after['tutor_payable']);
        $this->assertSame($before['tutor_receivable'], $after['tutor_receivable']);

        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_two_partial_refunds_on_one_order_stay_inside_the_external_cap(): void
    {
        $fixture = SessionFinanceFixture::make($this, 3, 30000);
        $first = $this->queue($fixture['sessions'][0], 'booking_case', 11, 50000, 'tutor_share');
        $second = $this->queue($fixture['sessions'][1], 'booking_case', 12, 50000, 'tutor_share');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Refund::query()->where('order_id', $fixture['order']->id)->count());
        $external = (float) Refund::query()->where('order_id', $fixture['order']->id)->sum('external_refund_amount');
        $this->assertLessThanOrEqual((float) $fixture['order']->external_received_amount + 0.009, $external);
        $this->assertSame(2, FinancialJournal::query()->where('event_type', 'refund_session_adjusted')->count());

        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_external_cap_blocks_a_third_refund_beyond_the_gateway_funds(): void
    {
        // Only one session of external money was received: the cap must reject
        // the second external refund even though two sessions exist.
        $fixture = SessionFinanceFixture::make($this, 2, 40000);
        $this->queue($fixture['sessions'][0], 'booking_case', 21, 50000, 'tutor_share');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->queue($fixture['sessions'][1], 'booking_case', 22, 50000, 'tutor_share');
    }

    public function test_booking_guard_rejects_a_refund_above_the_remaining_value(): void
    {
        $fixture = SessionFinanceFixture::make($this, 2);
        $this->queue($fixture['first'], 'booking_case', 30, 20000, 'tutor_share');
        $booking = $fixture['first']['booking']->fresh();
        $this->assertSame(30000.0, $booking->refundableRemaining());

        $this->expectException(\DomainException::class);
        $booking->assertRefundable(35000);
    }

    public function test_settlement_recompute_uses_the_reduced_tutor_share(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1, 0);
        ['booking' => $booking] = $fixture['first'];
        $participant = $booking->participants()->firstOrFail();
        $participant->update(['status' => 'approved', 'approved_at' => now()]);
        $this->recordSettlement($booking);

        $refund = $this->queue($fixture['first'], 'booking_case', 41, 50000, 'tutor_share');
        $this->settleInWallet($refund, $fixture['admin']->id);

        $booking->refresh();
        $this->assertSame('0.00', (string) $booking->gross_amount);
        $this->assertSame('0.00', (string) $booking->teacher_net_amount);
        $this->assertSame('cancelled', $booking->payout_status);

        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_settlement_journal_is_never_rewritten_by_the_adjustment(): void
    {
        $fixture = SessionFinanceFixture::make($this, 2);
        ['booking' => $booking] = $fixture['first'];
        $settled = $this->recordSettlement($booking);
        $originalHash = $settled->entry_hash;

        $this->queue($fixture['first'], 'booking_case', 51, 50000, 'tutor_share');

        $this->assertSame(
            $originalHash,
            FinancialJournal::query()->where('event_key', "booking:{$booking->id}:settled")->value('entry_hash')
        );
    }

    private function settleInWallet(Refund $refund, int $actorId): void
    {
        app(\App\Services\RefundSettlementService::class)->settle($refund->fresh(), 'bimbelku_balance', $actorId);
    }

    private function recordSettlement(Booking $booking): FinancialJournal
    {
        $booking->forceFill(['payout_status' => 'ready'])->save();

        return FinancialJournal::query()->where('event_key', "booking:{$booking->id}:settled")->firstOrFail();
    }

    private function queue(array $session, string $sourceType, int $sourceId, float $amount, string $bearer): Refund
    {
        return app(PartialPackageRefundService::class)->queueSessionRefund(
            $session['booking']->fresh(),
            $session['booking']->fresh()->participants()->firstOrFail(),
            $amount,
            $bearer,
            $sourceType,
            $sourceId,
            'Refund sesi uji',
            'admin',
            null,
            'Pengujian keputusan admin atas sesi yang tidak terlaksana.',
        );
    }

    /** @return array<string,float> */
    private function balances(): array
    {
        $rows = FinancialLedgerEntry::query()
            ->selectRaw("account_code, SUM(CASE WHEN side = 'credit' THEN amount ELSE -amount END) AS balance")
            ->groupBy('account_code')
            ->pluck('balance', 'account_code');

        return collect(['customer_funds', 'tutor_payable', 'platform_revenue', 'tutor_receivable'])
            ->mapWithKeys(fn (string $account) => [$account => round((float) ($rows[$account] ?? 0), 2)])
            ->all();
    }
}
