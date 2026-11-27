<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\GuestPackageOrder;
use App\Models\LearningPackage;
use App\Models\Order;
use App\Models\PackagePlan;
use App\Models\TeacherAvailability;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CurriculumCatalogSeeder;
use Database\Seeders\StageFiveExperienceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FreeTextLearningFocusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-19 09:00:00', 'Asia/Jakarta'));
        $this->seed(CurriculumCatalogSeeder::class);
        $this->seed(StageFiveExperienceSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(): array
    {
        return [
            'name' => 'Murid Fokus', 'email' => 'focus@example.test', 'phone' => '081234567890',
            'package_plan_id' => PackagePlan::query()->where('slug', 'coba-belajar')->firstOrFail()->id,
            'education_level' => 'SMP', 'grade' => 'Kelas 7', 'learning_mode' => 'online',
            'duration_hours' => 2,
            'subjects' => [[
                'curriculum_subject_id' => CurriculumSubject::query()->where('normalized_name', 'matematika')->firstOrFail()->id,
                'learning_goal' => "  Pecahan dan soal cerita.\nIngin mengulang dari dasar.  ",
                'weekdays' => [1], 'schedules' => ['2026-09-21 15:00:00'],
            ]],
        ];
    }

    public function test_both_ordering_paths_validate_focus_and_keep_legacy_chapters_valid(): void
    {
        $payload = $this->payload();
        $empty = $payload;
        $empty['subjects'][0]['learning_goal'] = '   ';
        $this->postJson('/api/guest/packages', $empty)->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.0.learning_goal');
        $tooLong = $payload;
        $tooLong['subjects'][0]['learning_goal'] = str_repeat('a', 1501);
        $this->postJson('/api/guest/packages', $tooLong)->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.0.learning_goal');

        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'status' => 'active', 'email_verified_at' => now()]));
        $this->postJson('/api/student/packages', $empty, ['Idempotency-Key' => 'focus-empty-goal'])->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.0.learning_goal');
        $this->postJson('/api/student/packages', $tooLong, ['Idempotency-Key' => 'focus-long-goal'])->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.0.learning_goal');
        $direct = $this->postJson('/api/student/packages', $payload, ['Idempotency-Key' => 'focus-direct-order'])->assertCreated();
        $this->assertDatabaseHas('package_subjects', [
            'learning_package_id' => $direct->json('data.id'),
            'learning_goal' => trim($payload['subjects'][0]['learning_goal']),
        ]);
        $this->assertDatabaseCount('package_chapters', 0);

        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'status' => 'active', 'email_verified_at' => now()]));
        $legacy = $empty;
        $chapter = CurriculumChapter::query()->where('curriculum_subject_id', $payload['subjects'][0]['curriculum_subject_id'])
            ->where('education_level', 'SMP')->where('grade', 'Kelas 7')->where('is_active', true)->firstOrFail();
        $legacy['subjects'][0]['curriculum_chapter_ids'] = [$chapter->id];
        $this->postJson('/api/student/packages', $legacy, ['Idempotency-Key' => 'focus-legacy-order'])->assertCreated();
        $this->assertDatabaseCount('package_chapters', 1);

        $chapter->update(['is_active' => false]);
        $this->postJson('/api/student/packages', $legacy, ['Idempotency-Key' => 'focus-inactive-chapter'])->assertUnprocessable();
    }

    public function test_admin_controls_chapter_visibility_without_deleting_existing_chapters(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $id = $this->postJson('/api/admin/subjects', [
            'name' => 'Mapel Fokus Baru', 'group_name' => 'Wajib',
            'education_levels' => ['SMP'], 'grades' => ['Kelas 7'],
            'show_chapter_selection' => false,
        ])->assertCreated()->json('data.id');
        $this->assertFalse(CurriculumSubject::findOrFail($id)->show_chapter_selection);
        $this->getJson('/api/learning-catalog?compact=1')->assertOk()
            ->assertJsonFragment(['id' => $id, 'name' => 'Mapel Fokus Baru', 'show_chapter_selection' => false]);
        $this->putJson("/api/admin/subjects/{$id}", ['name' => 'Mapel Fokus Baru', 'show_chapter_selection' => true])
            ->assertOk()->assertJsonPath('data.show_chapter_selection', true);
        $this->getJson('/api/learning-catalog?compact=1')->assertOk()
            ->assertJsonFragment(['id' => $id, 'name' => 'Mapel Fokus Baru', 'show_chapter_selection' => true]);

        $subject = CurriculumSubject::findOrFail($this->payload()['subjects'][0]['curriculum_subject_id']);
        $before = $subject->chapters()->count();
        $this->assertGreaterThan(0, $before);
        $this->putJson("/api/admin/subjects/{$subject->id}", ['name' => $subject->name, 'show_chapter_selection' => false])->assertOk();
        $this->assertSame($before, $subject->chapters()->count());
        $this->putJson("/api/admin/subjects/{$subject->id}", ['name' => $subject->name, 'show_chapter_selection' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('show_chapter_selection');
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'status' => 'active', 'email_verified_at' => now()]));
        $this->postJson('/api/student/packages', $this->payload(), ['Idempotency-Key' => 'focus-hidden-chapters'])->assertCreated();
    }

    public function test_guest_chapter_choice_and_optional_note_survive_claim(): void
    {
        $payload = $this->payload();
        $chapter = CurriculumChapter::query()->where('curriculum_subject_id', $payload['subjects'][0]['curriculum_subject_id'])
            ->where('education_level', 'SMP')->where('grade', 'Kelas 7')->where('is_active', true)->firstOrFail();
        $payload['subjects'][0]['curriculum_chapter_ids'] = [$chapter->id];
        $payload['subjects'][0]['learning_goal'] = '';
        $code = $this->postJson('/api/guest/packages', $payload)->assertCreated()->json('code');
        $this->assertSame([$chapter->id], GuestPackageOrder::where('code', $code)->firstOrFail()->payload['subjects'][0]['curriculum_chapter_ids']);
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'status' => 'active', 'email' => $payload['email'], 'email_verified_at' => now()]));
        $packageId = $this->postJson("/api/guest/packages/{$code}/claim")->assertOk()->json('data.id');
        $this->assertDatabaseHas('package_subjects', ['learning_package_id' => $packageId, 'chapter' => $chapter->title, 'learning_goal' => null]);
        $this->assertDatabaseHas('package_chapters', ['curriculum_chapter_id' => $chapter->id, 'title' => $chapter->title]);
        $payload['email'] = 'chapter-note@example.test';
        $payload['subjects'][0]['learning_goal'] = ' Fokus bagian yang sulit ';
        $noteCode = $this->postJson('/api/guest/packages', $payload)->assertCreated()->json('code');
        $this->assertSame('Fokus bagian yang sulit', GuestPackageOrder::where('code', $noteCode)->firstOrFail()->payload['subjects'][0]['learning_goal']);
    }

    public function test_guest_cannot_choose_inactive_or_wrong_class_chapters(): void
    {
        $payload = $this->payload();
        $chapter = CurriculumChapter::query()->where('curriculum_subject_id', $payload['subjects'][0]['curriculum_subject_id'])
            ->where('education_level', 'SMP')->where('grade', 'Kelas 8')->where('is_active', true)->firstOrFail();
        $payload['subjects'][0]['curriculum_chapter_ids'] = [$chapter->id];
        $this->postJson('/api/guest/packages', $payload)->assertUnprocessable()->assertJsonValidationErrors('subjects.0.curriculum_chapter_ids');
        $chapter->update(['grade' => 'Kelas 7', 'is_active' => false]);
        $this->postJson('/api/guest/packages', $payload)->assertUnprocessable()->assertJsonValidationErrors('subjects.0.curriculum_chapter_ids');
        $this->assertDatabaseCount('guest_package_orders', 0);
    }

    public function test_guest_focus_survives_payment_matching_class_completion_and_renewal_without_any_chapter_catalog(): void
    {
        $payload = $this->payload();
        $goal = trim($payload['subjects'][0]['learning_goal']);
        // Optional curriculum references must not block ordering or completion.
        CurriculumChapter::query()->delete();
        $code = $this->postJson('/api/guest/packages', $payload)->assertCreated()->json('code');
        $this->assertSame($goal, GuestPackageOrder::query()->where('code', $code)->firstOrFail()->payload['subjects'][0]['learning_goal']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active', 'email' => $payload['email'], 'email_verified_at' => now()]);
        Sanctum::actingAs($student);
        $packageId = $this->postJson("/api/guest/packages/{$code}/claim")->assertOk()->json('data.id');
        $this->postJson("/api/guest/packages/{$code}/claim")->assertOk()->assertJsonPath('data.id', $packageId);
        $package = LearningPackage::query()->findOrFail($packageId);
        $subject = $package->subjects()->firstOrFail();
        $this->assertSame($goal, $subject->learning_goal);
        $this->assertDatabaseCount('package_chapters', 0);
        $this->assertDatabaseCount('orders', 1);

        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'email_verified_at' => now()]);
        $profile = tap(TeacherProfile::query()->create([
            'user_id' => $teacher->id,
            'expertise' => 'Matematika',
            'teaching_method' => 'Online',
            'is_accepting_requests' => true,
        ]), fn ($profile) => $profile->forceFill(['verified_at' => now()])->save());
        TeacherSubject::query()->create([
            'teacher_profile_id' => $profile->id, 'curriculum_subject_id' => $subject->curriculum_subject_id,
            'name' => 'Matematika', 'levels' => ['SMP'], 'is_active' => true,
            'is_online' => true, 'is_offline' => false, 'is_private_active' => true,
        ]);
        TeacherAvailability::query()->create([
            'user_id' => $teacher->id, 'day' => 'Senin',
            'slots' => [['start_time' => '08:00', 'end_time' => '23:00']], 'is_active' => true,
        ]);
        config()->set('xendit.enabled', true);
        config()->set('xendit.secret_key', 'xnd_development_focus_test');
        config()->set('xendit.webhook_token', 'focus-webhook-token');
        config()->set('xendit.base_url', 'https://api.xendit.test');
        $order = Order::query()->where('learning_package_id', $packageId)->firstOrFail();
        $sessionId = "ps-focus-{$order->id}";
        Http::fake(['https://api.xendit.test/sessions' => Http::response([
            'payment_session_id' => $sessionId, 'payment_link_url' => "https://checkout-staging.xendit.test/{$sessionId}",
            'status' => 'ACTIVE', 'expires_at' => now()->addHour()->toIso8601String(),
        ])]);
        $this->postJson("/api/orders/{$order->id}/xendit-session", [], ['Idempotency-Key' => 'focus-payment-session'])->assertOk();
        $this->postJson('/api/webhooks/xendit', [
            'id' => 'evt-focus-paid', 'event' => 'payment_session.completed',
            'data' => [
                'payment_session_id' => $sessionId, 'reference_id' => $order->fresh()->gateway_reference_id,
                'status' => 'COMPLETED', 'amount' => (int) $order->amount,
                'payment_id' => 'py-focus', 'payment_request_id' => 'pr-focus',
            ],
        ], ['x-callback-token' => 'focus-webhook-token'])->assertOk();
        $request = BookingRequest::query()->where('package_subject_id', $subject->id)->firstOrFail();
        $this->assertSame($goal, $request->learning_goal);
        $offer = TeacherOffer::query()->where('booking_request_id', $request->id)->where('status', 'pending')->firstOrFail();
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/offers/{$offer->id}/accept")->assertOk();
        $booking = Booking::query()->where('order_id', $order->id)->firstOrFail();
        $booking->update(['meeting_link' => 'https://zoom.us/j/12345678901']);
        $this->getJson("/api/bookings/{$booking->id}/learning-session")->assertOk()
            ->assertJsonPath('booking.requested_goal', $goal)->assertJsonCount(0, 'learning_chapters');

        Carbon::setTestNow($booking->start_at);
        $this->postJson("/api/teacher/bookings/{$booking->id}/ready")->assertOk();
        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/presence-confirm")->assertOk();
        Carbon::setTestNow($booking->end_at);
        Sanctum::actingAs($teacher);
        $this->postJson("/api/teacher/bookings/{$booking->id}/check-out")->assertOk();
        $this->postJson("/api/teacher/bookings/{$booking->id}/progress-reports", [
            'material_covered' => 'Pecahan dan latihan soal cerita.',
            'mastered_skills' => 'Murid mampu menyamakan penyebut pecahan.',
            'next_exercise' => 'Latihan soal cerita pecahan secara mandiri.',
            'progress_percent' => 70,
        ])->assertCreated()->assertJsonPath('data.progress_percent', 70);
        $this->getJson("/api/bookings/{$booking->id}/learning-session")->assertOk()->assertJsonPath('material_progress_percent', 70);
        $this->getJson("/api/teacher/package-subjects/{$subject->id}/progress")->assertOk()->assertJsonPath('learning_goal', $goal);
        Sanctum::actingAs($student);
        $this->postJson("/api/student/bookings/{$booking->id}/approve")->assertOk();
        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertSame('ready', $booking->fresh()->payout_status);
        $this->assertSame('completed', $package->fresh()->status);
        $this->assertSame(1, $package->fresh()->used_sessions);
        $this->assertDatabaseCount('package_session_chapter_logs', 0);

        $renewal = $payload;
        $renewal['renewal_of_id'] = $package->id;
        $renewal['subjects'][0]['preferred_teacher_id'] = $teacher->id;
        $renewal['subjects'][0]['schedules'] = ['2026-09-28 15:00:00'];
        $renewedId = $this->postJson('/api/student/packages', $renewal, ['Idempotency-Key' => 'focus-renewal-order'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('package_subjects', ['learning_package_id' => $renewedId, 'learning_goal' => $goal]);
        $this->assertDatabaseCount('orders', 2);
    }
}
