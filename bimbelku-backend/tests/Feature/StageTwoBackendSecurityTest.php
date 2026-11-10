<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\TeacherOfferReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageTwoBackendSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_update_multipart_compatible_profile_endpoint(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        Sanctum::actingAs($student);

        $this->postJson('/api/user', [
            'name' => 'Murid Baru',
            'phone' => '+62 812-3456-7890',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => 'Murid Baru',
            'phone' => '6281234567890',
        ]);
    }

    public function test_teacher_cannot_bypass_reverification_through_student_profile_endpoint(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
        Sanctum::actingAs($teacher);

        $this->putJson('/api/user', [
            'name' => 'Nama Tanpa Verifikasi',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'id' => $teacher->id,
            'name' => 'Nama Tanpa Verifikasi',
        ]);
    }

    public function test_admin_cannot_apply_the_same_teacher_verification_twice(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'pending',
        ]);
        $paths = [
            'identity_document' => 'teacher_identity/id.jpg',
            'live_selfie' => 'teacher_selfies/selfie.jpg',
            'qualification_document' => 'teacher_qualifications/ijazah.pdf',
        ];
        foreach ($paths as $path) {
            Storage::disk('local')->put($path, 'test');
        }

        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            ...$paths,
            'is_accepting_requests' => false,
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'name' => 'Matematika',
            'levels' => ['SMP'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);
        Sanctum::actingAs($admin);

        $payload = [
            'user_id' => $teacher->id,
            'status' => 'active',
            'notes' => 'Dokumen lengkap dan dapat diverifikasi.',
            'screening_passed' => true,
        ];
        $this->patchJson("/api/admin/teachers/{$teacher->id}/verification-stage", ['stage' => 'documents_checked'])->assertOk();
        $this->patchJson("/api/admin/teachers/{$teacher->id}/verification-stage", ['stage' => 'whatsapp_scheduled'])->assertOk();
        $this->patchJson("/api/admin/teachers/{$teacher->id}/verification-stage", [
            'stage' => 'whatsapp_passed',
            'notes' => 'Tes mengajar WhatsApp dinyatakan lulus.',
        ])->assertOk();

        $this->postJson('/api/admin/verify-teacher', [...$payload, 'screening_passed' => false])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Kelulusan seleksi dan ringkasan hasil tes WhatsApp wajib dicatat sebelum tutor diaktifkan.');
        $this->postJson('/api/admin/verify-teacher', $payload)->assertOk();
        $this->postJson('/api/admin/verify-teacher', $payload)->assertUnprocessable();

        $this->assertDatabaseHas('users', [
            'id' => $teacher->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_authorized_teacher_document_is_served_as_an_inline_preview(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'pending',
        ]);
        $path = 'teacher_selfies/selfie.jpg';
        Storage::disk('local')->put($path, 'fake-image-content');
        TeacherProfile::create([
            'user_id' => $teacher->id,
            'live_selfie' => $path,
            'is_accepting_requests' => false,
        ]);
        Sanctum::actingAs($admin);

        $this->get("/api/teachers/{$teacher->id}/documents/live_selfie")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="selfie.jpg"');
    }

    public function test_retired_admin_payment_account_endpoint_does_not_create_a_record(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/payment-settings')->assertNotFound();
        $this->getJson('/api/admin/payment-settings')->assertNotFound();

        $this->assertDatabaseCount('payment_settings', 0);
    }

    public function test_admin_cannot_complete_a_retired_payment_account_while_an_old_invoice_is_open(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
        PaymentSetting::create([
            'singleton_key' => 1,
            'merchant_name' => 'BimbelKu Official',
            'bank_name' => '',
            'account_number' => '',
            'account_name' => '',
        ]);
        $startAt = now()->addDay()->setTime(18, 0);
        $bookingRequest = BookingRequest::create([
            'student_id' => $student->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 8',
            'chapter' => 'Aljabar',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $startAt->toDateString(),
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'duration_hours' => 1,
            'status' => 'awaiting_payment',
            'hourly_rate' => 40000,
            'total_amount' => 40000,
            'payment_due_at' => now()->addMinutes(30),
        ]);
        $booking = Booking::create([
            'booking_request_id' => $bookingRequest->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addHour(),
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'hourly_rate' => 40000,
            'total_amount' => 40000,
            'status' => 'awaiting_payment',
            'payment_due_at' => now()->addMinutes(30),
            'commission_percent' => 20,
            'payout_status' => 'locked',
        ]);
        Order::create([
            'user_id' => $student->id,
            'booking_id' => $booking->id,
            'order_id' => 'INV-OLD-OPEN',
            'amount' => 40000,
            'status' => 'pending',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/payment-settings', [
            'merchant_name' => 'BimbelKu Official',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => 'BimbelKu',
        ], ['Idempotency-Key' => 'test-payment-setting-001'])->assertNotFound();

        $this->assertDatabaseHas('payment_settings', [
            'singleton_key' => 1,
            'bank_name' => '',
            'account_number' => '',
            'account_name' => '',
        ]);
    }

    public function test_unpaid_group_participant_cannot_report_teacher_absence(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $startAt = now()->subMinutes(30);
        $endAt = now()->addMinutes(30);
        $bookingRequest = BookingRequest::create([
            'student_id' => $student->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 8',
            'chapter' => 'Aljabar',
            'learning_mode' => 'online',
            'class_type' => 'group',
            'scheduled_date' => $startAt->toDateString(),
            'start_time' => $startAt->format('H:i:s'),
            'end_time' => $endAt->format('H:i:s'),
            'duration_hours' => 1,
            'status' => 'confirmed',
            'hourly_rate' => 40000,
            'total_amount' => 40000,
        ]);
        $booking = Booking::create([
            'booking_request_id' => $bookingRequest->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'group',
            'hourly_rate' => 40000,
            'total_amount' => 40000,
            'status' => 'in_progress',
            'commission_percent' => 20,
            'payout_status' => 'locked',
        ]);
        $order = Order::create([
            'user_id' => $student->id,
            'classroom_id' => null,
            'booking_id' => $booking->id,
            'order_id' => 'INV-UNPAID-1',
            'amount' => 40000,
            'status' => 'pending',
        ]);
        BookingParticipant::create([
            'booking_id' => $booking->id,
            'booking_request_id' => $bookingRequest->id,
            'student_id' => $student->id,
            'order_id' => $order->id,
            'amount' => 40000,
            'status' => 'awaiting_payment',
        ]);
        Sanctum::actingAs($student);

        $this->postJson("/api/student/bookings/{$booking->id}/teacher-absence", [
            'chronology' => str_repeat('Tutor belum hadir. ', 3),
        ])->assertNotFound();

        $this->assertDatabaseCount('session_reports', 0);
    }

    public function test_ineligible_teacher_offer_is_released_without_no_response_penalty(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'banned',
        ]);
        $startAt = now()->addDay()->setTime(18, 0);
        $bookingRequest = BookingRequest::create([
            'student_id' => $student->id,
            'matched_teacher_id' => $teacher->id,
            'subject_name' => 'Matematika',
            'education_level' => 'SMP',
            'grade' => 'Kelas 8',
            'chapter' => 'Aljabar',
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $startAt->toDateString(),
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'duration_hours' => 1,
            'status' => 'teacher_pending',
            'hourly_rate' => 40000,
            'total_amount' => 40000,
            'search_started_at' => now(),
            'search_expires_at' => now()->addHours(12),
        ]);
        $offer = TeacherOffer::create([
            'booking_request_id' => $bookingRequest->id,
            'teacher_id' => $teacher->id,
            'status' => 'pending',
            'offered_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        app(TeacherOfferReleaseService::class)
            ->releaseForTeacher($teacher->id, 'Akun tutor tidak aktif');

        $this->assertDatabaseHas('teacher_offers', [
            'id' => $offer->id,
            'status' => 'cancelled',
            'no_response_penalty_applied' => false,
        ]);
        $this->assertDatabaseHas('booking_requests', [
            'id' => $bookingRequest->id,
            'status' => 'matching',
            'matched_teacher_id' => null,
        ]);
    }

    public function test_overlapping_offer_is_released_after_teacher_accepts_another_slot(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $startAt = now()->addDay()->setTime(18, 0);

        $requests = collect([0, 1])->map(function (int $index) use ($student, $teacher, $startAt) {
            return BookingRequest::create([
                'student_id' => $student->id,
                'matched_teacher_id' => $teacher->id,
                'subject_name' => 'Matematika',
                'education_level' => 'SMP',
                'grade' => 'Kelas 8',
                'chapter' => 'Aljabar',
                'learning_mode' => 'online',
                'class_type' => 'private',
                'scheduled_date' => $startAt->toDateString(),
                'start_time' => $index === 0 ? '18:00:00' : '18:30:00',
                'end_time' => $index === 0 ? '19:00:00' : '19:30:00',
                'duration_hours' => 1,
                'status' => 'teacher_pending',
                'hourly_rate' => 40000,
                'total_amount' => 40000,
            ]);
        });
        $offers = $requests->map(fn (BookingRequest $bookingRequest) => TeacherOffer::create([
            'booking_request_id' => $bookingRequest->id,
            'teacher_id' => $teacher->id,
            'status' => 'pending',
            'offered_at' => now(),
            'expires_at' => now()->addHour(),
        ]));

        app(TeacherOfferReleaseService::class)->releaseConflictingForTeacher(
            $teacher->id,
            $startAt,
            $startAt->copy()->addHour(),
            $offers[0]->id,
            'Jadwal bentrok setelah tutor menerima sesi lain'
        );

        $this->assertDatabaseHas('teacher_offers', [
            'id' => $offers[0]->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('teacher_offers', [
            'id' => $offers[1]->id,
            'status' => 'cancelled',
            'no_response_penalty_applied' => false,
        ]);
        $this->assertDatabaseHas('booking_requests', [
            'id' => $requests[1]->id,
            'status' => 'matching',
            'matched_teacher_id' => null,
        ]);
    }
}
