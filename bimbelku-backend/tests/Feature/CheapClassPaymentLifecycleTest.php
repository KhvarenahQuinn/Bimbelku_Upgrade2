<?php

namespace Tests\Feature;

use App\Models\CheapClass;
use App\Models\CheapClassEnrollment;
use App\Models\CheapClassSession;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Refund;
use App\Models\User;
use App\Services\CheapClassService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CheapClassPaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exact_seat_deadline_rejects_payment_and_expires_the_invoice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeClass();
        [$enrollment, $order] = $this->makeEnrollmentAndOrder(
            $class,
            $student,
            'seat_held',
            'pending',
            now()
        );
        $service = app(CheapClassService::class);

        try {
            $service->submitPayment($order, $this->paymentDetails(), 'payment_proofs/exact-deadline.png');
            $this->fail('Pembayaran pada detik kedaluwarsa seharusnya ditolak.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('Batas pembayaran sudah berakhir.', $exception->getMessage());
        }

        $service->refreshLifecycle(false);

        $this->assertSame('payment_expired', $enrollment->fresh()->status);
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertNull($order->fresh()->payment_proof);
    }

    public function test_gateway_checkout_rejects_exact_seat_deadline_and_manual_payment_is_retired(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        Storage::fake('local');
        PaymentSetting::create([
            'singleton_key' => 1,
            'merchant_name' => 'BimbelKu Official',
            'bank_name' => 'Bank Uji',
            'account_number' => '1234567890',
            'account_name' => 'BimbelKu',
        ]);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeClass();
        [$enrollment, $order] = $this->makeEnrollmentAndOrder(
            $class,
            $student,
            'seat_held',
            'pending',
            now()
        );

        Sanctum::actingAs($student);
        $this->post("/api/orders/{$order->id}/pay", [
            'file' => UploadedFile::fake()->create('bukti.png', 100, 'image/png'),
            ...$this->paymentDetails(),
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'cheap-class-exact-deadline-0001',
        ])
            ->assertStatus(410)
            ->assertJsonPath('error_code', 'manual_payment_retired');

        $this->postJson("/api/orders/{$order->id}/xendit-session", [], [
            'Idempotency-Key' => 'cheap-class-exact-deadline-gateway-0001',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Batas pembayaran kursi sudah berakhir.');

        $this->assertSame('seat_held', $enrollment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        Storage::disk('local')->assertDirectoryEmpty('payment_proofs');
    }

    public function test_exact_registration_deadline_blocks_student_cancellation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeClass(['registration_deadline' => now()]);
        [$enrollment, $order] = $this->makeEnrollmentAndOrder(
            $class,
            $student,
            'seat_held',
            'pending',
            now()->addHour()
        );

        try {
            app(CheapClassService::class)->cancelEnrollment($enrollment, $student);
            $this->fail('Pembatalan pada detik penutupan seharusnya ditolak.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('Pendaftaran sudah ditutup sehingga pembatalan tidak tersedia.', $exception->getMessage());
        }

        $this->assertSame('seat_held', $enrollment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_missing_teacher_at_deadline_cancels_package_but_keeps_submitted_proof_for_review(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeClass([
            'teacher_id' => null,
            'status' => 'waiting_teacher',
            'registration_deadline' => now()->subSecond(),
        ]);
        $this->makeSession($class);
        [$enrollment, $order] = $this->makeEnrollmentAndOrder(
            $class,
            $student,
            'payment_submitted',
            'submitted',
            now()->subSecond()
        );
        $order->update([
            'payment_proof' => 'payment_proofs/waiting-teacher.png',
            'payment_submitted_at' => now()->subMinute(),
        ]);

        app(CheapClassService::class)->finalizeIfReady($class);

        $this->assertSame('cancelled', $class->fresh()->status);
        $this->assertSame('Tutor tidak tersedia sampai pendaftaran berakhir.', $class->fresh()->cancellation_reason);
        $this->assertSame('cancellation_pending', $enrollment->fresh()->status);
        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('payment_proofs/waiting-teacher.png', $order->fresh()->payment_proof);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_payment_verified_after_first_session_never_confirms_the_package_and_refunds_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 10:05:00', 'Asia/Jakarta'));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $class = $this->makeClass([
            'status' => 'awaiting_verification',
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addMinutes(55),
            'registration_deadline' => now()->subDay(),
            'minimum_participants' => 1,
        ]);
        $this->makeSession($class, now()->subMinutes(5));
        [$enrollment, $order] = $this->makeEnrollmentAndOrder(
            $class,
            $student,
            'payment_submitted',
            'submitted',
            now()->subDay()
        );
        $order->update([
            'payment_proof' => 'payment_proofs/late-verification.png',
            'payment_submitted_at' => now()->subDay(),
        ]);

        $result = app(CheapClassService::class)->verifyPayment($order, 'paid', '', $admin);

        $this->assertSame(
            'Pembayaran valid, tetapi sesi pertama sudah dimulai. Refund penuh masuk antrean admin.',
            $result['message']
        );
        $this->assertSame('cancelled', $class->fresh()->status);
        $this->assertSame('refund_pending', $enrollment->fresh()->status);
        $this->assertSame('refund_pending', $order->fresh()->status);
        $this->assertNull($enrollment->fresh()->confirmed_at);
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());

        try {
            app(CheapClassService::class)->verifyPayment($order->fresh(), 'paid', '', $admin);
            $this->fail('Verifikasi ulang seharusnya ditolak.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
    }

    private function makeClass(array $overrides = []): CheapClass
    {
        $startsAt = now()->addDays(2)->setTime(10, 0);

        return CheapClass::create(array_merge([
            'subject_name' => 'Matematika',
            'education_level' => 'SD',
            'grade' => 'Kelas 6',
            'chapter' => 'Pecahan',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'session_count' => 1,
            'registration_opens_at' => now()->subDay(),
            'registration_deadline' => now()->addDay(),
            'price_per_session' => 25000,
            'price_per_student' => 25000,
            'minimum_participants' => 2,
            'maximum_participants' => 6,
            'payment_window_minutes' => 60,
            'status' => 'open',
        ], $overrides));
    }

    private function makeSession(CheapClass $class, ?Carbon $startsAt = null): CheapClassSession
    {
        $start = $startsAt ?? $class->starts_at;

        return CheapClassSession::create([
            'cheap_class_id' => $class->id,
            'session_number' => 1,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'status' => 'scheduled',
        ]);
    }

    /** @return array{0:CheapClassEnrollment,1:Order} */
    private function makeEnrollmentAndOrder(
        CheapClass $class,
        User $student,
        string $enrollmentStatus,
        string $orderStatus,
        Carbon $seatExpiresAt
    ): array {
        $enrollment = CheapClassEnrollment::create([
            'cheap_class_id' => $class->id,
            'student_id' => $student->id,
            'amount' => 25000,
            'status' => $enrollmentStatus,
            'seat_expires_at' => $seatExpiresAt,
            'payment_submitted_at' => $enrollmentStatus === 'payment_submitted' ? now() : null,
        ]);
        $order = Order::create([
            'user_id' => $student->id,
            'cheap_class_enrollment_id' => $enrollment->id,
            'amount' => 25000,
            'subtotal_amount' => 25000,
            'discount_amount' => 0,
            'status' => $orderStatus,
            'class_details_snapshot' => [
                'kind' => 'cheap_class',
                'package_code' => $class->package_code,
                'start_at' => $class->starts_at->toIso8601String(),
            ],
        ]);

        return [$enrollment, $order];
    }

    /** @return array{sender_name:string,bank_name:string,sender_account_number:string} */
    private function paymentDetails(): array
    {
        return [
            'sender_name' => 'Murid Uji',
            'bank_name' => 'Bank Uji',
            'sender_account_number' => '1234567890',
        ];
    }
}
