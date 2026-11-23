<?php

namespace Tests\Feature;

use App\Models\BookingParticipant;
use App\Models\FinancialJournal;
use App\Models\Refund;
use App\Models\User;
use App\Services\FinancialLedgerService;
use App\Services\PartialPackageRefundService;
use App\Services\RefundSettlementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

/**
 * Angka sesi setelah refund harus dihitung oleh satu fungsi yang sama untuk
 * refund manual admin, Xendit, dan saldo; dan jurnal pembalik tidak boleh
 * dibuat untuk sesi yang belum pernah diselesaikan.
 */
class RefundFigureLedgerConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manual_admin_refund_uses_the_shared_booking_figure_recompute(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];

        // Kelas kelompok: dua peserta sah, satu kursi dikembalikan.
        $second = BookingParticipant::create([
            'booking_id' => $booking->id,
            'student_id' => User::factory()->create(['role' => 'student', 'status' => 'active'])->id,
            'booking_request_id' => $booking->booking_request_id,
            'order_id' => $fixture['order']->id,
            'amount' => 50000,
            'status' => 'approved',
        ]);
        $booking->participants()->where('id', '!=', $second->id)->update(['status' => 'approved']);

        $booking->forceFill([
            'status' => 'completed',
            'gross_amount' => 100000,
            'teacher_net_amount' => 80000,
            'payout_status' => 'ready',
        ])->save();
        $this->assertDatabaseHas('financial_journals', ['event_key' => "booking:{$booking->id}:settled"]);

        $refund = Refund::create([
            'order_id' => $fixture['order']->id,
            'booking_id' => $booking->id,
            'user_id' => $fixture['student']->id,
            'amount' => 25000,
            'reason' => 'Satu kursi kelas kelompok dibatalkan dan disetujui admin.',
            'status' => 'pending',
            'cost_bearer' => 'tutor_share',
        ]);

        // Rekening tetap menunggu admin, jadi jalur manual benar-benar diuji.
        Sanctum::actingAs($fixture['student']);
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'bank_transfer',
            'bank_name' => 'BCA',
            'account_name' => $fixture['student']->name,
            'account_number' => '9988776655',
        ], ['Idempotency-Key' => 'refund-figure-destination'])->assertOk();
        $this->assertSame('pending', $refund->fresh()->status);

        Sanctum::actingAs($fixture['admin']);
        $this->post("/api/admin/refunds/{$refund->id}/complete", [
            'destination_selection_version' => (int) $refund->fresh()->destination_selection_version,
            'notes' => 'Refund satu kursi kelompok diselesaikan admin.',
            'proof' => UploadedFile::fake()->image('refund.jpg', 8, 8),
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'refund-figure-complete',
        ])->assertOk();

        $this->assertSame('paid', $refund->fresh()->status);

        $freshBooking = $booking->fresh();
        $figures = app(RefundSettlementService::class)->recomputeBookingFigures($freshBooking);

        // Nilai sesi dikurangi porsi refund yang ditanggung tutor: satu peserta
        // tersisa (50.000) dikurangi refund tutor 25.000.
        $this->assertSame(25000.0, $figures['gross']);
        $this->assertSame(20000.0, $figures['net']);
        $this->assertSame($figures['gross'], round((float) $freshBooking->gross_amount, 2));
        $this->assertSame($figures['net'], round((float) $freshBooking->teacher_net_amount, 2));
        $this->assertSame($figures['status'], $freshBooking->status);
        $this->assertSame($figures['payout_status'], $freshBooking->payout_status);

        $settled = FinancialJournal::query()->where('event_key', "booking:{$booking->id}:settled")->firstOrFail();
        $this->assertSame(
            round((float) $settled->entries()->where('side', 'debit')->sum('amount'), 2),
            round((float) $settled->entries()->where('side', 'credit')->sum('amount'), 2)
        );
    }

    public function test_tutor_exit_skips_adjustment_journals_for_unsettled_sessions(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];

        // Sesi belum pernah diselesaikan: belum ada jurnal booking settled.
        $this->assertDatabaseMissing('financial_journals', ['event_key' => "booking:{$booking->id}:settled"]);

        $refund = app(PartialPackageRefundService::class)->queueTutorExit(
            $fixture['package'],
            $fixture['admin'],
            'Tutor mengundurkan diri',
            'Persetujuan tutor terlampir.',
            [$booking->id]
        );

        $this->assertSame('tutor_exit', $refund->source_type);
        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertSame('refund_pending', $booking->fresh()->status);
        $this->assertSame('locked', $booking->fresh()->payout_status);

        // Tanpa jurnal settled tidak ada hak tutor yang bisa dibalik.
        $this->assertSame(0, FinancialJournal::query()
            ->where('event_key', 'like', "refund:{$refund->id}:adjust:%")
            ->count());
        $this->assertSame(0, FinancialJournal::query()->where('type', 'refund_session_adjusted')->count());
    }

    public function test_settled_session_refund_posts_a_balanced_adjustment_journal(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        $booking->participants()->update(['status' => 'approved']);
        $booking->forceFill([
            'status' => 'completed',
            'gross_amount' => 50000,
            'teacher_net_amount' => 40000,
            'payout_status' => 'ready',
        ])->save();

        $refund = Refund::create([
            'order_id' => $fixture['order']->id,
            'booking_id' => $booking->id,
            'user_id' => $fixture['student']->id,
            'amount' => 50000,
            'reason' => 'Sesi selesai dikembalikan penuh oleh admin.',
            'status' => 'pending',
            'cost_bearer' => 'tutor_share',
        ]);

        $journal = app(FinancialLedgerService::class)
            ->recordSessionRefundAdjustment($booking->fresh(), $refund, 50000, 40000);

        $this->assertNotNull($journal);
        $debit = round((float) $journal->entries()->where('side', 'debit')->sum('amount'), 2);
        $credit = round((float) $journal->entries()->where('side', 'credit')->sum('amount'), 2);
        $this->assertSame($debit, $credit);
        $this->assertSame(50000.0, $debit);
    }
}
