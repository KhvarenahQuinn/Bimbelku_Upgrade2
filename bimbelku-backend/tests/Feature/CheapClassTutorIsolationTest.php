<?php

namespace Tests\Feature;

use App\Models\CheapClass;
use App\Models\CheapClassEnrollment;
use App\Models\CheapClassTemplate;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\Order;
use App\Models\Refund;
use App\Models\TeacherAvailability;
use App\Models\TeacherAvailabilityException;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\CheapClassService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CheapClassTutorIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weekly_occurrence_selects_its_own_teacher_and_does_not_inherit_operational_data(): void
    {
        [$service, $template, $firstPackage, $teachers] = $this->recurringScenario(2);
        $firstTeacherId = (int) $firstPackage->teacher_id;

        $this->assertContains($firstTeacherId, $teachers->pluck('id')->map(fn ($id) => (int) $id)->all());

        $firstPackage->update([
            'meeting_link' => 'https://us06web.zoom.us/j/111111111',
            'maximum_participants' => 3,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-16 09:05:00', 'Asia/Jakarta'));
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        [$firstEnrollment, $firstOrder] = $service->join($firstPackage->fresh(), $student);
        Refund::create([
            'order_id' => $firstOrder->id,
            'user_id' => $student->id,
            'amount' => $firstOrder->amount,
            'reason' => 'Data uji isolasi occurrence pertama',
            'status' => 'pending',
        ]);

        TeacherAvailabilityException::create([
            'user_id' => $firstTeacherId,
            'start_date' => '2026-08-24',
            'end_date' => '2026-08-24',
            'reason' => 'Tidak tersedia pada occurrence kedua',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Jakarta'));
        $stats = $service->publishDueRecurringPackages();
        $secondPackage = CheapClass::query()
            ->where('cheap_class_template_id', $template->id)
            ->whereKeyNot($firstPackage->id)
            ->with('sessions')
            ->firstOrFail();

        $this->assertSame(1, $stats['created']);
        $this->assertNotSame($firstPackage->package_code, $secondPackage->package_code);
        $this->assertNotSame($firstTeacherId, (int) $secondPackage->teacher_id);
        $this->assertContains((int) $secondPackage->teacher_id, $teachers->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertNull($secondPackage->meeting_link);
        $this->assertSame(6, $secondPackage->maximum_participants);
        $this->assertSame(0, $secondPackage->enrollments()->count());
        $this->assertSame(1, $firstPackage->enrollments()->count());
        $this->assertSame($firstPackage->id, $firstEnrollment->cheap_class_id);
        $this->assertSame($firstPackage->package_code, $firstOrder->class_details_snapshot['package_code']);
        $this->assertSame(1, Refund::query()->where('order_id', $firstOrder->id)->count());
        $this->assertSame('https://us06web.zoom.us/j/111111111', $firstPackage->fresh()->meeting_link);
    }

    public function test_same_student_receives_a_new_enrollment_and_invoice_for_the_next_week(): void
    {
        [$service, $template, $firstPackage] = $this->recurringScenario(2);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Carbon::setTestNow(Carbon::parse('2026-08-16 09:05:00', 'Asia/Jakarta'));
        [$firstEnrollment, $firstOrder] = $service->join($firstPackage->fresh(), $student);

        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Jakarta'));
        $service->publishDueRecurringPackages();
        $secondPackage = CheapClass::query()
            ->where('cheap_class_template_id', $template->id)
            ->whereKeyNot($firstPackage->id)
            ->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-08-23 09:05:00', 'Asia/Jakarta'));
        [$secondEnrollment, $secondOrder] = $service->join($secondPackage, $student);

        $this->assertNotSame($firstEnrollment->id, $secondEnrollment->id);
        $this->assertNotSame($firstOrder->id, $secondOrder->id);
        $this->assertNotSame($firstOrder->order_id, $secondOrder->order_id);
        $this->assertSame($firstPackage->id, $firstEnrollment->cheap_class_id);
        $this->assertSame($secondPackage->id, $secondEnrollment->cheap_class_id);
        $this->assertSame($firstPackage->package_code, $firstOrder->class_details_snapshot['package_code']);
        $this->assertSame($secondPackage->package_code, $secondOrder->class_details_snapshot['package_code']);
        $this->assertSame($template->template_code, $firstOrder->class_details_snapshot['template_code']);
        $this->assertSame($template->template_code, $secondOrder->class_details_snapshot['template_code']);
        $this->assertSame(1, CheapClassEnrollment::query()->where('cheap_class_id', $firstPackage->id)->count());
        $this->assertSame(1, CheapClassEnrollment::query()->where('cheap_class_id', $secondPackage->id)->count());
        $this->assertSame(1, Order::query()->where('cheap_class_enrollment_id', $firstEnrollment->id)->count());
        $this->assertSame(1, Order::query()->where('cheap_class_enrollment_id', $secondEnrollment->id)->count());
        $this->assertFalse($secondOrder->refund()->exists());
    }

    /** @return array{0:CheapClassService,1:CheapClassTemplate,2:CheapClass,3:Collection<int,User>} */
    private function recurringScenario(int $teacherCount): array
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'Asia/Jakarta'));
        [$subject, $chapter] = $this->catalog();
        $teachers = collect(range(1, $teacherCount))
            ->map(fn (int $number) => $this->eligibleTeacher($subject, "Tutor Antrean {$number}"));
        $service = app(CheapClassService::class);
        $result = $service->createPackage($this->packageData($subject, $chapter));

        return [
            $service,
            $result['template']->fresh(),
            $result['class']->fresh(['sessions']),
            $teachers,
        ];
    }

    private function eligibleTeacher(CurriculumSubject $subject, string $name): User
    {
        $teacher = User::factory()->create([
            'name' => $name,
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'verified_at' => now(),
            'is_accepting_requests' => true,
        ]);
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $subject->id,
            'name' => $subject->name,
            'levels' => ['SD'],
            'is_active' => true,
            'is_online' => true,
            'is_group_active' => true,
        ]);
        TeacherAvailability::create([
            'user_id' => $teacher->id,
            'day' => 'Senin',
            'slots' => [['start_time' => '10:00', 'end_time' => '11:00']],
            'is_active' => true,
        ]);

        return $teacher;
    }

    private function packageData(CurriculumSubject $subject, CurriculumChapter $chapter): array
    {
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
            'registration_opens_at' => '2026-08-16 09:00:00',
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'session_count' => 1,
            'recurrence_days' => [1],
            'price_per_session' => 25000,
            'custom_price_per_student' => null,
            'price_per_student' => 25000,
            'minimum_participants' => 2,
            'maximum_participants' => 6,
            'registration_window_hours' => 24,
            'registration_closes_before_minutes' => 60,
            'payment_window_minutes' => 60,
            'recurrence_enabled' => true,
        ];
    }

    private function catalog(): array
    {
        $subject = CurriculumSubject::create([
            'name' => 'Matematika',
            'normalized_name' => 'matematika',
            'education_levels' => ['SD'],
            'grades' => ['Kelas 6'],
            'is_active' => true,
        ]);
        $chapter = CurriculumChapter::create([
            'curriculum_subject_id' => $subject->id,
            'education_level' => 'SD',
            'grade' => 'Kelas 6',
            'title' => 'Pecahan',
            'normalized_title' => 'pecahan',
            'is_active' => true,
        ]);

        return [$subject, $chapter];
    }
}
