<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CheapClass;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\StageOneFixtureSet;
use Tests\TestCase;

class TestingStageOnePreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preparation_uses_an_isolated_database_and_fake_mail(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_fixture_roles_and_subject_specific_tutor_availability_are_ready(): void
    {
        $fixture = StageOneFixtureSet::create();
        $users = $fixture['users'];
        $this->assertCount(14, $users);
        $this->assertCount(3, $fixture['subjects']);
        $this->assertCount(14, array_unique(array_map(fn ($user) => $user->email, $users)));
        $this->assertTrue($users['primary_admin']->isPrimaryAdmin());
        $this->assertFalse($users['ordinary_admin']->isPrimaryAdmin());
        $this->assertNull($users['unverified']->email_verified_at);
        $this->assertNotNull($users['minor']->guardian_consent_at);

        foreach (['math_teacher', 'indonesian_teacher', 'english_teacher', 'replacement_teacher'] as $key) {
            $teacher = $users[$key];
            $this->assertSame('active', $teacher->status);
            $this->assertNotNull($teacher->teacherProfile->verified_at);
            $this->assertTrue($teacher->teacherProfile->is_accepting_requests);
            $availability = $teacher->availabilities()->where('day', 'Senin')->firstOrFail();
            $this->assertTrue($availability->covers('09:00', '11:00'));
            $this->assertFalse($availability->covers('15:00', '17:00'));
        }
        $this->assertFalse($users['unavailable_teacher']->availabilities()->where('day', 'Senin')->exists());
        $this->assertFalse($users['paused_teacher']->teacherProfile->is_accepting_requests);
        $this->assertSame('pending', $users['pending_teacher']->status);
        $this->assertNull($users['pending_teacher']->teacherProfile->verified_at);
        $this->assertSame(0, Order::query()->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_demo_commands_refuse_production_without_creating_accounts_or_orders(): void
    {
        app()->instance('env', 'production');
        foreach (['demo:journey-time', 'demo:gateway-connectivity', 'demo:cheap-class', 'demo:package-renewal', 'demo:teacher-replacement', 'demo:session-reminder'] as $command) {
            $arguments = $command === 'demo:journey-time' ? ['order' => 'QA-NOT-AN-INVOICE', 'stage' => 'start'] : [];
            $this->artisan($command, $arguments)->assertFailed();
        }
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Order::query()->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_local_time_skip_refuses_a_production_payment_key(): void
    {
        app()->instance('env', 'local');
        config()->set('xendit.secret_key', 'xnd_production_synthetic_do_not_use');
        $this->artisan('demo:journey-time', ['order' => 'QA-NOT-AN-INVOICE', 'stage' => 'start'])->assertFailed();
        $this->assertSame(0, Order::query()->count());
        Http::assertNothingSent();
    }

    public function test_expiry_clock_is_process_local_and_can_be_restored(): void
    {
        $initial = Carbon::parse('2026-10-05 09:00:00', 'Asia/Jakarta');
        Carbon::setTestNow($initial);
        $deadline = now()->addHours(24);
        $this->assertFalse($deadline->isPast());
        Carbon::setTestNow($initial->copy()->addHours(25));
        $this->assertTrue($deadline->isPast());
        Carbon::setTestNow();
        $this->assertFalse(Carbon::hasTestNow());
        Http::assertNothingSent();
    }

    public function test_private_time_skip_moves_only_the_clock_and_never_approves_a_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Asia/Jakarta'));
        $this->artisan('demo:session-reminder', ['stage' => 'setup'])->assertSuccessful();
        $booking = Booking::query()->latest('id')->firstOrFail();
        // Synthetic paid fixture, not a claim of real gateway settlement.
        $order = $booking->order;
        $order->forceFill(['payment_provider' => 'xendit'])->save();
        $session = $booking->packageSession;
        $this->assertNotNull($session);
        $arguments = ['order' => $order->order_id, '--session' => $session->id];
        $this->artisan('demo:journey-time', [...$arguments, 'stage' => 'status'])->assertSuccessful();
        $this->artisan('demo:journey-time', [...$arguments, 'stage' => 'start'])->assertSuccessful();
        $this->assertTrue($booking->fresh()->start_at->equalTo(now()->subMinutes(2)->startOfMinute()));
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertNull($booking->fresh()->student_confirmed_at);
        $this->artisan('demo:journey-time', [...$arguments, 'stage' => 'end'])->assertFailed();
        // Model the presence state to inspect the command only; real role actions are stage 6.
        $booking->forceFill(['status' => 'in_progress', 'tutor_ready_at' => now(), 'student_confirmed_at' => now()])->save();
        $this->artisan('demo:journey-time', [...$arguments, 'stage' => 'end'])->assertSuccessful();
        $this->assertTrue($booking->fresh()->end_at->isPast());
        $this->assertSame('in_progress', $booking->fresh()->status);
        $this->assertNull($booking->fresh()->completed_at);
        $this->assertSame('paid', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_shared_time_skip_does_not_replace_report_and_verification(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Asia/Jakarta'));
        $this->artisan('demo:cheap-class', ['stage' => 'setup'])->assertSuccessful();
        $class = CheapClass::query()->latest('id')->firstOrFail();
        $order = Order::query()->whereNotNull('cheap_class_enrollment_id')->latest('id')->firstOrFail();
        $order->forceFill(['payment_provider' => 'xendit'])->save();
        $session = $class->sessions()->firstOrFail();
        $arguments = ['order' => $order->order_id, '--session' => $session->id];
        $this->artisan('demo:journey-time', [...$arguments, 'stage' => 'start'])->assertSuccessful();
        $this->assertSame('scheduled', $session->fresh()->status);
        $this->artisan('demo:journey-time', [...$arguments, 'stage' => 'end'])->assertFailed();
        $session->forceFill(['status' => 'in_progress', 'teacher_started_at' => now()])->save();
        $this->artisan('demo:journey-time', [...$arguments, 'stage' => 'end'])->assertSuccessful();
        $this->assertTrue($session->fresh()->ends_at->isPast());
        $this->assertNotSame('completed', $session->fresh()->status);
        $this->assertNotSame('completed', $class->fresh()->status);
        Http::assertNothingSent();
    }
}
