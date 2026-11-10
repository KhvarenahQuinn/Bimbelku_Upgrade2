<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Models\CurriculumSubject;
use App\Models\HourlyRate;
use App\Models\TeacherSubject;
use App\Support\EducationCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CurriculumSubjectController extends Controller
{
    public function index(Request $request)
    {
        $query = CurriculumSubject::query()
            ->withCount(['chapters' => fn ($chapters) => $chapters->where('is_active', true)])
            ->orderBy('group_name')
            ->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }
        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->input('search')).'%';
            $query->where('name', 'like', $search);
        }

        return response()->json(
            $request->boolean('all') ? $query->get() : $query->paginate(100)
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validateSubject($request);
        $normalized = $this->normalize($validated['name']);
        $existing = CurriculumSubject::query()
            ->where('normalized_name', $normalized)
            ->first();

        if ($existing) {
            $this->rememberGroup((string) $existing->group_name);
            if (! $existing->is_active || ! $this->hasValidScope($existing)) {
                $updates = ['is_active' => true];
                if (! $this->hasValidScope($existing)) {
                    $levels = $validated['education_levels'] ?? EducationCatalog::LEVELS;
                    $updates['education_levels'] = $levels;
                    $updates['grades'] = $validated['grades'] ?? $this->gradesForLevels($levels);
                }
                $existing->update($updates);
                Cache::forget('learning_catalog.payload');
            }

            return response()->json([
                'message' => 'Mata pelajaran sudah tersedia dan langsung dipilih.',
                'data' => $existing->fresh(),
                'created' => false,
            ]);
        }

        $levels = $validated['education_levels'] ?? EducationCatalog::LEVELS;
        $subject = CurriculumSubject::create([
            ...$validated,
            'name' => $this->displayName($validated['name']),
            'normalized_name' => $normalized,
            'education_levels' => $levels,
            'grades' => $validated['grades'] ?? $this->gradesForLevels($levels),
            'source_url' => $validated['source_url'] ?? 'https://buku.kemendikdasmen.go.id/',
        ]);
        $this->rememberGroup((string) $subject->group_name);
        Cache::forget('learning_catalog.payload');

        return response()->json([
            'message' => 'Mata pelajaran baru berhasil ditambahkan.',
            'data' => $subject,
            'created' => true,
        ], 201);
    }

    public function update(Request $request, CurriculumSubject $curriculumSubject)
    {
        $validated = $this->validateSubject($request, $curriculumSubject);
        if (array_key_exists('education_levels', $validated) && ! array_key_exists('grades', $validated)) {
            $validated['grades'] = $this->gradesForLevels($validated['education_levels']);
        }

        $name = $this->displayName($validated['name']);
        $curriculumSubject->update([
            ...$validated,
            'name' => $name,
            'normalized_name' => $this->normalize($name),
        ]);
        $this->rememberGroup((string) $curriculumSubject->group_name);
        Cache::forget('learning_catalog.payload');

        return response()->json([
            'message' => 'Mata pelajaran berhasil diperbarui.',
            'data' => $curriculumSubject->fresh()->loadCount('chapters'),
        ]);
    }

    public function destroy(CurriculumSubject $curriculumSubject)
    {
        $used = $curriculumSubject->chapters()->exists()
            || HourlyRate::query()->whereRaw('LOWER(subject_name) = ?', [$curriculumSubject->normalized_name])->exists()
            || TeacherSubject::query()->whereRaw('LOWER(name) = ?', [$curriculumSubject->normalized_name])->exists()
            || BookingRequest::query()->whereRaw('LOWER(subject_name) = ?', [$curriculumSubject->normalized_name])->exists();

        if ($used) {
            $curriculumSubject->update(['is_active' => false]);
            Cache::forget('learning_catalog.payload');

            return response()->json([
                'message' => 'Mapel sudah digunakan, sehingga dinonaktifkan tanpa menghapus riwayat.',
                'deactivated' => true,
            ]);
        }

        $curriculumSubject->delete();
        Cache::forget('learning_catalog.payload');

        return response()->json([
            'message' => 'Mata pelajaran berhasil dihapus.',
            'deactivated' => false,
        ]);
    }

    private function validateSubject(
        Request $request,
        ?CurriculumSubject $current = null
    ): array {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $normalized = $this->normalize((string) $request->input('name'));
        $request->merge(['normalized_name' => $normalized]);
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'group_name' => ['sometimes', 'string', 'max:80'],
            'education_levels' => ['sometimes', 'array', 'min:1'],
            'education_levels.*' => ['required', Rule::in(EducationCatalog::LEVELS)],
            'grades' => ['sometimes', 'array', 'min:1'],
            'grades.*' => ['required', 'string', 'max:50'],
            'is_elective' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'show_chapter_selection' => ['sometimes', 'boolean'],
            'curriculum_name' => ['sometimes', 'string', 'max:100'],
            'edition' => ['nullable', 'string', 'max:80'],
            'source_url' => ['nullable', 'url:http,https', 'max:500'],
        ];

        // Store menangani nama ganda dengan mengembalikan mapel lama.
        // Update tetap harus menolak perubahan nama ke mapel lain yang sudah ada.
        if ($current) {
            $rules['normalized_name'] = [
                Rule::unique('curriculum_subjects', 'normalized_name')->ignore($current->id),
            ];
        }

        $validated = $request->validate($rules) + ['normalized_name' => $normalized];
        $levels = $validated['education_levels'] ?? $current?->education_levels ?? EducationCatalog::LEVELS;
        if (array_key_exists('grades', $validated)) {
            $unsupportedGrades = array_values(array_diff(
                $validated['grades'],
                $this->gradesForLevels($levels)
            ));
            if ($unsupportedGrades !== []) {
                throw ValidationException::withMessages([
                    'grades' => ['Kelas yang dipilih tidak sesuai dengan jenjang mata pelajaran.'],
                ]);
            }
        }

        return $validated;
    }

    private function normalize(string $value): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));
    }

    private function rememberGroup(string $name): void
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
        if ($name === '') {
            return;
        }

        $now = now();
        DB::table('curriculum_subject_groups')->insertOrIgnore([
            'name' => $name,
            'normalized_name' => Str::lower($name),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function displayName(string $value): string
    {
        $trimmed = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        if (mb_strlen($trimmed) <= 6 && mb_strtoupper($trimmed, 'UTF-8') === $trimmed) {
            return $trimmed;
        }

        return mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8');
    }

    private function hasValidScope(CurriculumSubject $subject): bool
    {
        $levels = array_values(array_filter(
            $subject->education_levels ?? [],
            fn ($level) => is_string($level) && in_array($level, EducationCatalog::LEVELS, true)
        ));
        if ($levels === []) {
            return false;
        }

        $grades = array_values(array_filter($subject->grades ?? [], 'is_string'));
        if ($grades === []) {
            return false;
        }

        return array_diff($grades, $this->gradesForLevels($levels)) === [];
    }

    private function gradesForLevels(array $levels): array
    {
        return EducationCatalog::gradesForLevels($levels);
    }
}
