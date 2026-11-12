<?php

namespace Tests\Feature;

use App\Models\Refund;
use App\Services\PartialPackageRefundService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class TutorExitRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preview_lists_only_undelivered_sessions(): void
    {
        $fixture = SessionFinanceFixture::make($this, 4);
        $this->completeFirstSession($fixture);

        $preview = app(PartialPackageRefundService::class)->previewTutorExit($fixture['package']->fresh());

        $this->assertCount(3, $preview['sessions']);
        $this->assertSame(150000.0, $preview['total_amount']);
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_tutor_exit_refunds_only_the_remaining_sessions_of_a_discounted_package(): void
    {
        $fixture = SessionFinanceFixture::make($this, 4, 24000);
        $this->completeFirstSession($fixture);
        $fixtures = app(PartialPackageRefundService::class)->previewTutorExit($fixture['package']->fresh());
        Sanctum::actingAs($fixture['admin']);

        $confirmed = $this->postJson("/api/admin/packages/{$fixture['package']->id}/tutor-exit", [
            'reason' => 'Tutor berhenti mengajar dan tidak dapat melanjutkan paket.',
            'evidence_note' => 'Tutor menyampaikan pengunduran diri tertulis dan seluruh sesi tersisa belum terlaksana sama sekali.',
            'confirm_token' => 'REFUND_SISA_PAKET',
            'booking_ids' => collect($fixtures['sessions'])->pluck('booking_id')->all(),
        ], ['Idempotency-Key' => 'tutor-exit-0001']);
        $confirmed->assertCreated();

        $refund = Refund::query()->where('source_type', 'tutor_exit')->firstOrFail();
        $this->assertSame(150000.0, (float) $refund->amount);
        $this->assertLessThanOrEqual((float) $fixture['order']->external_received_amount + 0.009, (float) $refund->external_refund_amount);
        $this->assertSame('completed', $fixture['first']['booking']->fresh()->status);
        $this->assertSame('refund_pending', $fixture['sessions'][1]['booking']->fresh()->status);
        $this->assertSame(1, Refund::query()->count());

        // A second call must reuse the same aggregate refund.
        $this->postJson("/api/admin/packages/{$fixture['package']->id}/tutor-exit", [
            'reason' => 'Tutor berhenti mengajar dan tidak dapat melanjutkan paket.',
            'evidence_note' => 'Tutor menyampaikan pengunduran diri tertulis dan seluruh sesi tersisa belum terlaksana sama sekali.',
            'confirm_token' => 'REFUND_SISA_PAKET',
            'booking_ids' => collect($fixtures['sessions'])->pluck('booking_id')->all(),
        ], ['Idempotency-Key' => 'tutor-exit-0001-again'])->assertCreated();

        $this->assertSame(1, Refund::query()->count());
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_tutor_exit_rejects_a_session_that_already_had_attendance(): void
    {
        $fixture = SessionFinanceFixture::make($this, 3);
        $fixture['sessions'][0]['booking']->update(['tutor_ready_at' => now()]);
        Sanctum::actingAs($fixture['admin']);

        $this->postJson("/api/admin/packages/{$fixture['package']->id}/tutor-exit", [
            'reason' => 'Tutor berhenti mengajar dan tidak dapat melanjutkan paket.',
            'evidence_note' => 'Tutor menyampaikan pengunduran diri tertulis dan seluruh sesi tersisa belum terlaksana sama sekali.',
            'confirm_token' => 'REFUND_SISA_PAKET',
            'booking_ids' => [$fixture['sessions'][0]['booking']->id],
        ], ['Idempotency-Key' => 'tutor-exit-attended-01'])->assertStatus(422);

        $this->assertSame(0, Refund::query()->count());
    }

    private function completeFirstSession(array $fixture): void
    {
        $booking = $fixture['first']['booking'];
        $booking->update([
            'status' => 'completed',
            'completed_at' => now(),
            'student_approved_at' => now(),
            'payout_status' => 'ready',
        ]);
        $booking->participants()->update(['status' => 'approved', 'approved_at' => now()]);
        $fixture['first']['session']->update(['status' => 'completed']);
    }
}
