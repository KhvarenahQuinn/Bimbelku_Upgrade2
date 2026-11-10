<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurriculumSubject;
use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProgramController extends Controller
{
    public function index()
    {
        // Kategori katalog kini memakai kelompok mapel sebagai satu sumber data.
        $groups = DB::table('curriculum_subject_groups')->orderBy('name')->get();
        $productCategoryIds = DB::table('learning_programs')->where('is_active', true)
            ->whereNotNull('catalog_category_id')->pluck('catalog_category_id')->all();
        $subjects = CurriculumSubject::query()->where('is_active', true)
            ->orderBy('name')->get();

        return response()->json($groups->map(function ($group) use ($subjects) {
            $items = $subjects->filter(fn ($subject) => Str::lower((string) $subject->group_name) === $group->normalized_name);

            return [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => Str::slug($group->name),
                'description' => null,
                'allow_multi_mapel' => false,
                'subjects' => $items->values()->map(fn ($subject) => [
                    'id' => $subject->id, 'name' => $subject->name,
                    'education_levels' => $subject->education_levels, 'is_active' => true,
                ]),
            ];
        })->filter(fn ($group) => $group['subjects']->isNotEmpty() || in_array($group['id'], $productCategoryIds))->values());
    }

    public function adminIndex()
    {
        return response()->json(Program::query()
            ->with(['subjects' => fn ($query) => $query->orderBy('name')])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (Program $program) => $this->format($program)));
    }

    public function store(Request $request)
    {
        $data = $this->validateProgram($request);
        $program = DB::transaction(function () use ($data) {
            $program = Program::create(collect($data)->except('subject_ids')->all());
            $program->subjects()->sync($data['subject_ids']);

            return $program;
        });

        return response()->json(['message' => 'Program berhasil dibuat.', 'data' => $this->format($program->load('subjects'))], 201);
    }

    public function update(Request $request, Program $program)
    {
        $data = $this->validateProgram($request, $program);
        DB::transaction(function () use ($program, $data) {
            $program->update(collect($data)->except('subject_ids')->all());
            $program->subjects()->sync($data['subject_ids']);
        });

        return response()->json(['message' => 'Program berhasil diperbarui.', 'data' => $this->format($program->load('subjects'))]);
    }

    public function deactivate(Program $program)
    {
        $program->update(['is_active' => false]);

        return response()->json(['message' => 'Program disembunyikan. Riwayat mapel tetap aman.']);
    }

    private function validateProgram(Request $request, ?Program $program = null): array
    {
        $request->merge(['slug' => Str::slug((string) $request->input('name'))]);

        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'max:150', Rule::unique('programs', 'slug')->ignore($program?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'allow_multi_mapel' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'subject_ids' => ['required', 'array'],
            'subject_ids.*' => ['integer', 'distinct', 'exists:curriculum_subjects,id'],
        ]);
    }

    private function format(Program $program): array
    {
        return [
            'id' => $program->id,
            'name' => $program->name,
            'slug' => $program->slug,
            'description' => $program->description,
            'allow_multi_mapel' => $program->allow_multi_mapel,
            'is_active' => $program->is_active,
            'sort_order' => $program->sort_order,
            'subjects' => $program->subjects->map(fn ($subject) => [
                'id' => $subject->id,
                'name' => $subject->name,
                'education_levels' => $subject->education_levels,
                'is_active' => $subject->is_active,
            ])->values(),
        ];
    }
}
