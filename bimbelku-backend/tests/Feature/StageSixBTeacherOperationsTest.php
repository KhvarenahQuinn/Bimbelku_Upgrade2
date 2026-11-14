<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\Order;
use App\Models\TeacherAvailability;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\BankAccountNameVerifier;
use App\Services\OneTimeCodeService;
use App\Services\TeacherPayoutService;
use App\Services\XenditWebhookService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageSixBTeacherOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paid_chat_attachment_is_idempotent_and_gets_a_read_receipt(): void
    {
        Storage::fake('local');
        [$student, $teacher, $booking] = $this->makeBooking();
        $clientToken = 'f5bf860e-98f8-4f82-9b7c-5121d8b1f6e2';

        Sanctum::actingAs($student);
        $this->post("/api/bookings/{$booking->id}/messages", [
            'body' => 'Berikut latihan yang ingin dibahas.',
            'attachment' => UploadedFile::fake()->create('latihan.pdf', 40, 'application/pdf'),
            'client_token' => $clientToken,
        ])->assertCreated();
        $this->post("/api/bookings/{$booking->id}/messages", [
            'body' => 'Berikut latihan yang ingin dibahas.',
            'attachment' => UploadedFile::fake()->create('latihan.pdf', 40, 'application/pdf'),
            'client_token' => $clientToken,
        ])->assertOk();
        $this->assertDatabaseCount('classroom_messages', 1);

        Sanctum::actingAs($teacher);
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);
        $this->getJson("/api/bookings/{$booking->id}/learning-session")
            ->assertOk();
        $this->assertDatabaseHas('classroom_message_reads', [
            'user_id' => $teacher->id,
        ]);
    }

    public function test_schedule_changes_only_after_the_other_party_approves(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 08:00:00', 'Asia/Jakarta'));
        [$student, $teacher, $booking] = $this->makeBooking(
            Carbon::parse('2026-08-02 10:00:00', 'Asia/Jakarta'),
            Carbon::parse('2026-08-02 11:00:00', 'Asia/Jakarta')
        );
        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => 'Senin',
            'is_active' => true,
            'start_time' => '08:00:00',
            'end_time' => '20:00:00',
        ]);

        Sanctum::actingAs($student);
        $changeId = $this->postJson("/api/bookings/{$booking->id}/schedule-changes", [
            'proposed_start_at' => '2026-08-03 14:00:00',
            'reason' => 'Ada kegiatan sekolah wajib pada jadwal lama dan waktunya tidak dapat dipindahkan.',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'start_at' => '2026-08-02 10:00:00',
        ]);

        Sanctum::actingAs($teacher);
        $this->postJson("/api/bookings/{$booking->id}/schedule-changes/{$changeId}/respond", [
            'decision' => 'approved',
        ])->assertOk();
        $this->assertDatabaseHas('schedule_change_requests', [
            'id' => $changeId,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'start_at' => '2026-08-03 14:00:00',
            'end_at' => '2026-08-03 15:00:00',
        ]);
    }

    public function test_teacher_payout_request_is_sent_automatically_and_reserves_only_requested_amount(): void
    {
        app()->instance(BankAccountNameVerifier::class, new class extends BankAccountNameVerifier
        {
            public function available(): bool
            {
                return true;
            }

            public function matches(string $channelCode, string $accountNumber, string $expectedName): bool
            {
                return true;
            }
        });
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_test');
        config()->set('xendit.webhook_token', 'callback-test');
        config()->set('xendit.base_url', 'https://api.xendit.test');
        Http::fake(['https://api.xendit.test/v3/payouts' => Http::response([
            'payout_id' => 'po-stage6b-1', 'status' => 'ACCEPTED',
        ])]);

        [$student, $teacher, $booking] = $this->makeBooking();
        $teacher->update(['address' => 'Yogyakarta']);
        $booking->update([
            'status' => 'completed', 'completed_at' => now(), 'gross_amount' => 100000,
            'teacher_net_amount' => 80000, 'payout_status' => 'ready',
        ]);
        TeacherProfile::create([
            'user_id' => $teacher->id, 'bank_name' => 'Bank Central Asia (BCA)',
            'payout_channel_code' => 'BCA', 'account_number' => '1234567890',
            'account_name' => $teacher->name, 'bank_details_version' => 1, 'location' => 'Yogyakarta',
        ]);

        Sanctum::actingAs($teacher);
        $this->getJson('/api/teacher/salary')
            ->assertOk()->assertJsonPath('balances.available', 80000);

        $this->postJson('/api/teacher/payout-requests', [
            'amount' => 30000,
            'verification' => ['otp' => $this->issuePayoutOtp($teacher)],
        ], [
            'Idempotency-Key' => 'stage6b-payout-request-0001',
        ])->assertStatus(202)
            ->assertJsonPath('data.requested_amount', 30000)
            ->assertJsonPath('data.transfer_amount', 30000);

        $this->assertDatabaseHas('teacher_payout_requests', [
            'teacher_id' => $teacher->id, 'requested_amount' => 30000,
            'net_amount' => 30000, 'status' => 'processing',
        ]);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id, 'teacher_reserved_amount' => 30000,
            'payout_status' => 'ready',
        ]);
        $this->getJson('/api/teacher/salary')
            ->assertOk()->assertJsonPath('balances.available', 50000)
            ->assertJsonPath('balances.requested', 30000);
    }

    public function test_payout_stays_unreserved_until_bank_owner_name_can_be_verified(): void
    {
        config(['xendit.enabled' => true, 'xendit.secret_key' => 'test', 'xendit.webhook_token' => 'test']);
        config(['features.payout_bank_name_stub' => false]);
        [$student, $teacher, $booking] = $this->makeBooking();
        $teacher->update(['address' => 'Yogyakarta']);
        $booking->update([
            'status' => 'completed', 'completed_at' => now(), 'gross_amount' => 100000,
            'teacher_net_amount' => 80000, 'payout_status' => 'ready',
        ]);
        TeacherProfile::create([
            'user_id' => $teacher->id, 'bank_name' => 'BCA', 'payout_channel_code' => 'BCA',
            'account_number' => '1234567890', 'account_name' => $teacher->name, 'location' => 'Yogyakarta',
        ]);
        Sanctum::actingAs($teacher);

        $this->getJson('/api/teacher/salary')->assertOk()
            ->assertJsonPath('bank_name_validation_available', false);
        $this->postJson('/api/teacher/payout-requests', [
            'amount' => 30000,
            'verification' => ['otp' => $this->issuePayoutOtp($teacher)],
        ], [
            'Idempotency-Key' => 'bank-verification-unavailable-1',
        ])->assertStatus(422)->assertJsonPath('message', 'Pencairan belum dapat dikirim: verifikasi nama pemilik rekening langsung dari bank belum tersedia atau belum cocok. Saldo Anda tetap aman.');
        $this->assertDatabaseCount('teacher_payout_requests', 0);
        $this->assertSame(0.0, (float) $booking->fresh()->teacher_reserved_amount);
    }

    public function test_payout_failure_webhook_does_not_release_another_pending_withdrawal(): void
    {
        app()->instance(BankAccountNameVerifier::class, new class extends BankAccountNameVerifier
        {
            public function available(): bool
            {
                return true;
            }

            public function matches(string $channelCode, string $accountNumber, string $expectedName): bool
            {
                return true;
            }
        });
        config(['xendit.enabled' => true, 'xendit.secret_key' => 'test', 'xendit.webhook_token' => 'test', 'xendit.base_url' => 'https://api.xendit.test']);
        Http::fake(['*/v3/payouts' => Http::sequence()
            ->push(['payout_id' => 'po-first', 'status' => 'ACCEPTED'])
            ->push(['payout_id' => 'po-second', 'status' => 'ACCEPTED'])]);
        [$student, $teacher, $booking] = $this->makeBooking();
        $teacher->update(['address' => 'Yogyakarta']);
        $booking->update(['status' => 'completed', 'completed_at' => now(), 'gross_amount' => 100000, 'teacher_net_amount' => 80000, 'payout_status' => 'ready']);
        TeacherProfile::create(['user_id' => $teacher->id, 'bank_name' => 'BCA', 'payout_channel_code' => 'BCA', 'account_number' => '1234567890', 'account_name' => $teacher->name, 'location' => 'Yogyakarta']);
        $service = app(TeacherPayoutService::class);
        $otp = $this->issuePayoutOtp($teacher);
        $first = $service->request($teacher, 30000, null, ['otp' => $otp])['payout'];
        $service->request($teacher, 20000, null, ['otp' => $otp]);
        $webhooks = app(XenditWebhookService::class);
        $data = ['payout_id' => 'po-first', 'status' => 'FAILED'];
        $webhooks->handle('v3_payout.failed', 'first-failure', $data, $data);
        $webhooks->handle('v3_payout.failed', 'duplicate-failure-new-event', $data, $data);
        $this->assertSame(20000.0, (float) $booking->fresh()->teacher_reserved_amount);
        $this->assertSame(60000.0, $service->availableAmount($teacher->id));
        $this->assertSame('failed', $first->fresh()->status);
    }

    private function issuePayoutOtp(User $teacher): string
    {
        $code = '246810';
        \DB::table('verification_codes')->insert([
            'user_id' => $teacher->id,
            'purpose' => OneTimeCodeService::PAYOUT_BANK_CHANGE,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $code;
    }

    private function makeBooking(?Carbon $start = null, ?Carbon $end = null): array
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $start ??= now()->addDay()->startOfHour();
        $end ??= $start->copy()->addHour();
        $bookingRequest = BookingRequest::create([
            'student_id' => $student->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'duration_hours' => 1,
            'status' => 'confirmed',
            'hourly_rate' => 100000,
            'total_amount' => 100000,
        ]);
        $booking = Booking::create([
            'booking_request_id' => $bookingRequest->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'start_at' => $start,
            'end_at' => $end,
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'hourly_rate' => 100000,
            'total_amount' => 100000,
            'status' => 'confirmed',
            'commission_percent' => 20,
            'gross_amount' => 100000,
            'teacher_net_amount' => 80000,
            'payout_status' => 'locked',
        ]);
        $order = Order::create([
            'user_id' => $student->id,
            'booking_id' => $booking->id,
            'order_id' => 'INV-6B-'.$booking->id,
            'amount' => 100000,
            'status' => 'paid',
        ]);
        BookingParticipant::create([
            'booking_id' => $booking->id,
            'booking_request_id' => $bookingRequest->id,
            'student_id' => $student->id,
            'order_id' => $order->id,
            'amount' => 100000,
            'status' => 'paid',
        ]);
        $booking->update(['order_id' => $order->id]);
        $bookingRequest->update(['booking_id' => $booking->id]);

        return [$student, $teacher, $booking->fresh()];
    }
}
