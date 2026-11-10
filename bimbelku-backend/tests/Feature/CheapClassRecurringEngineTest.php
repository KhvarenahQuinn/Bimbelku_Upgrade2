<?php

namespace Tests\Feature;

use App\Models\CheapClass;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\User;
use App\Services\CheapClassService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheapClassRecurringEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recurring_setting_creates_a_separate_package_each_week_without_duplicates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        $service = app(CheapClassService::class);
        $result = $service->createPackage($this->packageData(true));
        $template = $result['template']->fresh();
        $firstPackage = $result['class']->fresh(['sessions']);

        $this->assertTrue($template->recurrence_enabled);
        $this->assertTrue($template->is_active);
        $this->assertSame('2026-08-23 09:00:00', $template->next_publish_at->format('Y-m-d H:i:s'));
        $this->assertSame('manual', $firstPackage->generation_source);
        $this->assertSame(1, CheapClass::query()->where('cheap_class_template_id', $template->id)->count());

        Carbon::setTestNow($template->next_publish_at->copy());
        $firstRun = $service->publishDueRecurringPackages();

        $this->assertSame(1, $firstRun['created']);
        $this->assertSame(2, CheapClass::query()->where('cheap_class_template_id', $template->id)->count());
        $secondPackage = CheapClass::query()
            ->where('cheap_class_template_id', $template->id)
            ->where('id', '!=', $firstPackage->id)
            ->with('sessions')
            ->firstOrFail();

        $this->assertSame('recurring', $secondPackage->generation_source);
        $this->assertNotSame($firstPackage->package_code, $secondPackage->package_code);
        $this->assertSame(0, $secondPackage->enrollments()->count());
        $this->assertSame(
            $firstPackage->sessions->first()->starts_at->copy()->addWeek()->format('Y-m-d H:i:s'),
            $secondPackage->sessions->first()->starts_at->format('Y-m-d H:i:s')
        );

        // Simulasikan retry scheduler untuk periode yang sama. Unique period key
        // dan pemeriksaan existing harus menjaga jumlah paket tetap dua.
        $template->fresh()->update(['next_publish_at' => Carbon::parse('2026-08-23 09:00:00', 'Asia/Jakarta')]);
        $retry = $service->publishDueRecurringPackages();

        $this->assertSame(0, $retry['created']);
        $this->assertSame(1, $retry['existing']);
        $this->assertSame(2, CheapClass::query()->where('cheap_class_template_id', $template->id)->count());
    }

    public function test_deactivation_stops_future_generation_but_keeps_existing_package(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        $service = app(CheapClassService::class);
        $result = $service->createPackage($this->packageData(true));
        $template = $service->setRecurrenceActive($result['template'], false);

        $this->assertFalse($template->is_active);
        $this->assertNull($template->next_publish_at);

        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'Asia/Jakarta'));
        $stats = $service->publishDueRecurringPackages();

        $this->assertSame(0, $stats['checked']);
        $this->assertSame(1, CheapClass::query()->where('cheap_class_template_id', $template->id)->count());
        $this->assertDatabaseHas('cheap_class_templates', [
            'id' => $template->id,
            'recurrence_enabled' => true,
            'is_active' => false,
        ]);
    }

    public function test_reactivation_uses_the_next_future_aligned_week_and_forgets_missed_weeks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        $service = app(CheapClassService::class);
        $result = $service->createPackage($this->packageData(true));
        $template = $service->setRecurrenceActive($result['template'], false);

        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'Asia/Jakarta'));
        $reactivated = $service->setRecurrenceActive($template, true);

        $this->assertTrue($reactivated->is_active);
        $this->assertSame('2026-09-13 09:00:00', $reactivated->next_publish_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, CheapClass::query()->where('cheap_class_template_id', $template->id)->count());

        Carbon::setTestNow($reactivated->next_publish_at->copy());
        $stats = $service->publishDueRecurringPackages();

        $this->assertSame(1, $stats['created']);
        $this->assertSame(2, CheapClass::query()->where('cheap_class_template_id', $template->id)->count());
        $this->assertDatabaseMissing('cheap_classes', [
            'cheap_class_template_id' => $template->id,
            'occurrence_week_start' => '2026-08-24',
        ]);
    }

    public function test_late_active_scheduler_skips_closed_windows_instead_of_backfilling(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        $service = app(CheapClassService::class);
        $template = $service->createPackage($this->packageData(true))['template'];

        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'Asia/Jakarta'));
        $stats = $service->publishDueRecurringPackages();
        $fresh = $template->fresh();

        $this->assertSame(0, $stats['created']);
        $this->assertSame(3, $stats['skipped']);
        $this->assertSame('2026-09-13 09:00:00', $fresh->next_publish_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-06 09:00:00', $fresh->last_skipped_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, CheapClass::query()->where('cheap_class_template_id', $template->id)->count());
    }

    public function test_admin_endpoint_can_turn_recurring_template_off_without_touching_existing_package(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        $template = app(CheapClassService::class)->createPackage($this->packageData(true))['template'];
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->patchJson(
            "/api/admin/cheap-class-templates/{$template->id}/recurrence",
            ['active' => false],
            ['Idempotency-Key' => 'cheap-class-recurrence-off-0001']
        )
            ->assertOk()
            ->assertJsonPath('data.recurrence_enabled', true)
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.next_publish_at', null);

        $this->assertSame(1, CheapClass::query()->where('cheap_class_template_id', $template->id)->count());
        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_admin_has_a_dedicated_recurring_template_list_with_runtime_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        $result = app(CheapClassService::class)->createPackage($this->packageData(true));
        $template = $result['template'];
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/cheap-class-templates/recurring')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $template->id)
            ->assertJsonPath('data.0.template_code', $template->template_code)
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.0.package_count', 1)
            ->assertJsonPath('data.0.active_package_count', 1)
            ->assertJsonPath('data.0.history_package_count', 0);
    }

    public function test_cancelled_package_is_removed_from_active_schedule_and_kept_in_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        $class = app(CheapClassService::class)->createPackage($this->packageData(false))['class'];
        $class->update([
            'status' => 'cancelled',
            'cancellation_reason' => 'Paket dibatalkan admin.',
            'cancelled_at' => now(),
        ]);
        $class->sessions()->update(['status' => 'cancelled']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/cheap-classes/schedule?scope=active&view=packages')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.scope', 'active');

        $this->getJson('/api/admin/cheap-classes/schedule?scope=history&view=packages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $class->id)
            ->assertJsonPath('data.0.status', 'cancelled')
            ->assertJsonPath('data.0.cancellation_reason', 'Paket dibatalkan admin.')
            ->assertJsonPath('meta.scope', 'history');
    }

    private function packageData(bool $recurring): array
    {
        [$subject, $chapter] = $this->catalog();

        return [
            'curriculum_subject_id' => $subject->id,
            'curriculum_chapter_id' => $chapter->id,
            'subjects' => [[
                'curriculum_subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'curriculum_chapter_id' => $chapter->id,
                'chapter' => $chapter->title,
            ]],
            'subject_name' => $subject->name,
            'education_level' => 'SD',
            'grade' => 'Kelas 6',
            'chapter' => $chapter->title,
            'topic' => null,
            'first_session_date' => '2026-08-17',
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'session_count' => 4,
            'recurrence_days' => [1],
            'price_per_session' => 25000,
            'custom_price_per_student' => null,
            'price_per_student' => 100000,
            'minimum_participants' => 2,
            'maximum_participants' => 6,
            'registration_window_hours' => 24,
            'registration_closes_before_minutes' => 60,
            'payment_window_minutes' => 60,
            'recurrence_enabled' => $recurring,
        ];
    }

    private function catalog(): array
    {
        $subject = CurriculumSubject::query()->firstOrCreate(
            ['normalized_name' => 'matematika'],
            [
                'name' => 'Matematika',
                'education_levels' => ['SD'],
                'grades' => ['Kelas 6'],
                'is_active' => true,
            ]
        );
        $chapter = CurriculumChapter::query()->firstOrCreate(
            [
                'curriculum_subject_id' => $subject->id,
                'education_level' => 'SD',
                'grade' => 'Kelas 6',
                'normalized_title' => 'pecahan',
            ],
            [
                'title' => 'Pecahan',
                'is_active' => true,
            ]
        );

        return [$subject, $chapter];
    }
}
