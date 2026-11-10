<?php

namespace App\Services;

use App\Models\LearningProgram;
use App\Models\PackagePlan;
use Illuminate\Validation\ValidationException;

class LearningProgramSelection
{
    public function validate(int $id, int $planId, string $level, string $grade, array $subjects): LearningProgram
    {
        $program = LearningProgram::query()->where('is_active', true)->with('subjects')->findOrFail($id);
        $chosen = collect($subjects)->pluck('curriculum_subject_id')->map(fn ($value) => (int) $value)->sort()->values()->all();
        $required = $program->subjects->pluck('id')->map(fn ($value) => (int) $value)->sort()->values()->all();
        $selectedSubjects = $program->subjects->whereIn('id', $chosen);
        $plan = PackagePlan::query()->where('is_active', true)->findOrFail($planId);
        if (! in_array($level, $program->availableEducationLevels(), true) || ($program->grade && $program->grade !== $grade)
            || ! $chosen || count($chosen) > $plan->maximum_subjects
            || count($chosen) > $plan->session_count
            || count($chosen) !== count(array_unique($chosen))
            || array_diff($chosen, $required)
            || $selectedSubjects->contains(fn ($subject) => ! $subject->is_active
                || ($subject->education_levels && ! in_array($level, $subject->education_levels, true))
                || ($subject->grades && ! in_array($grade, $subject->grades, true)))) {
            throw ValidationException::withMessages([
                'learning_program_id' => 'Pilih satu atau beberapa mapel dari program sesuai jenjang dan kapasitas paket sesi.',
            ]);
        }

        return $program;
    }
}
