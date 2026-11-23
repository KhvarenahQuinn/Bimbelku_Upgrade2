<?php

namespace Tests\Feature;

use App\Models\BookingDispute;
use App\Models\BookingParticipant;
use App\Models\FinancialJournal;
use App\Models\Refund;
use App\Models\SessionReport;
use App\Models\User;
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
        // Laporan darurat hanya masuk akal saat sesi sudah berjalan.
        $booking->forceFill([
            'start_at' => now()->subMinutes(20),
            'end_at' => now()->addMinutes(40),
        ])->save();
        Sanctum::actingAs($fixture['teacher']);

        $this->postJson("/api/teacher/bookings/{$booking->id}/emergency", [
            'incident_type' => 'keluarga',
            'chronology' => 'Tutor mengalami musibah keluarga mendadak dan tidak dapat mengajar pada jam tersebut.',
            'incident_at' => now()->subMinutes(30)->toDateTimeString(),
            'incident_location' => 'Yogyakarta',
            'impact' => 'Sesi tidak dapat dilaksanakan oleh tutor.',
            'evidence' => UploadedFile::fake()->image('bukti.jpg', 8, 8),
        ])->assertCreated();

        $booking->refresh();
        $this->assertSame('admin_review_required', $booking->status);
        $this->assertSame('locked', $booking->payout_status);
        $this->assertNotNull($booking->admin_review_required_at);
        $this->assertSame('paid', $booking->participants()->firstOrFail()->status);
        $this->assertSame('paid', $fixture['order']->fresh()->status);
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_emergency_report_cannot_freeze_a_session_that_has_not_started(): void
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        Sanctum::actingAs($fixture['teacher']);

        $payload = [
            'incident_type' => 'keluarga',
            'chronology' => 'Tutor mengalami musibah keluarga mendadak dan tidak dapat mengajar pada jam tersebut.',
            'incident_at' => now()->toDateTimeString(),
            'incident_location' => 'Yogyakarta',
            'impact' => 'Sesi tidak dapat dilaksanakan oleh tutor.',
            'evidence' => UploadedFile::fake()->image('bukti.jpg', 8, 8),
        ];

        $this->postJson("/api/teacher/bookings/{$booking->id}/emergency", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Laporan keadaan darurat dapat dibuat mulai 15 menit sebelum sesi dimulai.');

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(0, SessionReport::query()->count());

        // Sesi berjalan, tetapi waktu kejadian yang diklaim berada di luar jendela sesi.
        $booking->forceFill([
            'start_at' => now()->subMinutes(20),
            'end_at' => now()->addMinutes(40),
        ])->save();
        $this->postJson("/api/teacher/bookings/{$booking->id}/emergency", [
            ...$payload,
            'incident_at' => now()->subHours(5)->toDateTimeString(),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Waktu kejadian harus berada di dalam jendela sesi ini.');

        $this->assertSame(0, SessionReport::query()->count());
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

    public function test_emergency_session_refund_uses_the_paid_session_value_not_a_client_amount(): void
    {
        $fixture = $this->emergencyFixture();
        $report = SessionReport::query()->firstOrFail();
        Sanctum::actingAs($fixture['admin']);

        // Nominal dari klien tidak lagi dipakai: backend menghitung sendiri.
        $this->postJson("/api/admin/session-reports/{$report->id}/resolve", [
            'decision' => 'accepted',
            'refund_scope' => 'session',
            'refund_amount' => 1,
            'notes' => 'Bukti kehadiran kosong di kedua sisi, dana sesi dikembalikan penuh.',
        ])->assertOk();

        $refund = Refund::query()->where('source_type', 'teacher_emergency')->firstOrFail();
        $this->assertSame(50000.0, (float) $refund->amount);
        $this->assertSame('accepted', $report->fresh()->status);
    }

    public function test_emergency_decision_without_any_paid_participant_is_rejected(): void
    {
        $fixture = $this->emergencyFixture();
        $report = SessionReport::query()->firstOrFail();
        $fixture['order']->update(['status' => 'cancelled']);
        Sanctum::actingAs($fixture['admin']);

        $this->postJson("/api/admin/session-reports/{$report->id}/resolve", [
            'decision' => 'accepted',
            'refund_scope' => 'session',
            'notes' => 'Tidak ada dana murid yang dapat dikembalikan pada sesi ini.',
        ])->assertUnprocessable();

        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_emergency_decision_with_session_refund_queues_one_refund_without_a_phantom_adjustment(): void
    {
        $fixture = $this->emergencyFixture();
        $report = SessionReport::query()->firstOrFail();
        Sanctum::actingAs($fixture['admin']);

        $this->postJson("/api/admin/session-reports/{$report->id}/resolve", [
            'decision' => 'accepted',
            'refund_scope' => 'session',
            'cost_bearer' => 'tutor_share',
            'notes' => 'Bukti kehadiran kosong di kedua sisi, dana sesi dikembalikan penuh.',
        ])->assertOk();

        $refund = Refund::query()->where('source_type', 'teacher_emergency')->firstOrFail();
        $this->assertSame(50000.0, (float) $refund->amount);
        $this->assertSame('tutor_share', $refund->cost_bearer);
        $this->assertSame('admin', $refund->decided_by);
        $this->assertSame($fixture['admin']->id, (int) $refund->decision_actor_id);
        $this->assertDatabaseHas('financial_journals', [
            'event_key' => "refund:{$refund->id}:queued",
        ]);
        // Sesi ini belum pernah diselesaikan, jadi belum ada hak tutor yang
        // bisa dibalik. Jurnal pembalik di sini akan menjadi debit
        // tutor_payable tanpa lawan (phantom).
        $this->assertDatabaseMissing('financial_journals', [
            'event_key' => "refund:{$refund->id}:adjust:{$fixture['first']['booking']->id}",
        ]);
        $this->assertSame(0, FinancialJournal::query()->where('type', 'refund_session_adjusted')->count());
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_emergency_session_refund_uses_each_participant_paid_amount(): void
    {
        $fixture = $this->groupEmergencyFixture();
        $report = SessionReport::query()->firstOrFail();
        Sanctum::actingAs($fixture['admin']);

        $this->postJson("/api/admin/session-reports/{$report->id}/resolve", [
            'decision' => 'accepted',
            'refund_scope' => 'session',
            'cost_bearer' => 'tutor_share',
            'notes' => 'Sesi kelompok tidak terlaksana, tiap murid direfund sesuai nominalnya.',
        ])->assertOk();

        $refunds = Refund::query()
            ->where('source_type', 'teacher_emergency')
            ->orderBy('user_id')
            ->get();
        $this->assertCount(2, $refunds);
        $byStudent = $refunds->keyBy('user_id');
        $this->assertSame(50000.0, (float) $byStudent[$fixture['student']->id]->amount);
        $this->assertSame(40000.0, (float) $byStudent[$fixture['secondStudent']->id]->amount);
        $this->assertSame(90000.0, (float) $refunds->sum('amount'));
        $this->artisan('finance:verify-ledger')->assertSuccessful();
    }

    public function test_platform_refund_cannot_be_repeated_through_another_report_path(): void
    {
        $fixture = $this->emergencyFixture();
        $booking = $fixture['first']['booking'];
        $report = SessionReport::query()->firstOrFail();
        Sanctum::actingAs($fixture['admin']);

        $this->postJson("/api/admin/session-reports/{$report->id}/resolve", [
            'decision' => 'accepted',
            'refund_scope' => 'session',
            'cost_bearer' => 'platform',
            'notes' => 'Sesi batal karena keadaan darurat tutor, biaya ditanggung platform.',
        ])->assertOk();

        $first = Refund::query()->where('source_type', 'teacher_emergency')->firstOrFail();
        $this->assertSame('platform', $first->cost_bearer);

        // Jalur lain pada sesi yang sama harus ditolak, bukan menambah refund kedua.
        SessionReport::create([
            'booking_id' => $booking->id,
            'teacher_id' => $fixture['teacher']->id,
            'reported_by' => $fixture['student']->id,
            'reported_student_id' => $fixture['student']->id,
            'type' => 'teacher_absence',
            'chronology' => 'Tutor tidak hadir pada sesi yang sudah diputuskan lewat laporan darurat.',
            'status' => 'pending',
        ]);
        $second = SessionReport::query()->where('type', 'teacher_absence')->firstOrFail();

        $this->postJson("/api/admin/session-reports/{$second->id}/resolve", [
            'decision' => 'accepted',
            'notes' => 'Laporan susulan tidak boleh menggandakan refund sesi yang sama.',
        ])->assertUnprocessable();

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(50000.0, (float) Refund::query()->sum('amount'));
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

    /**
     * Sesi kelompok dengan dua murid yang membayar nominal berbeda. Order
     * menampung total yang benar-benar dibayar supaya split tender tetap sah.
     */
    private function groupEmergencyFixture(): array
    {
        $fixture = SessionFinanceFixture::make($this, 1);
        $booking = $fixture['first']['booking'];
        $booking->update(['start_at' => now()->subMinutes(90), 'end_at' => now()->subMinutes(30)]);

        $secondStudent = User::factory()->create(['role' => 'student', 'status' => 'active', 'name' => 'Murid Kelompok']);
        BookingParticipant::create([
            'booking_id' => $booking->id,
            'student_id' => $secondStudent->id,
            'order_id' => $fixture['order']->id,
            'amount' => 40000,
            'status' => 'paid',
        ]);
        $fixture['order']->update([
            'amount' => 90000,
            'subtotal_amount' => 90000,
            'external_received_amount' => 90000,
        ]);

        SessionReport::create([
            'booking_id' => $booking->id,
            'teacher_id' => $fixture['teacher']->id,
            'reported_by' => $fixture['teacher']->id,
            'type' => 'teacher_emergency',
            'incident_type' => 'keluarga',
            'chronology' => 'Tutor mengalami musibah keluarga mendadak dan tidak dapat mengajar kelas kelompok.',
            'incident_at' => now()->subHour(),
            'incident_location' => 'Yogyakarta',
            'impact' => 'Sesi kelompok tidak dapat dilaksanakan.',
            'evidence' => 'emergency_evidence/bukti.jpg',
            'status' => 'pending',
        ]);
        $booking->update(['status' => 'admin_review_required', 'admin_review_required_at' => now(), 'payout_status' => 'locked']);

        return $fixture + ['secondStudent' => $secondStudent];
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
