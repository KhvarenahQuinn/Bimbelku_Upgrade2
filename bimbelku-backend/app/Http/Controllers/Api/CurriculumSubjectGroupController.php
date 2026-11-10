<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CurriculumSubjectGroupController extends Controller
{
    public function index()
    {
        $counts = DB::table('curriculum_subjects')
            ->select('group_name')
            ->selectRaw('COUNT(*) as subject_count')
            ->groupBy('group_name')
            ->get()
            ->groupBy(fn ($row) => Str::lower(trim((string) $row->group_name)))
            ->map(fn ($rows) => $rows->sum('subject_count'));

        return response()->json(DB::table('curriculum_subject_groups')
            ->orderBy('name')
            ->get(['id', 'name', 'normalized_name'])
            ->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'subject_count' => (int) ($counts[$group->normalized_name] ?? 0),
            ]));
    }

    public function store(Request $request)
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $request->input('name', '')));
        $request->merge(['name' => $name]);
        $validated = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']]);
        $normalized = Str::lower($validated['name']);

        $now = now();
        $inserted = DB::table('curriculum_subject_groups')->insertOrIgnore([
            'name' => $validated['name'],
            'normalized_name' => $normalized,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (! $inserted) {
            throw ValidationException::withMessages(['name' => ['Kelompok mapel ini sudah tersedia.']]);
        }

        $group = DB::table('curriculum_subject_groups')->where('normalized_name', $normalized)->first();

        return response()->json([
            'message' => 'Kelompok mapel berhasil ditambahkan.',
            'data' => ['id' => $group->id, 'name' => $group->name, 'subject_count' => 0],
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $request->input('name', '')));
        $request->merge(['name' => $name]);
        $validated = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']]);
        $normalized = Str::lower($validated['name']);

        $group = DB::transaction(function () use ($id, $validated, $normalized) {
            $group = DB::table('curriculum_subject_groups')->where('id', $id)->lockForUpdate()->first();
            abort_unless($group, 404);
            if (DB::table('curriculum_subject_groups')
                ->where('normalized_name', $normalized)
                ->where('id', '!=', $id)
                ->exists()) {
                throw ValidationException::withMessages(['name' => ['Kelompok mapel ini sudah tersedia.']]);
            }

            DB::table('curriculum_subject_groups')->where('id', $id)->update([
                'name' => $validated['name'],
                'normalized_name' => $normalized,
                'updated_at' => now(),
            ]);
            DB::table('curriculum_subjects')->where('group_name', $group->name)->update([
                'group_name' => $validated['name'],
                'updated_at' => now(),
            ]);

            return DB::table('curriculum_subject_groups')->where('id', $id)->first();
        }, 3);
        Cache::forget('learning_catalog.payload');

        return response()->json([
            'message' => 'Nama kelompok dan mapel terkait berhasil diperbarui.',
            'data' => [
                'id' => $group->id,
                'name' => $group->name,
                'subject_count' => DB::table('curriculum_subjects')->where('group_name', $group->name)->count(),
            ],
        ]);
    }

    public function destroy(int $id)
    {
        DB::transaction(function () use ($id) {
            $group = DB::table('curriculum_subject_groups')->where('id', $id)->lockForUpdate()->first();
            abort_unless($group, 404);
            if (DB::table('curriculum_subjects')->where('group_name', $group->name)->exists()) {
                throw ValidationException::withMessages([
                    'group' => ['Kelompok ini masih dipakai. Pindahkan mapelnya ke kelompok lain sebelum menghapus.'],
                ]);
            }
            if (DB::table('learning_programs')->where('catalog_category_id', $id)->exists()) {
                throw ValidationException::withMessages(['group' => ['Kategori masih dipakai program belajar. Pindahkan program dahulu.']]);
            }
            DB::table('curriculum_subject_groups')->where('id', $id)->delete();
        }, 3);

        return response()->json(['message' => 'Kelompok mapel berhasil dihapus.']);
    }
}
