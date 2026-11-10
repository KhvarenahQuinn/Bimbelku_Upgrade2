<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->deactivateRows('curriculum_chapters', 'education_level');
        $this->deactivateRows('learning_topics', 'education_level');
        $this->deactivateRows('hourly_rates', 'education_level');
        $this->removeLevelFromJsonColumn('curriculum_subjects', 'education_levels', 'grades');
        $this->removeLevelFromJsonColumn('teacher_subjects', 'levels');
    }

    public function down(): void
    {
        // Riwayat transaksi dipertahankan. Jenjang yang dikeluarkan tidak
        // diaktifkan kembali otomatis karena katalognya tidak lagi tersedia.
    }

    private function deactivateRows(string $table, string $levelColumn): void
    {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, $levelColumn)
            || ! Schema::hasColumn($table, 'is_active')
        ) {
            return;
        }

        $values = ['is_active' => false];
        if (Schema::hasColumn($table, 'updated_at')) {
            $values['updated_at'] = now();
        }

        DB::table($table)
            ->where($levelColumn, 'Perguruan Tinggi')
            ->update($values);
    }

    private function removeLevelFromJsonColumn(
        string $table,
        string $levelsColumn,
        ?string $gradesColumn = null
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $levelsColumn)) {
            return;
        }

        $columns = ['id', $levelsColumn];
        if ($gradesColumn && Schema::hasColumn($table, $gradesColumn)) {
            $columns[] = $gradesColumn;
        }
        if (Schema::hasColumn($table, 'is_active')) {
            $columns[] = 'is_active';
        }

        DB::table($table)
            ->select($columns)
            ->orderBy('id')
            ->get()
            ->each(function (object $row) use ($table, $levelsColumn, $gradesColumn) {
                $originalLevels = $this->decodeList($row->{$levelsColumn});
                if (! in_array('Perguruan Tinggi', $originalLevels, true)) {
                    return;
                }

                $levels = array_values(array_filter(
                    $originalLevels,
                    fn (string $level) => $level !== 'Perguruan Tinggi'
                ));
                $values = [
                    $levelsColumn => json_encode($levels, JSON_UNESCAPED_UNICODE),
                ];

                if ($gradesColumn && property_exists($row, $gradesColumn)) {
                    $grades = array_values(array_filter(
                        $this->decodeList($row->{$gradesColumn}),
                        fn (string $grade) => preg_match('/^Semester\s+\d+$/iu', trim($grade)) !== 1
                    ));
                    $values[$gradesColumn] = json_encode($grades, JSON_UNESCAPED_UNICODE);
                }

                if ($levels === [] && Schema::hasColumn($table, 'is_active')) {
                    $values['is_active'] = false;
                }
                if (Schema::hasColumn($table, 'updated_at')) {
                    $values['updated_at'] = now();
                }

                DB::table($table)->where('id', $row->id)->update($values);
            });
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
};
