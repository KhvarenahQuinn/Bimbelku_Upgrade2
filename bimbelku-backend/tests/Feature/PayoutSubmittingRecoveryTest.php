<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class PayoutSubmittingRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_test');
        config()->set('xendit.base_url', 'https://api.xendit.test');
        config()->set('xendit.payout_api_version', '2025-09-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reconcile_command_resubmits_a_payout_stuck_without_a_gateway_id(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $payout = $this->stuckPayout($fixture['teacher']);
        $this->fakeGateway();

        $this->artisan('payments:reconcile-payouts')->assertSuccessful();

        $payout->refresh();
        $this->assertSame('disb-recovered', $payout->gateway_payout_id);
        $this->assertSame('ACCEPTED', $payout->gateway_status);
        // Satu percobaan ulang dengan idempotency-key yang sama: bukan transfer baru.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->hasHeader('idempotency-key', 'BKU-PAY-900001')
            && $request['reference_id'] === 'BKU-PAY-900001');
    }

    public function test_manual_reconcile_resubmits_instead_of_failing_on_a_missing_gateway_id(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $payout = $this->stuckPayout($fixture['teacher']);
        $this->fakeGateway();

        Sanctum::actingAs($fixture['admin']);
        $this->postJson("/api/admin/finance/payouts/{$payout->id}/reconcile")
            ->assertOk()
            ->assertJsonPath('gateway_status', 'ACCEPTED');

        $this->assertSame('disb-recovered', $payout->fresh()->gateway_payout_id);
        Http::assertSentCount(1);
    }

    private function stuckPayout(User $teacher): Payout
    {
        $payout = Payout::create([
            'user_id' => $teacher->id,
            'amount' => 40000,
            'period' => 'November 2026',
            'total_classes' => 1,
            'status' => 'processing',
            'payment_provider' => 'xendit',
            'account_number' => '1234567890',
            'account_name' => $teacher->name,
            'bank_name' => 'BCA',
            'payout_channel_code' => 'BCA',
            'booking_ids' => [],
            'allocation_breakdown' => [],
        ]);
        // POST pertama timeout: referensi sudah tersimpan tetapi gateway belum
        // pernah memberi payout_id.
        $payout->forceFill([
            'gateway_status' => 'SUBMITTING',
            'gateway_reference_id' => 'BKU-PAY-900001',
            'gateway_payout_id' => null,
        ])->save();
        DB::table('payouts')->where('id', $payout->id)->update([
            'created_at' => now()->subMinutes(10),
            'gateway_last_checked_at' => null,
        ]);

        return $payout->fresh();
    }

    private function fakeGateway(): void
    {
        Http::fake([
            'https://api.xendit.test/v3/payouts' => Http::response([
                'payout_id' => 'disb-recovered',
                'status' => 'ACCEPTED',
            ], 200),
        ]);
    }
}
