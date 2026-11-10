<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\CurriculumSubject;
use App\Models\LearningPackage;
use App\Models\LearningTimeSlot;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\Refund;
use App\Models\TeacherAvailability;
use App\Models\TeacherProfile;
use App\Models\TeacherReplacementRequest;
use App\Models\User;
use App\Services\PartialPackageRefundService;
use App\Services\TeacherAssignmentService;
use App\Services\TeacherMatchingService;
use App\Services\TeacherReplacementService;
use Carbon\Carbon;
use Database\Seeders\CurriculumCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TeacherReplacementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_replacement_moves_only_future_sessions_and_preserves_package_progress_and_finance(): void
    {
        $fixture = $this->fixture();
        $service = app(TeacherReplacementService::class);
        $replacement = $service->submit($fixture['student'], $fixture['package'], $fixture['subject'], [
            'reason_code' => 'learning_fit',
            'reason_detail' => 'Metode pengajaran pada sesi berikutnya perlu disesuaikan dengan kebutuhan belajar murid.',
        ]);

        $this->assertCount(1, $replacement->sessions);
        $this->assertSame($fixture['future_booking']->id, $replacement->sessions->first()->old_booking_id);
        $this->assertSame('completed', $fixture['completed_booking']->fresh()->status);

        $approved = $service->approve($replacement, $fixture['admin'], 'Permintaan valid dan sesi mendatang aman untuk dialihkan kepada tutor pengganti.');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $fixture['student']->id,
            'title' => 'Penggantian guru disetujui',
            'is_read' => false,
            'target_url' => '/student/packages',
        ]);
        $offer = app(TeacherMatchingService::class)->dispatchNextOffer($approved['booking_request']);
        $this->assertNotNull($offer);
        $this->assertSame($fixture['new_teacher']->id, $offer->teacher_id);
        $this->assertNotSame($fixture['old_teacher']->id, $offer->teacher_id);

        $service->acceptOffer($offer, $fixture['new_teacher']);
        $futureSession = $fixture['future_session']->fresh('booking');

        $this->assertSame('active', $fixture['package']->fresh()->status);
        $this->assertSame(1, $fixture['package']->fresh()->used_sessions);
        $this->assertSame('completed', $fixture['completed_booking']->fresh()->status);
        $this->assertSame('ready', $fixture['completed_booking']->fresh()->payout_status);
        $this->assertSame('teacher_replaced', $fixture['future_booking']->fresh()->status);
        $this->assertSame('cancelled', $fixture['future_booking']->fresh()->payout_status);
        $this->assertSame($fixture['new_teacher']->id, $futureSession->booking->teacher_id);
        $this->assertSame($fixture['future_booking']->id, $futureSession->booking->replacement_of_booking_id);
        $this->assertSame($fixture['new_teacher']->id, $fixture['subject']->fresh()->assigned_teacher_id);
        $this->assertSame(1, Order::query()->where('learning_package_id', $fixture['package']->id)->count());
        $this->assertSame('completed', TeacherReplacementRequest::findOrFail($replacement->id)->status);
        $fixture['future_booking']->update(['status' => 'cancelled']);
        $this->assertSame('scheduled', $futureSession->fresh()->status);
    }

    public function test_only_one_open_replacement_is_allowed_per_subject(): void
    {
        $fixture = $this->fixture();
        $service = app(TeacherReplacementService::class);
        $payload = ['reason_code' => 'communication', 'reason_detail' => 'Komunikasi tutor tidak berjalan dan menghambat persiapan sesi belajar berikutnya.'];
        $service->submit($fixture['student'], $fixture['package'], $fixture['subject'], $payload);

        $this->expectException(HttpException::class);
        $service->submit($fixture['student'], $fixture['package'], $fixture['subject'], $payload);
    }

    public function test_multiple_refunds_for_one_order_keep_immutable_tender_snapshots(): void
    {
        $fixture = $this->fixture();
        Refund::create([
            'order_id' => $fixture['order']->id, 'user_id' => $fixture['student']->id,
            'amount' => 25000, 'wallet_refund_amount' => 10000, 'external_refund_amount' => 15000,
            'source_type' => 'teacher_replacement', 'source_id' => 101, 'reason' => 'Sesi A', 'status' => 'pending',
        ]);
        $second = Refund::create([
            'order_id' => $fixture['order']->id, 'user_id' => $fixture['student']->id,
            'amount' => 25000, 'wallet_refund_amount' => 10000, 'external_refund_amount' => 15000,
            'source_type' => 'teacher_replacement', 'source_id' => 102, 'reason' => 'Sesi B', 'status' => 'pending',
        ]);

        $this->assertCount(2, $fixture['order']->refunds()->get());
        $this->assertSame(10000.0, $second->tenderBreakdown()['wallet_funded_amount']);
        $this->assertSame(15000.0, $second->tenderBreakdown()['external_funded_amount']);
    }

    public function test_partial_refund_is_idempotent_capped_and_keeps_the_package_order_paid(): void
    {
        $fixture = $this->fixture();
        $replacement = app(TeacherReplacementService::class)->submit(
            $fixture['student'],
            $fixture['package'],
            $fixture['subject'],
            ['reason_code' => 'teacher_unavailable', 'reason_detail' => 'Tutor tidak dapat melanjutkan jadwal dan diperlukan pencarian guru pengganti.']
        );
        app(TeacherReplacementService::class)->approve($replacement, $fixture['admin'], 'Penggantian disetujui dan pencarian tutor pengganti dapat dimulai untuk sesi tersisa.');
        $replacement->update(['status' => 'no_teacher']);
        $refunds = app(PartialPackageRefundService::class);
        $first = $refunds->queueTeacherReplacement($replacement->fresh());
        $second = $refunds->queueTeacherReplacement($replacement->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(50000.0, (float) $first->amount);
        $this->assertSame(20000.0, (float) $first->wallet_refund_amount);
        $this->assertSame(30000.0, (float) $first->external_refund_amount);
        $this->assertSame('paid', $fixture['order']->fresh()->status);
        $this->assertSame('refund_pending', $fixture['future_session']->fresh()->status);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_matching_exhaustion_opens_the_replacement_fallback(): void
    {
        $fixture = $this->fixture();
        $fixture['old_teacher']->update(['status' => 'inactive']);
        $fixture['new_teacher']->update(['status' => 'inactive']);
        $service = app(TeacherReplacementService::class);
        $replacement = $service->submit($fixture['student'], $fixture['package'], $fixture['subject'], [
            'reason_code' => 'teacher_unavailable',
            'reason_detail' => 'Tutor lama tidak dapat melanjutkan dan sistem perlu mencari pengganti untuk sesi berikutnya.',
        ]);
        $approved = $service->approve($replacement, $fixture['admin'], 'Pengajuan valid dan pencarian dapat dimulai meskipun kandidat belum tersedia saat ini.');

        $this->assertNull(app(TeacherMatchingService::class)->dispatchNextOffer($approved['booking_request']));
        $this->assertSame('no_teacher', $replacement->fresh()->status);
        $this->assertSame('no_teacher', $approved['booking_request']->fresh()->status);
    }

    public function test_admin_manual_assignment_uses_the_replacement_path_without_reactivating_package(): void
    {
        $fixture = $this->fixture();
        $service = app(TeacherReplacementService::class);
        $replacement = $service->submit($fixture['student'], $fixture['package'], $fixture['subject'], [
            'reason_code' => 'schedule',
            'reason_detail' => 'Jadwal tutor lama tidak lagi sesuai dan perlu dialihkan untuk sesi yang masih tersisa.',
        ]);
        $approved = $service->approve($replacement, $fixture['admin'], 'Admin sudah memastikan kesediaan tutor pengganti dan validitas seluruh jadwal tersisa.');
        $result = app(TeacherAssignmentService::class)->assignManually(
            $approved['booking_request'],
            $fixture['new_teacher'],
            $fixture['admin'],
            'Tutor sudah dihubungi admin dan menyetujui seluruh jadwal sesi tersisa.'
        );

        $this->assertSame('teacher_replacement', $result['assignment']['type']);
        $this->assertSame('active', $fixture['package']->fresh()->status);
        $this->assertSame($fixture['new_teacher']->id, $fixture['future_session']->fresh('booking')->booking->teacher_id);
        $this->assertSame('completed', $replacement->fresh()->status);
    }

    public function test_feature_flag_policy_and_idempotency_protect_the_submission_endpoint(): void
    {
        config()->set('features.teacher_replacement', false);
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['student']);
        $url = "/api/student/packages/{$fixture['package']->id}/subjects/{$fixture['subject']->id}/teacher-replacements";
        $payload = [
            'reason_code' => 'communication',
            'reason_detail' => 'Komunikasi tutor tidak berjalan dan menghambat persiapan sesi belajar berikutnya.',
        ];

        $this->postJson($url, $payload, ['Idempotency-Key' => 'replacement-disabled'])
            ->assertNotFound();

        config()->set('features.teacher_replacement', true);
        $first = $this->postJson($url, $payload, ['Idempotency-Key' => 'replacement-submit-001'])
            ->assertCreated();
        $second = $this->postJson($url, $payload, ['Idempotency-Key' => 'replacement-submit-001'])
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('teacher_replacement_requests', 1);

        $otherStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($otherStudent);
        $this->postJson($url, $payload, ['Idempotency-Key' => 'replacement-wrong-owner'])
            ->assertForbidden();
    }

    public function test_package_api_keeps_the_teacher_identity_per_session_after_replacement(): void
    {
        $fixture = $this->fixture();
        $service = app(TeacherReplacementService::class);
        $replacement = $service->submit($fixture['student'], $fixture['package'], $fixture['subject'], [
            'reason_code' => 'learning_fit',
            'reason_detail' => 'Metode pengajaran perlu disesuaikan agar sesi berikutnya lebih cocok untuk kebutuhan murid.',
        ]);
        $approved = $service->approve($replacement, $fixture['admin'], 'Permintaan valid dan sesi mendatang dapat dialihkan dengan aman kepada tutor lain.');
        $offer = app(TeacherMatchingService::class)->dispatchNextOffer($approved['booking_request']);
        $service->acceptOffer($offer, $fixture['new_teacher']);

        Sanctum::actingAs($fixture['student']);
        $response = $this->getJson("/api/student/packages/{$fixture['package']->id}")
            ->assertOk();

        $this->assertSame($fixture['old_teacher']->id, $response->json('subjects.0.sessions.0.teacher.id'));
        $this->assertSame($fixture['new_teacher']->id, $response->json('subjects.0.sessions.1.teacher.id'));
    }

    public function test_approval_requires_reschedule_when_a_remaining_session_is_under_twenty_four_hours(): void
    {
        $fixture = $this->fixture();
        $start = now()->addHours(20);
        $fixture['future_session']->update([
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addHour(),
        ]);
        $fixture['future_booking']->update([
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
        ]);
        $fixture['future_booking']->bookingRequest->update([
            'scheduled_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addHour()->format('H:i:s'),
        ]);

        $service = app(TeacherReplacementService::class);
        $replacement = $service->submit($fixture['student'], $fixture['package'], $fixture['subject'], [
            'reason_code' => 'schedule',
            'reason_detail' => 'Jadwal tutor lama tidak lagi sesuai untuk sesi yang masih tersisa pada paket.',
        ]);
        $result = $service->approve($replacement, $fixture['admin'], 'Sesi terlalu dekat sehingga murid wajib memilih ulang jadwal minimal dua puluh empat jam.');

        $this->assertTrue($result['needs_reschedule']);
        $this->assertSame('no_teacher', $replacement->fresh()->status);
        $this->assertSame('no_teacher', $result['booking_request']->fresh()->status);
        $this->assertDatabaseCount('teacher_offers', 0);
    }

    public function test_refund_and_teacher_acceptance_cannot_both_complete(): void
    {
        $fixture = $this->fixture();
        $service = app(TeacherReplacementService::class);
        $replacement = $service->submit($fixture['student'], $fixture['package'], $fixture['subject'], [
            'reason_code' => 'teacher_unavailable',
            'reason_detail' => 'Tutor tidak dapat melanjutkan sehingga sistem perlu mencari guru untuk sesi tersisa.',
        ]);
        $approved = $service->approve($replacement, $fixture['admin'], 'Penggantian valid dan pencarian guru baru dapat dimulai untuk sesi yang tersisa.');
        $offer = app(TeacherMatchingService::class)->dispatchNextOffer($approved['booking_request']);
        $replacement->update(['status' => 'no_teacher']);
        app(PartialPackageRefundService::class)->queueTeacherReplacement($replacement->fresh());

        try {
            $service->acceptOffer($offer, $fixture['new_teacher']);
            $this->fail('Tutor tidak boleh menerima setelah refund dikunci.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame('refund_pending', $replacement->fresh()->status);
        $this->assertNull($fixture['future_session']->fresh()->booking_id);
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_terminal_state_cannot_transition_back_into_matching(): void
    {
        $fixture = $this->fixture();
        $replacement = app(TeacherReplacementService::class)->submit(
            $fixture['student'],
            $fixture['package'],
            $fixture['subject'],
            ['reason_code' => 'conduct', 'reason_detail' => 'Ada masalah perilaku tutor yang perlu diperiksa terlebih dahulu oleh admin.']
        );
        app(TeacherReplacementService::class)->reject(
            $replacement,
            $fixture['admin'],
            'Bukti belum cukup sehingga pengajuan ini ditolak tanpa mengubah sesi paket yang berjalan.'
        );

        $this->expectException(ValidationException::class);
        $replacement->fresh()->transitionTo('matching');
    }

    public function test_replacement_on_multi_subject_package_does_not_touch_another_subject(): void
    {
        $fixture = $this->fixture();
        $otherCatalog = CurriculumSubject::query()
            ->where('normalized_name', '<>', 'matematika')
            ->firstOrFail();
        $otherSubject = PackageSubject::create([
            'learning_package_id' => $fixture['package']->id,
            'curriculum_subject_id' => $otherCatalog->id,
            'assigned_teacher_id' => $fixture['old_teacher']->id,
            'subject_name' => $otherCatalog->name,
            'chapter' => 'Materi lain',
            'curriculum_chapter_ids' => [],
            'allocated_sessions' => 1,
            'unit_price' => 50000,
            'subtotal_amount' => 50000,
            'status' => 'active',
        ]);
        [$otherSession, $otherBooking] = $this->createSessionFixture(
            $fixture['package'],
            $otherSubject,
            $fixture['order'],
            $fixture['student'],
            $fixture['old_teacher'],
            1,
            now()->addDays(3),
            'confirmed',
        );
        $fixture['package']->update(['total_sessions' => 3, 'total_amount' => 150000, 'subtotal_amount' => 150000]);
        $fixture['order']->update(['amount' => 150000, 'subtotal_amount' => 150000]);

        $service = app(TeacherReplacementService::class);
        $replacement = $service->submit($fixture['student'], $fixture['package'], $fixture['subject'], [
            'reason_code' => 'learning_fit',
            'reason_detail' => 'Metode pada mapel Matematika perlu disesuaikan tanpa mengubah mapel lain di paket.',
        ]);
        $approved = $service->approve($replacement, $fixture['admin'], 'Hanya sesi Matematika yang disetujui untuk dialihkan kepada tutor pengganti.');
        $offer = app(TeacherMatchingService::class)->dispatchNextOffer($approved['booking_request']);
        $service->acceptOffer($offer, $fixture['new_teacher']);

        $this->assertSame($fixture['old_teacher']->id, $otherSubject->fresh()->assigned_teacher_id);
        $this->assertSame($otherBooking->id, $otherSession->fresh()->booking_id);
        $this->assertSame('confirmed', $otherBooking->fresh()->status);
        $this->assertSame('locked', $otherBooking->fresh()->payout_status);
    }

    public function test_admin_approval_endpoint_is_idempotent_and_audits_the_replacement_target(): void
    {
        $fixture = $this->fixture();
        $replacement = app(TeacherReplacementService::class)->submit(
            $fixture['student'],
            $fixture['package'],
            $fixture['subject'],
            ['reason_code' => 'schedule', 'reason_detail' => 'Jadwal tutor lama tidak lagi sesuai untuk seluruh sesi paket yang tersisa.']
        );
        config()->set('features.teacher_replacement', true);
        Sanctum::actingAs($fixture['admin']);
        $url = '/api/admin/teacher-replacements/'.$replacement->id.'/approve';
        $payload = ['notes' => 'Admin sudah memeriksa paket, mapel, tutor, dan seluruh sesi tersisa secara lengkap.'];

        $first = $this->postJson($url, $payload, ['Idempotency-Key' => 'replacement-admin-approve'])
            ->assertOk();
        $second = $this->postJson($url, $payload, ['Idempotency-Key' => 'replacement-admin-approve'])
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('teacher_pending', $replacement->fresh()->status);
        $audit = AdminAuditLog::query()->where('target_type', 'TeacherReplacementRequest')->latest('id')->firstOrFail();
        $this->assertSame($replacement->id, $audit->target_id);
        $this->assertSame(200, $audit->response_status);
    }

    public function test_student_can_cancel_a_pending_request_idempotently_but_admin_cannot_approve_it_afterwards(): void
    {
        $fixture = $this->fixture();
        $replacement = app(TeacherReplacementService::class)->submit(
            $fixture['student'],
            $fixture['package'],
            $fixture['subject'],
            ['reason_code' => 'communication', 'reason_detail' => 'Komunikasi tutor menghambat persiapan belajar dan perlu ditinjau sebelum sesi berikutnya.']
        );
        config()->set('features.teacher_replacement', true);
        Sanctum::actingAs($fixture['student']);
        $url = '/api/student/teacher-replacements/'.$replacement->id.'/cancel';

        $this->postJson($url, [], ['Idempotency-Key' => 'replacement-cancel-001'])->assertOk();
        $this->postJson($url, [], ['Idempotency-Key' => 'replacement-cancel-001'])->assertOk();

        $this->assertSame('cancelled', $replacement->fresh()->status);
        $this->assertSame(['cancelled'], $replacement->sessions()->pluck('status')->unique()->values()->all());

        try {
            app(TeacherReplacementService::class)->approve(
                $replacement->fresh(),
                $fixture['admin'],
                'Admin kedua tidak boleh menyetujui kasus yang telah dibatalkan oleh murid sebelumnya.'
            );
            $this->fail('Kasus batal tidak boleh disetujui.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_retry_resets_matching_state_without_creating_a_second_replacement(): void
    {
        $fixture = $this->fixture();
        $service = app(TeacherReplacementService::class);
        $replacement = $service->submit(
            $fixture['student'],
            $fixture['package'],
            $fixture['subject'],
            ['reason_code' => 'teacher_unavailable', 'reason_detail' => 'Tutor lama tidak dapat melanjutkan sehingga pencarian perlu dicoba kembali untuk sesi tersisa.']
        );
        $approved = $service->approve($replacement, $fixture['admin'], 'Permintaan sudah diverifikasi dan aman untuk masuk pencarian tutor pengganti.');
        $replacement->update(['status' => 'no_teacher']);
        $approved['booking_request']->update(['status' => 'no_teacher', 'search_expires_at' => now()->subMinute()]);

        $result = $service->retry($replacement->fresh(), $fixture['student']);

        $this->assertSame('matching', $result['replacement']->fresh()->status);
        $this->assertSame('matching', $result['booking_request']->fresh()->status);
        $this->assertTrue($result['booking_request']->fresh()->search_expires_at->isFuture());
        $this->assertDatabaseCount('teacher_replacement_requests', 1);
        $this->assertDatabaseHas('matching_operation_logs', [
            'booking_request_id' => $result['booking_request']->id,
            'action' => 'search_restarted',
        ]);
    }

    public function test_reschedule_enforces_twenty_four_hours_and_changes_only_the_snapshot_session(): void
    {
        $fixture = $this->fixture();
        LearningTimeSlot::firstOrCreate(
            ['start_time' => '16:00:00'],
            ['label' => '16:00', 'sort_order' => 16, 'is_active' => true]
        );
        $service = app(TeacherReplacementService::class);
        $replacement = $service->submit(
            $fixture['student'],
            $fixture['package'],
            $fixture['subject'],
            ['reason_code' => 'schedule', 'reason_detail' => 'Jadwal lama sulit dipenuhi dan perlu dipindahkan sebelum pencarian guru pengganti dilanjutkan.']
        );
        $approved = $service->approve($replacement, $fixture['admin'], 'Penggantian disetujui dan murid dapat memilih jadwal baru untuk sesi yang dialihkan.');
        $replacement->update(['status' => 'no_teacher']);
        $approved['booking_request']->update(['status' => 'no_teacher']);
        $oldBookingStart = $fixture['future_booking']->start_at->copy();

        try {
            $service->reschedule($replacement->fresh(), $fixture['student'], [now()->addHours(23)->startOfHour()->toIso8601String()]);
            $this->fail('Jadwal di bawah 24 jam harus ditolak.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $newStart = now()->addDays(3)->setTime(16, 0);
        $result = $service->reschedule($replacement->fresh(), $fixture['student'], [$newStart->toIso8601String()]);

        $this->assertSame($newStart->timestamp, $fixture['future_session']->fresh()->scheduled_start_at->timestamp);
        $this->assertSame($oldBookingStart->timestamp, $fixture['future_booking']->fresh()->start_at->timestamp);
        $this->assertSame('teacher_replaced', $fixture['future_booking']->fresh()->status);
        $this->assertSame('matching', $result['replacement']->fresh()->status);
    }

    public function test_completed_partial_refund_settles_subject_and_package_without_refunding_the_order(): void
    {
        $fixture = $this->fixture();
        $service = app(TeacherReplacementService::class);
        $replacement = $service->submit(
            $fixture['student'],
            $fixture['package'],
            $fixture['subject'],
            ['reason_code' => 'teacher_unavailable', 'reason_detail' => 'Tutor pengganti belum tersedia sehingga sisa sesi pada mapel ini perlu dikembalikan.']
        );
        $service->approve($replacement, $fixture['admin'], 'Admin menyetujui pencarian dan fallback refund untuk sesi yang belum terlaksana.');
        $replacement->update(['status' => 'no_teacher']);
        $refunds = app(PartialPackageRefundService::class);
        $refund = $refunds->queueTeacherReplacement($replacement->fresh());
        $refund->forceFill([
            'destination_method' => 'bimbelku_balance',
            'destination_selected_at' => now(),
            'destination_selection_version' => 1,
        ])->save();
        Sanctum::actingAs($fixture['admin']);
        $this->postJson(
            '/api/admin/refunds/'.$refund->id.'/complete',
            ['destination_selection_version' => 1],
            ['Idempotency-Key' => 'replacement-refund-complete-001'],
        )->assertOk();

        $this->assertSame('refunded', $replacement->fresh()->status);
        $this->assertSame('refunded', $fixture['future_session']->fresh()->status);
        $this->assertSame('completed', $fixture['subject']->fresh()->status);
        $this->assertSame('completed', $fixture['package']->fresh()->status);
        $this->assertSame(1, $fixture['package']->fresh()->used_sessions);
        $this->assertSame('paid', $fixture['order']->fresh()->status);
        $this->assertSame('ready', $fixture['completed_booking']->fresh()->payout_status);
    }

    public function test_admin_can_approve_conduct_replacement_without_legacy_penalty_system(): void
    {
        $fixture = $this->fixture();
        $replacement = app(TeacherReplacementService::class)->submit(
            $fixture['student'],
            $fixture['package'],
            $fixture['subject'],
            ['reason_code' => 'conduct', 'reason_detail' => 'Perilaku tutor pada proses belajar perlu diperiksa dan sesi berikutnya harus dialihkan.']
        );

        $result = app(TeacherReplacementService::class)->approve(
            $replacement,
            $fixture['admin'],
            'Pemeriksaan admin membuktikan pelanggaran tutor dan penggantian disetujui untuk sesi tersisa.',
        );

        $this->assertSame('matching', $result['replacement']->fresh()->status);
        $this->assertSame('matching', $result['booking_request']->fresh()->status);
        $this->assertSame('active', $fixture['old_teacher']->fresh()->status);
    }

    private function fixture(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 10:00:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);
        $catalog = CurriculumSubject::query()->where('normalized_name', 'matematika')->firstOrFail();
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $oldTeacher = $this->teacher($catalog, 'old');
        $newTeacher = $this->teacher($catalog, 'new');
        $plan = PackagePlan::create([
            'name' => 'Paket Replacement', 'slug' => 'replacement-test', 'description' => 'Fixture replacement',
            'session_count' => 2, 'validity_days' => 30, 'maximum_subjects' => 1, 'sort_order' => 999, 'is_active' => false,
        ]);
        $package = LearningPackage::create([
            'student_id' => $student->id, 'package_plan_id' => $plan->id, 'package_code' => 'PKG-REPLACE-TEST',
            'education_level' => 'SMP', 'grade' => 'Kelas 7', 'learning_mode' => 'online', 'duration_hours' => 1,
            'status' => 'active', 'total_sessions' => 2, 'used_sessions' => 1, 'subtotal_amount' => 100000,
            'discount_amount' => 0, 'total_amount' => 100000, 'starts_at' => now()->subWeek(), 'expires_at' => now()->addDays(23),
        ]);
        $subject = PackageSubject::create([
            'learning_package_id' => $package->id, 'curriculum_subject_id' => $catalog->id,
            'assigned_teacher_id' => $oldTeacher->id, 'subject_name' => 'Matematika', 'chapter' => 'Aljabar',
            'curriculum_chapter_ids' => [], 'allocated_sessions' => 2, 'unit_price' => 50000,
            'subtotal_amount' => 100000, 'status' => 'active',
        ]);
        $order = Order::create([
            'user_id' => $student->id, 'learning_package_id' => $package->id, 'order_id' => 'ORD-REPLACE-TEST',
            'amount' => 100000, 'subtotal_amount' => 100000, 'discount_amount' => 0, 'status' => 'paid',
            'wallet_applied_amount' => 40000, 'external_received_amount' => 60000,
        ]);
        $order->forceFill(['wallet_applied_amount' => 40000])->saveQuietly();
        [$completedSession, $completedBooking] = $this->createSessionFixture($package, $subject, $order, $student, $oldTeacher, 1, now()->subDays(2), 'completed');
        [$futureSession, $futureBooking] = $this->createSessionFixture($package, $subject, $order, $student, $oldTeacher, 2, Carbon::parse('2026-09-08 14:00:00', 'Asia/Jakarta'), 'confirmed');

        return [
            'student' => $student, 'admin' => $admin, 'old_teacher' => $oldTeacher, 'new_teacher' => $newTeacher,
            'package' => $package, 'subject' => $subject, 'order' => $order,
            'completed_session' => $completedSession, 'completed_booking' => $completedBooking,
            'future_session' => $futureSession, 'future_booking' => $futureBooking,
        ];
    }

    private function teacher(CurriculumSubject $catalog, string $suffix): User
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'email' => "{$suffix}@replacement.test"]);
        $profile = TeacherProfile::create(['user_id' => $teacher->id, 'is_accepting_requests' => true, 'verified_at' => now()->subDay()]);
        $profile->subjects()->create([
            'name' => 'Matematika', 'curriculum_subject_id' => $catalog->id, 'levels' => ['SMP'], 'is_active' => true,
            'is_online' => true, 'is_offline' => false, 'is_private_active' => true,
        ]);
        TeacherAvailability::create(['user_id' => $teacher->id, 'day' => 'Selasa', 'start_time' => '06:00:00', 'end_time' => '22:00:00', 'slots' => [], 'is_active' => true]);

        return $teacher;
    }

    private function createSessionFixture(LearningPackage $package, PackageSubject $subject, Order $order, User $student, User $teacher, int $sequence, Carbon $start, string $status): array
    {
        $request = BookingRequest::create([
            'student_id' => $student->id, 'package_subject_id' => $subject->id, 'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika', 'curriculum_subject_id' => $subject->curriculum_subject_id,
            'education_level' => 'SMP', 'grade' => 'Kelas 7', 'learning_mode' => 'online', 'class_type' => 'private',
            'scheduled_date' => $start->toDateString(), 'start_time' => $start->format('H:i:s'), 'end_time' => $start->copy()->addHour()->format('H:i:s'),
            'duration_hours' => 1, 'status' => $status, 'hourly_rate' => 50000, 'total_amount' => 50000,
        ]);
        $booking = Booking::create([
            'booking_request_id' => $request->id, 'student_id' => $student->id, 'teacher_id' => $teacher->id, 'order_id' => $order->id,
            'start_at' => $start, 'end_at' => $start->copy()->addHour(), 'duration_hours' => 1, 'learning_mode' => 'online',
            'class_type' => 'private', 'hourly_rate' => 50000, 'total_amount' => 50000, 'gross_amount' => 50000,
            'teacher_net_amount' => 40000, 'commission_percent' => 20, 'status' => $status,
            'session_flow_version' => 'presence_confirmation_v2', 'payout_status' => $status === 'completed' ? 'ready' : 'locked',
        ]);
        $request->update(['booking_id' => $booking->id]);
        BookingParticipant::create(['booking_id' => $booking->id, 'student_id' => $student->id, 'booking_request_id' => $request->id, 'order_id' => $order->id, 'amount' => 50000, 'status' => $status === 'completed' ? 'approved' : 'paid']);
        $session = PackageSession::create(['package_subject_id' => $subject->id, 'booking_id' => $booking->id, 'sequence' => $sequence, 'scheduled_start_at' => $start, 'scheduled_end_at' => $start->copy()->addHour(), 'status' => $status]);

        return [$session, $booking];
    }
}
