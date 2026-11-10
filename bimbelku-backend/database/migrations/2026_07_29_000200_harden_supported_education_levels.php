<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GRADES_BY_LEVEL = [
        'SD' => [
            'Kelas 1',
            'Kelas 2',
            'Kelas 3',
            'Kelas 4',
            'Kelas 5',
            'Kelas 6',
        ],
        'SMP' => [
            'Kelas 7',
            'Kelas 8',
            'Kelas 9',
        ],
        'SMA' => [
            'Kelas 10',
            'Kelas 11',
            'Kelas 12',
        ],
        'Umum' => [
            'Semua tingkat',
            'Pemula',
            'Menengah',
            'Lanjutan',
        ],
    ];

    public function up(): void
    {
        $this->cleanStudentProfiles();
        $this->cleanCurriculumSubjects();
        $this->cleanTeacherSubjects();
        $this->deactivateUnsupportedRows('curriculum_chapters');
        $this->deactivateUnsupportedRows('learning_topics');
        $this->deactivateUnsupportedRows('hourly_rates', true);
    }

    public function down(): void
    {
        // Nilai yang berada di luar cakupan layanan tidak dipulihkan.
        // Riwayat pemesanan dan transaksi tidak diubah oleh migrasi ini.
    }

    private function cleanStudentProfiles(): void
    {
        if (
            ! Schema::hasTable('users')
            || ! Schema::hasColumn('users', 'grade')
            || ! Schema::hasColumn('users', 'role')
        ) {
            return;
        }

        $values = ['grade' => null];
        if (Schema::hasColumn('users', 'updated_at')) {
            $values['updated_at'] = now();
        }

        DB::table('users')
            ->where('role', 'student')
            ->where(function ($query) {
                $query->where('grade', 'Perguruan Tinggi')
                    ->orWhere('grade', 'like', 'Semester %');
            })
            ->update($values);
    }

    private function cleanCurriculumSubjects(): void
    {
        if (
            ! Schema::hasTable('curriculum_subjects')
            || ! Schema::hasColumn('curriculum_subjects', 'education_levels')
        ) {
            return;
        }

        DB::table('curriculum_subjects')
            ->select(['id', 'education_levels', 'grades', 'is_active'])
            ->orderBy('id')
            ->get()
            ->each(function (object $row) {
                $levels = $this->supportedLevels($this->decodeList($row->education_levels));
                $allowedGrades = $this->gradesForLevels($levels);
                $grades = array_values(array_intersect(
                    $this->decodeList($row->grades),
                    $allowedGrades
                ));
                if ($levels !== [] && $grades === []) {
                    $grades = $allowedGrades;
                }

                $values = [
                    'education_levels' => $this->encodeList($levels),
                    'grades' => $this->encodeList($grades),
                    'is_active' => $levels !== [] && $grades !== []
                        ? (bool) $row->is_active
                        : false,
                ];
                if (Schema::hasColumn('curriculum_subjects', 'updated_at')) {
                    $values['updated_at'] = now();
                }

                DB::table('curriculum_subjects')
                    ->where('id', $row->id)
                    ->update($values);
            });
    }

    private function cleanTeacherSubjects(): void
    {
        if (
            ! Schema::hasTable('teacher_subjects')
            || ! Schema::hasColumn('teacher_subjects', 'levels')
        ) {
            return;
        }

        DB::table('teacher_subjects')
            ->select(['id', 'levels', 'is_active'])
            ->orderBy('id')
            ->get()
            ->each(function (object $row) {
                $levels = $this->supportedLevels($this->decodeList($row->levels));
                $values = [
                    'levels' => $this->encodeList($levels),
                    'is_active' => $levels !== [] && (bool) $row->is_active,
                ];
                if (Schema::hasColumn('teacher_subjects', 'updated_at')) {
                    $values['updated_at'] = now();
                }

                DB::table('teacher_subjects')
                    ->where('id', $row->id)
                    ->update($values);
            });
    }

    private function deactivateUnsupportedRows(
        string $table,
        bool $allowNullLevel = false
    ): void {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'education_level')
            || ! Schema::hasColumn($table, 'is_active')
        ) {
            return;
        }

        $values = ['is_active' => false];
        if (Schema::hasColumn($table, 'updated_at')) {
            $values['updated_at'] = now();
        }

        DB::table($table)
            ->when(
                $allowNullLevel,
                fn ($query) => $query->whereNotNull('education_level')
            )
            ->whereNotIn('education_level', array_keys(self::GRADES_BY_LEVEL))
            ->update($values);

        if (! Schema::hasColumn($table, 'grade')) {
            return;
        }

        DB::table($table)
            ->select(['id', 'education_level', 'grade'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->each(function (object $row) use ($table, $values) {
                $level = is_string($row->education_level)
                    ? $row->education_level
                    : '';
                $grade = is_string($row->grade) ? $row->grade : '';
                if (
                    array_key_exists($level, self::GRADES_BY_LEVEL)
                    && ! in_array($grade, self::GRADES_BY_LEVEL[$level], true)
                ) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update($values);
                }
            });
    }

    private function supportedLevels(array $levels): array
    {
        return array_values(array_unique(array_filter(
            $levels,
            fn (string $level) => array_key_exists($level, self::GRADES_BY_LEVEL)
        )));
    }

    private function gradesForLevels(array $levels): array
    {
        $grades = [];
        foreach ($levels as $level) {
            $grades = [
                ...$grades,
                ...(self::GRADES_BY_LEVEL[$level] ?? []),
            ];
        }

        return array_values(array_unique($grades));
    }

    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, 'is_string'))
            : [];
    }

    private function encodeList(array $value): string
    {
        return json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
    }
};
