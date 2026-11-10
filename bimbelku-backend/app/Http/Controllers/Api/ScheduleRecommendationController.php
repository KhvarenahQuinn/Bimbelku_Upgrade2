<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Models\CurriculumSubject;
use App\Models\LearningTimeSlot;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\Setting;
use App\Services\TeacherMatchingService;
use App\Support\EducationCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScheduleRecommendationController extends Controller
{
    public function __invoke(Request $request, TeacherMatchingService $matchingService)
    {
        $validated = $request->validate([
            'curriculum_subject_id' => ['required', 'integer', 'exists:curriculum_subjects,id'],
            'education_level' => ['required', Rule::in(EducationCatalog::LEVELS)],
            'grade' => ['required', 'string', 'max:100'],
            'learning_mode' => ['required', Rule::in(['online', 'offline'])],
            'duration_hours' => ['required', 'integer', Rule::in([1, 2])],
            'schedule_start_date' => ['required', 'date_format:Y-m-d'],
            'weekdays' => ['required', 'array', 'min:1', 'max:4'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'session_count' => ['required', 'integer', 'between:1,60'],
            'current_time' => ['nullable', 'date_format:H:i'],
        ]);

        $student = $request->user();
        if ($validated['learning_mode'] === 'offline') {
            abort_if(
                $student->latitude === null || $student->longitude === null,
                422,
                'Lengkapi titik lokasi profil sebelum meminta rekomendasi jadwal offline.'
            );
        }

        $subject = CurriculumSubject::query()
            ->where('is_active', true)
            ->findOrFail($validated['curriculum_subject_id']);
        $duration = (int) $validated['duration_hours'];
        $leadHours = min(72, max(1, (int) (
            Setting::query()->where('key', 'booking_lead_hours')->value('value') ?? 24
        )));
        $threshold = now(config('app.timezone', 'Asia/Jakarta'))->addHours($leadHours);
        $preferredMinutes = $this->minutes($validated['current_time'] ?? '18:00');

        $recommendations = LearningTimeSlot::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get()
            ->map(function (LearningTimeSlot $slot) use (
                $validated,
                $student,
                $subject,
                $duration,
                $threshold,
                $matchingService,
                $preferredMinutes
            ) {
                $time = substr((string) $slot->start_time, 0, 5);
                if (substr($time, 3, 2) !== '00' || $this->minutes($time) + ($duration * 60) > 23 * 60) {
                    return null;
                }

                $starts = $this->buildStarts(
                    $validated['schedule_start_date'],
                    $validated['weekdays'],
                    (int) $validated['session_count'],
                    $time
                );
                if ($starts->isEmpty() || $starts->first()->lt($threshold)) {
                    return null;
                }

                $preview = new BookingRequest;
                $preview->forceFill([
                    'student_id' => $student->id,
                    'package_subject_id' => -1,
                    'curriculum_subject_id' => $subject->id,
                    'subject_name' => $subject->name,
                    'education_level' => $validated['education_level'],
                    'grade' => $validated['grade'],
                    'learning_mode' => $validated['learning_mode'],
                    'class_type' => 'private',
                    'scheduled_date' => $starts->first()->toDateString(),
                    'start_time' => $starts->first()->format('H:i:s'),
                    'end_time' => $starts->first()->copy()->addHours($duration)->format('H:i:s'),
                    'duration_hours' => $duration,
                    'latitude' => $validated['learning_mode'] === 'offline' ? $student->latitude : null,
                    'longitude' => $validated['learning_mode'] === 'offline' ? $student->longitude : null,
                    'search_radius_km' => $validated['learning_mode'] === 'offline' ? 3 : null,
                ]);
                $packageSubject = new PackageSubject;
                $packageSubject->setRelation('sessions', $starts->values()->map(
                    fn (Carbon $start, int $index) => (new PackageSession)->forceFill([
                        'sequence' => $index + 1,
                        'scheduled_start_at' => $start,
                        'scheduled_end_at' => $start->copy()->addHours($duration),
                    ])
                ));
                $preview->setRelation('packageSubject', $packageSubject);

                $candidateCount = $matchingService->availableCandidateCountForPreview($preview);

                return [
                    'time' => $time,
                    'candidate_count' => $candidateCount,
                    'availability' => $this->availabilityLabel($candidateCount),
                    'schedules' => $starts->map(fn (Carbon $start) => $start->format('Y-m-d\TH:i'))->all(),
                    'distance_from_current_minutes' => abs($this->minutes($time) - $preferredMinutes),
                ];
            })
            ->filter()
            ->sort(function (array $a, array $b) {
                $candidateOrder = $b['candidate_count'] <=> $a['candidate_count'];

                return $candidateOrder !== 0
                    ? $candidateOrder
                    : ($a['distance_from_current_minutes'] <=> $b['distance_from_current_minutes']);
            })
            ->take(3)
            ->map(function (array $item) {
                unset($item['distance_from_current_minutes']);

                return $item;
            })
            ->values();

        return response()->json([
            'mode' => 'preserve_days',
            'weekdays' => collect($validated['weekdays'])->sort()->values(),
            'lead_hours' => $leadHours,
            'recommendations' => $recommendations,
            'message' => $recommendations->contains(fn (array $item) => $item['candidate_count'] > 0)
                ? 'Rekomendasi mempertahankan hari pilihanmu dan memeriksa guru yang sama untuk seluruh sesi.'
                : 'Belum ada guru yang terlihat untuk pola hari ini. Kamu tetap dapat memakai jadwalmu atau mencoba alternatif hari secara terpisah.',
        ]);
    }

    private function buildStarts(string $date, array $weekdays, int $count, string $time)
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');
        $cursor = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, $timezone);
        $selectedDays = collect($weekdays)->map(fn ($day) => (int) $day)->unique();
        $starts = collect();
        $guard = 0;

        while ($starts->count() < $count && $guard < 730) {
            if ($selectedDays->contains($cursor->dayOfWeekIso)) {
                $starts->push($cursor->copy());
            }
            $cursor->addDay();
            $guard++;
        }

        return $starts;
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }

    private function availabilityLabel(int $count): string
    {
        return match (true) {
            $count >= 6 => 'very_high',
            $count >= 3 => 'high',
            $count >= 1 => 'limited',
            default => 'unavailable',
        };
    }
}
