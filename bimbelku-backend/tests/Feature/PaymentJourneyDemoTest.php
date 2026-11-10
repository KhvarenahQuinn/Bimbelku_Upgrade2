<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CheapClass;
use App\Models\CheapClassSession;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\LearningPackage;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\PaymentGatewayEvent;
use App\Models\Refund;
use App\Models\TeacherAvailability;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\TeacherReplacementRequest;
use App\Models\TeacherSubject;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CurriculumCatalogSeeder;
use Database\Seeders\StageFiveExperienceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Demo otomatis: memakai endpoint aplikasi dan respons Xendit sandbox tiruan, tanpa transaksi uang. */
class PaymentJourneyDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-19 09:00:00', 'Asia/Jakarta'));
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_demo_only');
        config()->set('xendit.webhook_token', 'demo-callback-token');
        config()->set('xendit.base_url', 'https://api.xendit.test');
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(StageFiveExperienceSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_private_alone_and_with_friend_complete_checkout_webhook_and_duplicate_delivery_safely(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $tiers = collect(range(2, 8))->mapWithKeys(fn (int $count) => [(string) $count => [
            'discount_percent' => 8,
        ]])->all();
        $this->putJson('/api/admin/private-participant-pricing', ['tiers' => $tiers])->assertOk();
        auth()->forgetGuards();
        $this->fakeSession();
        $teacher = $this->createPrivateDemoTeacher();

        foreach ([1, 2] as $participants) {
            Carbon::setTestNow(Carbon::parse('2026-09-19 09:00:00', 'Asia/Jakarta'));
            $email = "demo-privat-{$participants}@example.test";
            $order = $this->createGuestPrivateOrder($email, $participants);
            $student = $order->user;
            $sessionId = "ps-demo-order-{$order->id}";

            Sanctum::actingAs($student);
            $first = $this->postJson("/api/orders/{$order->id}/xendit-session", [], [
                'Idempotency-Key' => "demo-private-{$participants}",
            ]);
            $this->assertSame(200, $first->status(), $first->getContent());
            $first->assertJsonPath('data.session_id', $sessionId);
            $this->postJson("/api/orders/{$order->id}/xendit-session", [], [
                'Idempotency-Key' => "demo-private-{$participants}-retry",
            ])->assertOk()->assertJsonPath('data.checkout_url', $first->json('data.checkout_url'));
            Http::assertSentCount($participants);

            $this->completeGatewayPayment($order, $sessionId, "private-{$participants}");
            $this->assertSame('paid', $order->fresh()->status);
            $this->assertNotSame('awaiting_payment', $order->learningPackage->fresh()->status);
            $this->assertSame($participants, (int) $order->learningPackage->fresh()->participant_count);
            $this->assertDatabaseCount('payment_gateway_events', $participants);

            $package = $order->learningPackage->fresh();
            $offer = TeacherOffer::query()->where('teacher_id', $teacher->id)->where('status', 'pending')
                ->whereHas('bookingRequest.packageSubject', fn ($query) => $query->where('learning_package_id', $package->id))
                ->firstOrFail();
            Sanctum::actingAs($teacher);
            $this->postJson("/api/teacher/offers/{$offer->id}/accept")->assertOk();
            $booking = Booking::query()->where('teacher_id', $teacher->id)
                ->whereHas('bookingRequest.packageSubject', fn ($query) => $query->where('learning_package_id', $package->id))
                ->firstOrFail();
            $this->finishPrivateSession($booking, $student, $teacher);
            $this->assertSame('completed', $package->fresh()->status);
            $this->assertSame('ready', $booking->fresh()->payout_status);
        }
    }

    public function test_shared_class_join_checkout_and_webhook_confirm_the_seat(): void
    {
        $this->artisan('demo:cheap-class', ['stage' => 'pre-payment'])->assertSuccessful();
        $class = CheapClass::query()->where('package_code', 'like', 'DEMO-KM-PREPAY-%')->firstOrFail();
        $student = User::query()->where('email', 'demo.student@bimbelku.local')->firstOrFail();
        $this->fakeSession();

        Sanctum::actingAs($student);
        $this->postJson("/api/student/cheap-classes/{$class->id}/join", [], [
            'Idempotency-Key' => 'demo-gateway-shared-join',
        ])->assertCreated()->assertJsonPath('enrollment_status', 'seat_held');
        $enrollment = $class->enrollments()->where('student_id', $student->id)->with('order')->firstOrFail();
        $order = $enrollment->order;
        $sessionId = "ps-demo-order-{$order->id}";

        $this->postJson("/api/orders/{$order->id}/xendit-session", [], [
            'Idempotency-Key' => 'demo-gateway-shared-session',
        ])->assertOk()->assertJsonPath('data.session_id', $sessionId);
        $this->completeGatewayPayment($order, $sessionId, 'shared-class');

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('confirmed', $enrollment->fresh()->status);
        $this->assertSame('confirmed', $class->fresh()->status);

        $session = CheapClassSession::query()->where('cheap_class_id', $class->id)->firstOrFail();
        $teacher = User::query()->where('email', 'demo.tutor@bimbelku.local')->firstOrFail();
        $admin = User::query()->where('email', 'demo.admin@bimbelku.local')->firstOrFail();
        $this->artisan('demo:journey-time', ['order' => $order->id, 'stage' => 'start'])
            ->assertSuccessful();
        $this->artisan('demo:journey-time', ['order' => $order->id, 'stage' => 'end'])
            ->assertFailed();
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/cheap-classes/{$class->id}/start-session")
            ->assertOk()->assertJsonPath('session.status', 'in_progress');
        $this->artisan('demo:journey-time', ['order' => $order->id, 'stage' => 'end'])
            ->assertSuccessful();
        $this->putJson("/api/teacher/cheap-classes/{$class->id}/progress", [
            'session_id' => $session->id,
            'attended_participants_count' => 2,
            'session_notes' => 'Dua peserta mengikuti latihan aljabar sampai sesi berakhir.',
            'updates' => [[
                'subject_index' => 0,
                'progress_status' => 'completed',
                'needs_review' => false,
                'progress_notes' => 'Tujuan aljabar dasar tercapai.',
            ]],
        ])->assertOk()->assertJsonPath('session.status', 'awaiting_admin_verification');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/cheap-classes/{$class->id}/sessions/{$session->id}/verify", [
            'notes' => 'Kehadiran dan laporan terverifikasi.',
        ], ['Idempotency-Key' => 'demo-gateway-group-verify'])->assertOk();
        $this->assertSame('completed', $class->fresh()->status);
        $this->assertSame('completed', $session->fresh()->status);
        Sanctum::actingAs($student);
        $this->getJson("/api/student/cheap-classes/{$class->id}?scope=progress")
            ->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_localhost_status_sync_settles_private_package_when_webhook_cannot_reach_localhost(): void
    {
        $order = $this->createGuestPrivateOrder('demo-sync@example.test', 1);
        $student = $order->user;
        $sessionId = "ps-demo-order-{$order->id}";
        $this->fakeSession();
        Sanctum::actingAs($student);
        $this->postJson("/api/orders/{$order->id}/xendit-session", [], [
            'Idempotency-Key' => 'demo-sync-session',
        ])->assertOk();

        Http::fake(['https://api.xendit.test/sessions/'.$sessionId => Http::response([
            'payment_session_id' => $sessionId,
            'reference_id' => $order->fresh()->gateway_reference_id,
            'payment_id' => 'py-demo-sync',
            'payment_request_id' => 'pr-demo-sync',
            'status' => 'COMPLETED',
            'amount' => (int) $order->amount,
        ])]);
        $this->postJson("/api/orders/{$order->id}/payment-status-sync")
            ->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson("/api/orders/{$order->id}/payment-status-sync")
            ->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertNotSame('awaiting_payment', $order->learningPackage->fresh()->status);
    }

    public function test_wrong_amount_or_missing_webhook_token_never_marks_private_order_paid(): void
    {
        $order = $this->createGuestPrivateOrder('demo-rejected@example.test', 1);
        $sessionId = "ps-demo-order-{$order->id}";
        $this->fakeSession();
        Sanctum::actingAs($order->user);
        $this->postJson("/api/orders/{$order->id}/xendit-session", [], [
            'Idempotency-Key' => 'demo-rejected-session',
        ])->assertOk();

        $payload = [
            'id' => 'evt-demo-wrong-amount',
            'event' => 'payment_session.completed',
            'data' => [
                'payment_session_id' => $sessionId,
                'status' => 'COMPLETED',
                'amount' => (int) $order->amount - 1,
            ],
        ];
        $this->postJson('/api/webhooks/xendit', $payload)->assertUnauthorized();
        $this->postJson('/api/webhooks/xendit', $payload, [
            'x-callback-token' => 'demo-callback-token',
        ])->assertAccepted();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('failed', PaymentGatewayEvent::query()
            ->where('event_id', 'xendit:evt-demo-wrong-amount')->value('status'));
    }

    public function test_package_renewal_uses_new_gateway_payment_then_offers_the_existing_teacher(): void
    {
        $this->artisan('demo:package-renewal', ['stage' => 'setup'])->assertSuccessful();
        $student = User::query()->where('email', 'demo.student@bimbelku.local')->firstOrFail();
        $teacher = User::query()->where('email', 'demo.tutor@bimbelku.local')->firstOrFail();
        $source = LearningPackage::query()
            ->where('student_id', $student->id)
            ->where('package_code', 'like', 'DEMO-RENEW-SOURCE-%')
            ->latest('id')->firstOrFail();
        $subject = $source->subjects()->firstOrFail();
        $plan = PackagePlan::query()->where('is_active', true)->where('session_count', 4)->firstOrFail();
        $this->fakeSession();

        Sanctum::actingAs($student);
        $response = $this->postJson('/api/student/packages', [
            'package_plan_id' => $plan->id,
            'renewal_of_id' => $source->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'subjects' => [[
                'curriculum_subject_id' => $subject->curriculum_subject_id,
                'curriculum_chapter_ids' => [$subject->curriculum_chapter_id],
                'preferred_teacher_id' => $teacher->id,
                'learning_goal' => 'Lanjutkan paket demo dengan tutor sebelumnya.',
                'weekdays' => [1, 3],
                'schedules' => [
                    '2026-09-21 18:00:00', '2026-09-23 18:00:00',
                    '2026-09-28 18:00:00', '2026-09-30 18:00:00',
                ],
            ]],
        ], ['Idempotency-Key' => 'demo-gateway-renewal-create'])->assertCreated();
        $renewalId = (int) $response->json('data.id');
        $order = Order::query()->where('learning_package_id', $renewalId)->firstOrFail();
        $sessionId = "ps-demo-order-{$order->id}";

        $this->postJson("/api/orders/{$order->id}/xendit-session", [], [
            'Idempotency-Key' => 'demo-gateway-renewal-session',
        ])->assertOk()->assertJsonPath('data.session_id', $sessionId);
        $this->completeGatewayPayment($order, $sessionId, 'renewal');

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame($source->id, $order->learningPackage->fresh()->renewal_of_id);
        $this->assertDatabaseHas('orders', [
            'learning_package_id' => $source->id,
            'status' => 'paid',
        ]);
        $this->getJson('/api/orders')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.renewal_of_id', $source->id);
        $offer = TeacherOffer::query()->where('teacher_id', $teacher->id)
            ->whereHas('bookingRequest.packageSubject', fn ($query) => $query->where('learning_package_id', $renewalId))
            ->firstOrFail();
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/offers/{$offer->id}/accept")->assertOk();
        $renewal = LearningPackage::query()->findOrFail($renewalId);
        $bookings = $renewal->subjects()->firstOrFail()->sessions()
            ->orderBy('sequence')->get()->map(fn ($session) => $session->booking()->firstOrFail());
        $this->assertCount(4, $bookings);
        foreach ($bookings as $booking) {
            $this->finishPrivateSession($booking, $student, $teacher);
        }
        $this->assertSame('completed', $renewal->fresh()->status);
        $this->assertSame(4, (int) $renewal->fresh()->used_sessions);
    }

    public function test_teacher_replacement_keeps_original_gateway_payment_without_a_second_charge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00', 'Asia/Jakarta'));
        config()->set('features.teacher_replacement', true);
        $this->artisan('demo:teacher-replacement', ['stage' => 'setup'])->assertSuccessful();
        $student = User::query()->where('email', 'demo.replacement.student@bimbelku.local')->firstOrFail();
        $admin = User::query()->where('role', 'admin')->where('status', 'active')->firstOrFail();
        $newTeacher = User::query()->where('email', 'demo.replacement.new@bimbelku.local')->firstOrFail();
        $package = LearningPackage::query()->where('package_code', 'like', 'DEMO-GGR-%')->latest('id')->firstOrFail();
        $subject = $package->subjects()->firstOrFail();
        $order = $package->orders()->firstOrFail();
        $order->forceFill([
            'payment_provider' => 'xendit',
            'gateway_payment_id' => 'py-demo-replacement-original',
            'gateway_payment_request_id' => 'pr-demo-replacement-original',
            'external_received_amount' => $order->amount,
        ])->save();
        $orderCount = Order::query()->count();
        Http::fake();

        Sanctum::actingAs($student);
        $replacementId = (int) $this->postJson(
            "/api/student/packages/{$package->id}/subjects/{$subject->id}/teacher-replacements",
            ['reason_code' => 'learning_fit', 'reason_detail' => 'Demo ganti tutor tanpa bayar ulang untuk sesi tersisa.'],
            ['Idempotency-Key' => 'demo-gateway-replacement-submit'],
        )->assertCreated()->json('data.id');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/teacher-replacements/{$replacementId}/approve", [
            'notes' => 'Cari tutor pengganti untuk demo pembayaran yang sudah lunas.',
        ], ['Idempotency-Key' => 'demo-gateway-replacement-approve'])->assertOk();
        $offer = TeacherOffer::query()->where('teacher_id', $newTeacher->id)->where('status', 'pending')->firstOrFail();
        Sanctum::actingAs($newTeacher);
        $this->postJson("/api/teacher/offers/{$offer->id}/accept")->assertOk();

        $this->assertSame('completed', TeacherReplacementRequest::findOrFail($replacementId)->status);
        $this->assertSame($newTeacher->id, $subject->fresh()->assigned_teacher_id);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame($orderCount, Order::query()->count());

        $remainingBookings = $subject->sessions()->where('status', '!=', 'completed')
            ->orderBy('sequence')->get()->map(fn ($session) => $session->booking()->firstOrFail());
        $this->assertCount(2, $remainingBookings);
        foreach ($remainingBookings as $booking) {
            $this->assertSame($newTeacher->id, $booking->teacher_id);
            $this->finishPrivateSession($booking, $student, $newTeacher);
        }
        $this->assertSame('completed', $package->fresh()->status);
        $this->assertSame(3, (int) $package->fresh()->used_sessions);
        $this->assertSame($orderCount, Order::query()->count());
        Http::assertNothingSent();
    }

    public function test_no_replacement_teacher_refunds_remaining_sessions_to_original_gateway_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00', 'Asia/Jakarta'));
        config()->set('features.teacher_replacement', true);
        $this->artisan('demo:teacher-replacement', ['stage' => 'setup'])->assertSuccessful();
        $student = User::query()->where('email', 'demo.replacement.student@bimbelku.local')->firstOrFail();
        $admin = User::query()->where('role', 'admin')->where('status', 'active')->firstOrFail();
        $package = LearningPackage::query()->where('package_code', 'like', 'DEMO-GGR-%')->latest('id')->firstOrFail();
        $subject = $package->subjects()->firstOrFail();
        $order = $package->orders()->firstOrFail();
        $order->forceFill([
            'payment_provider' => 'xendit',
            'gateway_payment_id' => 'py-demo-refund-original',
            'gateway_payment_request_id' => 'pr-demo-refund-original',
            'external_received_amount' => $order->amount,
        ])->save();
        Http::fake(['https://api.xendit.test/refunds' => Http::response([
            'id' => 'rfd-demo-replacement', 'status' => 'SUCCEEDED',
        ])]);

        Sanctum::actingAs($student);
        $replacementId = (int) $this->postJson(
            "/api/student/packages/{$package->id}/subjects/{$subject->id}/teacher-replacements",
            ['reason_code' => 'teacher_unavailable', 'reason_detail' => 'Demo pengganti tidak tersedia; kembalikan sesi tersisa.'],
            ['Idempotency-Key' => 'demo-gateway-refund-submit'],
        )->assertCreated()->json('data.id');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/teacher-replacements/{$replacementId}/approve", [
            'notes' => 'Uji pencarian pengganti dan refund otomatis.',
        ], ['Idempotency-Key' => 'demo-gateway-refund-approve'])->assertOk();
        $this->artisan('demo:teacher-replacement', ['stage' => 'no-teacher'])->assertSuccessful();

        Sanctum::actingAs($student);
        $this->postJson("/api/student/teacher-replacements/{$replacementId}/request-refund", [], [
            'Idempotency-Key' => 'demo-gateway-refund-request',
        ])->assertCreated();
        $refund = Refund::query()->where('source_type', 'teacher_replacement')->where('source_id', $replacementId)->firstOrFail();
        $this->assertSame('pending', $refund->status);
        $this->assertNull($refund->destination_selected_at);
        Http::assertNothingSent();
        $this->postJson("/api/student/refunds/{$refund->id}/destination", [
            'destination_method' => 'xendit_original',
        ], ['Idempotency-Key' => 'demo-gateway-refund-destination'])->assertOk();
        $refund->refresh();
        $this->assertSame('paid', $refund->status);
        $this->assertSame('xendit_original', $refund->destination_method);
        $this->assertSame('rfd-demo-replacement', $refund->gateway_refund_id);
        $this->assertSame('refunded', TeacherReplacementRequest::findOrFail($replacementId)->status);
        $this->assertSame('paid', $order->fresh()->status);
        Http::assertSentCount(1);
    }

    private function createGuestPrivateOrder(string $email, int $participants): Order
    {
        $plan = PackagePlan::query()->where('slug', 'coba-belajar')->firstOrFail();
        $subject = CurriculumSubject::query()->where('normalized_name', 'matematika')->firstOrFail();
        $chapter = CurriculumChapter::query()
            ->where('curriculum_subject_id', $subject->id)
            ->where('education_level', 'SMP')
            ->where('grade', 'Kelas 7')
            ->where('is_active', true)
            ->firstOrFail();

        $payload = [
            'name' => $participants === 1 ? 'Demo Privat Sendiri' : 'Demo Privat Bersama Teman',
            'email' => $email,
            'phone' => '081234567890',
            'package_plan_id' => $plan->id,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'learning_mode' => 'online',
            'duration_hours' => 1,
            'participant_count' => $participants,
            'subjects' => [[
                'curriculum_subject_id' => $subject->id,
                'curriculum_chapter_ids' => [$chapter->id],
                'learning_goal' => 'Demo pembayaran end-to-end.',
                'weekdays' => [$participants === 1 ? 1 : 3],
                'schedules' => [$participants === 1 ? '2026-09-21 15:00:00' : '2026-09-23 15:00:00'],
            ]],
        ];
        if ($participants > 1) {
            $payload['purchaser_participates'] = false;
            $payload['participant_details'] = [
                ['full_name' => 'Peserta Satu', 'nickname' => 'Satu', 'birth_date' => '2013-05-10', 'gender' => 'female'],
                ['full_name' => 'Peserta Dua', 'nickname' => 'Dua', 'birth_date' => '2014-08-12', 'gender' => 'male'],
            ];
        }

        $code = $this->postJson('/api/guest/packages', $payload)->assertCreated()->json('code');
        $student = User::factory()->create([
            'role' => 'student', 'status' => 'active', 'email' => $email, 'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($student);
        $packageId = $this->postJson("/api/guest/packages/{$code}/claim")
            ->assertOk()->json('data.id');

        return Order::query()->where('learning_package_id', $packageId)->firstOrFail()->load('user', 'learningPackage');
    }

    private function createPrivateDemoTeacher(): User
    {
        $subject = CurriculumSubject::query()->where('normalized_name', 'matematika')->firstOrFail();
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'email_verified_at' => now()]);
        $profile = TeacherProfile::query()->create([
            'user_id' => $teacher->id,
            'expertise' => 'Matematika',
            'teaching_method' => 'Online',
            'verified_at' => now(),
            'is_accepting_requests' => true,
        ]);
        TeacherSubject::query()->create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $subject->id,
            'name' => 'Matematika',
            'levels' => ['SMP'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);
        foreach (['Senin', 'Rabu'] as $day) {
            TeacherAvailability::query()->create([
                'user_id' => $teacher->id,
                'day' => $day,
                'slots' => [['start_time' => '08:00', 'end_time' => '23:00']],
                'is_active' => true,
            ]);
        }

        return $teacher;
    }

    private function finishPrivateSession(Booking $booking, User $student, User $teacher): void
    {
        // Online classes require their meeting link before the tutor can declare readiness.
        $booking->update(['meeting_link' => 'https://zoom.us/j/12345678901']);
        $chapter = $booking->packageSession?->subject?->chapters()->firstOrFail();
        $this->artisan('demo:journey-time', [
            'order' => $booking->order_id, 'stage' => 'start', '--session' => $booking->packageSession?->id,
        ])->assertSuccessful();
        $this->artisan('demo:journey-time', [
            'order' => $booking->order_id, 'stage' => 'end', '--session' => $booking->packageSession?->id,
        ])->assertFailed();
        Sanctum::actingAs($teacher);
        $ready = $this->postJson("/api/teacher/bookings/{$booking->id}/ready", [
            'focus_note' => 'Bahas aljabar dan latihan terarah.',
        ]);
        $this->assertSame(200, $ready->status(), $ready->getContent());
        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/presence-confirm")->assertOk();

        $this->artisan('demo:journey-time', [
            'order' => $booking->order_id, 'stage' => 'end', '--session' => $booking->packageSession?->id,
        ])->assertSuccessful();
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/check-out")->assertOk();
        $this->postJson("/api/teacher/bookings/{$booking->id}/progress-reports", [
            'material_covered' => 'Materi aljabar sesuai paket.',
            'mastered_skills' => 'Peserta memahami latihan aljabar dan dapat menjelaskan langkahnya.',
            'next_exercise' => 'Latihan soal mandiri setelah kelas.',
            'progress_percent' => 100,
            'chapter_updates' => [[
                'chapter' => $chapter->title,
                'activity_type' => 'taught',
                'status_after' => 'completed',
                'needs_review' => false,
            ]],
        ])->assertCreated();

        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/approve")->assertOk();
        $this->assertSame('completed', $booking->fresh()->status);
        Sanctum::actingAs($teacher);
        $this->assertGreaterThan(0, (float) $this->getJson('/api/teacher/dashboard-v2')
            ->assertOk()->json('earnings.available'));
    }

    private function fakeSession(): void
    {
        Http::fake(['https://api.xendit.test/sessions' => function (GatewayRequest $request) {
            $sessionId = 'ps-demo-order-'.$request['metadata']['order_id'];

            return Http::response([
                'payment_session_id' => $sessionId,
                'payment_link_url' => "https://checkout-staging.xendit.test/{$sessionId}",
                'status' => 'ACTIVE',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]);
        }]);
    }

    private function completeGatewayPayment(Order $order, string $sessionId, string $suffix): void
    {
        $payload = [
            'id' => "evt-demo-{$suffix}",
            'event' => 'payment_session.completed',
            'data' => [
                'payment_session_id' => $sessionId,
                'reference_id' => $order->fresh()->gateway_reference_id,
                'status' => 'COMPLETED',
                'amount' => (int) $order->amount,
                'payment_id' => "py-demo-{$suffix}",
                'payment_request_id' => "pr-demo-{$suffix}",
            ],
        ];
        $headers = ['x-callback-token' => 'demo-callback-token'];
        $this->postJson('/api/webhooks/xendit', $payload, $headers)->assertOk();
        $this->postJson('/api/webhooks/xendit', $payload, $headers)->assertOk();
        $this->assertSame(1, PaymentGatewayEvent::query()->where('event_id', "xendit:evt-demo-{$suffix}")->count());
    }
}
