<?php

namespace Tests\Feature;

use App\Models\BookingDispute;
use App\Models\Refund;
use App\Models\SessionReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SessionFinanceFixture;
use Tests\TestCase;

class SessionRefundDecisionTest extends TestCase
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

    public function test_teacher_emergency_report_waits_for_admin_without_refunding(): void
    {
        $fixture = SessionFinanceFixture::make($this, 2);
        $booking = $fixture['first']['booking'];
        Sanctum::actingAs($fixture['teacher']);

        $this->postJson("/api/teacher/bookings/{$booking->id}/emergency", [
            'incident_type' => 'keluarga',
            'chronology' => 'Tutor mengalami musibah keluarga mendadak dan tidak dapat mengajar pada jam tersebut.',
            'incident_at' => now()->subMinutes(30)->toDateTimeString(),
            'incident_location' => 'Yogyakarta',
            'impact' => 'Sesi tidak dapat dilaksanakan oleh tutor.',
            'evidence' => UploadedFile::fake()->image('bukti.jpg'),
        ])->assertCreated();

        $booking->refresh();
        $this->assertSame('admin_review_required', $booking->status);
        $this->assertSame('locked', $booking->payout_status);
        $this->assertNotNull($booking->admin_review_required_at);
        $this->assertSame('paid', $booking->participants()->firstOrFail()->status);
        $this->assertSame('paid', $fixture['order']->fresh()->status);
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_emergency_decision_without_refund_pays_the_tutor_normally(): void
    {
        $fixture = $this->emergencyFixture();
        $report = SessionReport::query()->firstOrFail();
        Sanctum::actingAs($fixture['admin']);

        $this->postJson("/api/admin/session-reports/{$report->id}/resolve", [
            'decision' => 'accepted',
            'refund_scope' => 'none',
            'notes' => 'Tutor menyelesaikan sesi susulan sehingga sesi dinilai tetap berjalan.',
        ])->assertOk();

        $booking = $fixture['first']['booking']->fresh();
        $this->assertSame('completed', $booking->status);
        $this->assertSame('ready', $booking->payout_status);
        $this->assertSame(0, Refund::query()->where('source_type', 'teacher_emergency')->count());
    }

    public function test_emergency_decision_with_session_refund_queues_one_adjustment(): void
    {
        $fixture = $this->emergencyFixture();
        $report = SessionReport::query()->firstOrFail();
        Sanctum::actingAs($fixture['admin']);

        $this->postJson("/api/admin/session-reports/{$report->id}/resolve", [
            'decision' => 'accepted',
            'refund_scope' => 'session',
            'refund_amount' => 50000,
            'cost_bearer' => 'tutor_share',
            'notes' => 'Bukti kehadiran kosong di kedua sisi, dana sesi dikembalikan penuh.',
        ])->assertOk();

        $refund = Refund::query()->where('source_type', 'teacher_emergency')->firstOrFail();
        $this->assertSame(50000.0, (float) $refund->amount);
        $this->assertSame('tutor_share', $refund->cost_bearer);
        $this->assertSame('admin', $refund->decided_by);
        $this->assertSame($fixture['admin']->id, (int) $refund->decision_actor_id);
        $this->assertDatabaseHas('financial_journals', [
            'event_key' => "refund:{$refund->id}:adjust:{$fixture['first']['booking']->id}",
        ]);
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_student_can_appeal_a_lapsed_session_only_once(): void
    {
        $fixture = SessionFinanceFixture::make($this, 2);
        $booking = $fixture['first']['booking'];
        Carbon::setTestNow(now()->addDays(2));
        $booking->update([
            'start_at' => now()->subHours(3),
            'end_at' => now()->subHours(2),
            'status' => 'admin_review_required',
            'admin_review_required_at' => now(),
        ]);
        Sanctum::actingAs($fixture['student']);

        $this->postJson("/api/student/bookings/{$booking->id}/appeal", [
            'reason' => 'Saya menunggu di ruang kelas selama satu jam tetapi tutor tidak pernah masuk.',
        ], ['Idempotency-Key' => 'appeal-1'])->assertCreated();

        $dispute = BookingDispute::query()->firstOrFail();
        $this->assertSame('lapsed_session', $dispute->kind);
        $this->assertSame('pending', $dispute->status);

        $this->postJson("/api/student/bookings/{$booking->id}/appeal", [
            'reason' => 'Saya menunggu di ruang kelas selama satu jam tetapi tutor tidak pernah masuk.',
        ], ['Idempotency-Key' => 'appeal-2'])->assertStatus(422);
    }

    public function test_lapsed_appeal_partial_refund_requires_bearer_and_stays_within_session_value(): void
    {
        $fixture = $this->lapsedAppealFixture();
        $dispute = BookingDispute::query()->firstOrFail();
        Sanctum::actingAs($fixture['admin']);

        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", [
            'resolution' => 'partial_refund',
            'refund_amount' => 20000,
            'notes' => 'Sebagian sesi terlaksana sehingga hanya sebagian nilai dikembalikan.',
        ])->assertStatus(422);

        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", [
            'resolution' => 'partial_refund',
            'refund_amount' => 20000,
            'cost_bearer' => 'platform',
            'notes' => 'Sebagian sesi terlaksana sehingga hanya sebagian nilai dikembalikan.',
        ])->assertOk();

        $refund = Refund::query()->where('source_type', 'lapsed_session')->firstOrFail();
        $this->assertSame(20000.0, (float) $refund->amount);
        $this->assertSame('platform', $refund->cost_bearer);
        $this->assertSame('lapsed_session', $refund->source_type);
    }

    public function test_teacher_paid_decision_needs_attendance_evidence(): void
    {
        $fixture = $this->lapsedAppealFixture();
        $dispute = BookingDispute::query()->firstOrFail();
        Sanctum::actingAs($fixture['admin']);

        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", [
            'resolution' => 'teacher_paid',
            'notes' => 'Tutor hadir dan menunggu, murid tidak muncul pada sesi ini.',
        ])->assertStatus(422);

        $this->assertSame('pending', $dispute->fresh()->status);
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_attendance_evidence_reports_the_observed_state(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        $booking->update(['tutor_ready_at' => now()->subHour()]);
        Sanctum::actingAs($fixture['admin']);

        $this->getJson("/api/admin/bookings/{$booking->id}/attendance-evidence")
            ->assertOk()
            ->assertJsonPath('data.evidence_state', 'tutor_present_student_absent');
    }

    private function emergencyFixture(): array
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        $booking->update(['start_at' => now()->subMinutes(90), 'end_at' => now()->subMinutes(30)]);
        SessionReport::create([
            'booking_id' => $booking->id,
            'teacher_id' => $fixture['teacher']->id,
            'reported_by' => $fixture['teacher']->id,
            'type' => 'teacher_emergency',
            'incident_type' => 'keluarga',
            'chronology' => 'Tutor mengalami musibah keluarga mendadak dan tidak dapat mengajar.',
            'incident_at' => now()->subHour(),
            'incident_location' => 'Yogyakarta',
            'impact' => 'Sesi tidak dapat dilaksanakan.',
            'evidence' => 'emergency_evidence/bukti.jpg',
            'status' => 'pending',
        ]);
        $booking->update(['status' => 'admin_review_required', 'admin_review_required_at' => now(), 'payout_status' => 'locked']);

        return $fixture;
    }

    private function lapsedAppealFixture(): array
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        Carbon::setTestNow(now()->addDays(2));
        $booking->update([
            'start_at' => now()->subHours(3),
            'end_at' => now()->subHours(2),
            'status' => 'admin_review_required',
            'admin_review_required_at' => now(),
            'payout_status' => 'locked',
        ]);
        BookingDispute::create([
            'booking_id' => $booking->id,
            'kind' => 'lapsed_session',
            'student_id' => $fixture['student']->id,
            'reason' => 'Saya menunggu di ruang kelas selama satu jam tetapi tutor tidak pernah masuk.',
            'status' => 'pending',
        ]);

        return $fixture;
    }
}
