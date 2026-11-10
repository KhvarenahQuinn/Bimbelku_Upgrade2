<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurriculumSubject;
use App\Models\LearningProgram;
use App\Support\EducationCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LearningProgramController extends Controller
{
    public function index()
    {
        return response()->json(LearningProgram::query()->where('is_active', true)
            ->with(['subjects', 'plan', 'category'])->orderBy('sort_order')->orderBy('name')->get());
    }

    public function adminIndex()
    {
        return response()->json(LearningProgram::query()->with(['subjects', 'plan', 'category'])
            ->orderBy('sort_order')->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $program = DB::transaction(function () use ($data) {
            $program = LearningProgram::query()->create(collect($data)->except('subject_ids')->all());
            $program->subjects()->sync($this->subjectOrder($data['subject_ids']));

            return $program;
        });

        return response()->json(['data' => $program->load(['subjects', 'plan', 'category'])], 201);
    }

    public function update(Request $request, LearningProgram $learningProgram)
    {
        $data = $this->validated($request, $learningProgram);
        DB::transaction(function () use ($learningProgram, $data) {
            $learningProgram->update(collect($data)->except('subject_ids')->all());
            $learningProgram->subjects()->sync($this->subjectOrder($data['subject_ids']));
        });

        return response()->json(['data' => $learningProgram->load(['subjects', 'plan', 'category'])]);
    }

    public function deactivate(LearningProgram $learningProgram)
    {
        $learningProgram->update(['is_active' => false]);

        return response()->json(['message' => 'Program disembunyikan; pesanan lama tidak berubah.']);
    }

    private function validated(Request $request, ?LearningProgram $current = null): array
    {
        $request->merge(['slug' => Str::slug((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', Rule::unique('learning_programs', 'slug')->ignore($current?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'catalog_category_id' => ['nullable', 'integer', 'exists:curriculum_subject_groups,id'],
            'education_level' => ['sometimes', Rule::in(EducationCatalog::LEVELS)],
            'education_levels' => ['sometimes', 'array', 'min:1'],
            'education_levels.*' => ['required', 'distinct', Rule::in(EducationCatalog::LEVELS)],
            'grade' => ['nullable', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'subject_ids' => ['required', 'array', 'min:1', 'max:20'],
            'subject_ids.*' => ['required', 'integer', 'distinct', 'exists:curriculum_subjects,id'],
        ], [
            'slug.unique' => 'Nama program ini sudah digunakan. Pilih nama program lain.',
        ]);
        $levels = $data['education_levels'] ?? (isset($data['education_level']) ? [$data['education_level']] : $current?->availableEducationLevels());
        if (! $levels) {
            throw ValidationException::withMessages(['education_levels' => 'Pilih minimal satu jenjang program.']);
        }
        $data['education_levels'] = array_values($levels);
        $data['education_level'] = $levels[0];
        $data['package_plan_id'] = null;
        if ($data['grade'] && (count($levels) !== 1 || ! EducationCatalog::supports($levels[0], $data['grade']))) {
            throw ValidationException::withMessages(['grade' => 'Kelas tidak sesuai dengan jenjang program.']);
        }
        $subjects = CurriculumSubject::query()->whereIn('id', $data['subject_ids'])->get();
        if ($subjects->count() !== count($data['subject_ids'])) {
            throw ValidationException::withMessages(['subject_ids' => 'Ada mapel program yang tidak tersedia.']);
        }
        foreach ($subjects as $subject) {
            if (! $subject->is_active || array_diff($levels, $subject->education_levels ?? [])
                || ($data['grade'] && $subject->grades && ! in_array($data['grade'], $subject->grades, true))
                || (! $data['grade'] && $subject->grades && collect($levels)->contains(fn ($level) => ! array_intersect(
                    EducationCatalog::GRADES_BY_LEVEL[$level] ?? [], $subject->grades ?? []
                )))) {
                throw ValidationException::withMessages(['subject_ids' => "{$subject->name} tidak tersedia pada jenjang atau kelas program."]);
            }
        }

        return $data;
    }

    private function subjectOrder(array $ids): array
    {
        return collect($ids)->mapWithKeys(fn ($id, $index) => [$id => ['sort_order' => $index]])->all();
    }
}
