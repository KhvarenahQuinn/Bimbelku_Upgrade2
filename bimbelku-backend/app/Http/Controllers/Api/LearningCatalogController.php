<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\HourlyRate;
use App\Models\TeacherSubject;
use App\Support\EducationCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LearningCatalogController extends Controller
{
    public function index(Request $request)
    {
        $basePayload = Cache::remember('learning_catalog.payload', now()->addMinutes(10), function () {
            $defaults = [
                'Matematika', 'Bahasa Indonesia', 'Bahasa Inggris', 'IPA', 'IPS',
                'Fisika', 'Kimia', 'Biologi', 'Ekonomi', 'Akuntansi',
            ];

            $catalogSubjects = CurriculumSubject::query()
                ->where('is_active', true)
                ->orderBy('group_name')
                ->orderBy('name')
                ->get([
                    'id', 'name', 'group_name', 'education_levels', 'grades',
                    'is_elective', 'curriculum_name', 'edition', 'show_chapter_selection',
                ]);

            $subjects = $catalogSubjects
                ->pluck('name')
                ->merge(TeacherSubject::query()
                    ->where('is_active', true)
                    ->whereNotNull('name')
                    ->distinct()
                    ->pluck('name'))
                ->merge(HourlyRate::query()->where('is_active', true)->distinct()->pluck('subject_name'))
                ->merge($defaults)
                ->filter()
                ->unique()
                ->sort()
                ->values();

            return [
                'subjects' => $subjects,
                'subject_options' => $catalogSubjects,
                'landing_subjects' => $this->landingSubjects($catalogSubjects),
            ];
        });

        // Cache lama mungkin masih berisi detail lengkap. Hanya dua bagian
        // dasar yang dipakai agar respons compact tetap kecil setelah deploy.
        $basePayload = [
            'subjects' => $basePayload['subjects'],
            'subject_options' => $basePayload['subject_options'],
            // Cache dari versi lama belum mempunyai landing_subjects. Nilai
            // tetap dibentuk dari mapel kurikulum aktif agar deploy aman.
            'landing_subjects' => $basePayload['landing_subjects']
                ?? $this->landingSubjects(collect($basePayload['subject_options'])),
        ];
        $chapters = collect();

        if (! $request->boolean('compact')) {
            $subjectId = $request->integer('curriculum_subject_id');
            $subjectName = trim((string) $request->query('subject_name', ''));
            $educationLevel = trim((string) $request->query('education_level', ''));
            $grade = trim((string) $request->query('grade', ''));

            $chapterQuery = CurriculumChapter::query()
                ->where('is_active', true)
                ->whereHas('subject', function ($subjects) use ($subjectId, $subjectName) {
                    $subjects->where('is_active', true);
                    if ($subjectId > 0) {
                        $subjects->whereKey($subjectId);
                    } elseif ($subjectName !== '') {
                        $subjects->where('name', $subjectName);
                    }
                })
                ->with('subject:id,name')
                ->when(
                    $educationLevel !== '',
                    fn ($query) => $query->where('education_level', $educationLevel)
                )
                ->when($grade !== '', fn ($query) => $query->where('grade', $grade))
                ->orderBy('education_level')
                ->orderBy('grade')
                ->orderBy('sort_order');

            $chapters = $chapterQuery->get()->map(fn (CurriculumChapter $chapter) => [
                'id' => $chapter->id,
                'subject_id' => $chapter->curriculum_subject_id,
                'subject_name' => $chapter->subject->name,
                'education_level' => $chapter->education_level,
                'grade' => $chapter->grade,
                'title' => $chapter->title,
                'sort_order' => $chapter->sort_order,
            ]);
        }

        $payload = [
            ...$basePayload,
            'chapters' => $chapters,
            // Kontrak API lama tetap menyediakan key `topics`, tetapi selalu
            // kosong. Subbab sudah dipensiunkan dari runtime dan tidak lagi
            // dibaca dari database.
            'topics' => [],
            'education_levels' => EducationCatalog::LEVELS,
            'grades_by_level' => EducationCatalog::GRADES_BY_LEVEL,
            'class_types' => [
                ['value' => 'private', 'label' => 'Privat'],
            ],
            'learning_modes' => [
                ['value' => 'online', 'label' => 'Online'],
                ['value' => 'offline', 'label' => 'Offline'],
            ],
        ];
        $response = response()->json($payload);
        $response->setEtag(sha1((string) json_encode($payload)));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->headers->addCacheControlDirective('stale-while-revalidate', '60');
        $response->isNotModified($request);

        return $response;
    }

    private function landingSubjects($subjects)
    {
        $priority = array_flip([
            'Matematika',
            'Bahasa Indonesia',
            'Bahasa Inggris',
            'Fisika',
            'Kimia',
            'Biologi',
            'Ekonomi',
            'Akuntansi',
        ]);

        return collect($subjects)
            ->sort(function ($left, $right) use ($priority) {
                $leftName = (string) data_get($left, 'name', '');
                $rightName = (string) data_get($right, 'name', '');
                $leftPriority = $priority[$leftName] ?? 1000;
                $rightPriority = $priority[$rightName] ?? 1000;

                return ($leftPriority <=> $rightPriority)
                    ?: strnatcasecmp($leftName, $rightName);
            })
            ->values()
            ->map(fn ($subject) => [
                'id' => (int) data_get($subject, 'id'),
                'name' => (string) data_get($subject, 'name'),
                'group_name' => (string) data_get($subject, 'group_name', ''),
                'education_levels' => array_values((array) data_get($subject, 'education_levels', [])),
            ]);
    }
}
