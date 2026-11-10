<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Support\EducationCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CurriculumChapterController extends Controller
{
    public function index(Request $request)
    {
        $query = CurriculumChapter::query()
            ->with('subject:id,name,group_name,is_active')
            ->orderBy('education_level')
            ->orderBy('grade')
            ->orderBy('sort_order')
            ->orderBy('title');

        if ($request->filled('curriculum_subject_id')) {
            $query->where('curriculum_subject_id', $request->integer('curriculum_subject_id'));
        }
        if ($request->filled('grade')) {
            $query->where('grade', $request->string('grade'));
        }
        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', $search)
                    ->orWhereHas('subject', fn ($subjects) => $subjects->where('name', 'like', $search));
            });
        }

        return response()->json(
            $request->boolean('all') ? $query->get() : $query->paginate(100)
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validateChapter($request);
        $chapter = CurriculumChapter::create($validated);
        Cache::forget('learning_catalog.payload');

        return response()->json([
            'message' => 'Bab kurikulum berhasil ditambahkan.',
            'data' => $chapter->load('subject:id,name'),
        ], 201);
    }

    public function update(Request $request, CurriculumChapter $curriculumChapter)
    {
        $curriculumChapter->update($this->validateChapter($request, $curriculumChapter));
        Cache::forget('learning_catalog.payload');

        return response()->json([
            'message' => 'Bab kurikulum berhasil diperbarui.',
            'data' => $curriculumChapter->fresh()->load('subject:id,name'),
        ]);
    }

    public function destroy(CurriculumChapter $curriculumChapter)
    {
        $used = BookingRequest::query()
            ->whereRaw('LOWER(subject_name) = ?', [$curriculumChapter->subject->normalized_name])
            ->where('grade', $curriculumChapter->grade)
            ->whereRaw('LOWER(chapter) = ?', [$curriculumChapter->normalized_title])
            ->exists();

        if ($used) {
            $curriculumChapter->update(['is_active' => false]);
            Cache::forget('learning_catalog.payload');

            return response()->json([
                'message' => 'Bab sudah digunakan, sehingga dinonaktifkan tanpa menghapus riwayat.',
                'deactivated' => true,
            ]);
        }

        $curriculumChapter->delete();
        Cache::forget('learning_catalog.payload');

        return response()->json([
            'message' => 'Bab kurikulum berhasil dihapus.',
            'deactivated' => false,
        ]);
    }

    private function validateChapter(
        Request $request,
        ?CurriculumChapter $current = null
    ): array {
        $request->merge(['title' => trim((string) $request->input('title'))]);
        $subject = CurriculumSubject::query()
            ->where('is_active', true)
            ->findOrFail($request->integer('curriculum_subject_id'));
        $normalized = Str::lower(preg_replace('/\s+/u', ' ', (string) $request->input('title')));
        $unique = Rule::unique('curriculum_chapters', 'normalized_title')
            ->where(fn ($query) => $query
                ->where('curriculum_subject_id', $subject->id)
                ->where('grade', $request->input('grade')));
        if ($current) {
            $unique->ignore($current->id);
        }

        $validated = $request->validate([
            'curriculum_subject_id' => ['required', 'integer', 'exists:curriculum_subjects,id'],
            'education_level' => ['required', Rule::in(EducationCatalog::LEVELS)],
            'grade' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'min:2', 'max:220'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
            'source_reference' => ['nullable', 'string', 'max:500'],
        ]);

        $this->assertGrade($validated['education_level'], $validated['grade']);
        if (! in_array($validated['grade'], $subject->grades ?? [], true)) {
            abort(422, 'Kelas tersebut belum diaktifkan pada mata pelajaran ini.');
        }

        return [
            ...$validated,
            'title' => preg_replace('/\s+/u', ' ', trim($validated['title'])),
            'normalized_title' => $normalized,
        ];
    }

    private function assertGrade(string $level, string $grade): void
    {
        abort_unless(EducationCatalog::supports($level, $grade), 422, 'Kelas tidak sesuai jenjang.');
    }
}
