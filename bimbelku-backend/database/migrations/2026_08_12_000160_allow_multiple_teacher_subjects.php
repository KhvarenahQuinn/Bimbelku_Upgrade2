<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROFILE_FK_INDEX = 'teacher_subjects_profile_fk_idx';

    private const OLD_PROFILE_UNIQUE = 'teacher_subjects_profile_unique';

    private const PROFILE_SUBJECT_UNIQUE = 'teacher_subjects_profile_subject_unique';

    public function up(): void
    {
        if (! Schema::hasTable('teacher_subjects')) {
            return;
        }

        /*
         * MySQL/InnoDB requires an index whose left-most column covers
         * teacher_profile_id for the existing foreign key. On older installs,
         * teacher_subjects_profile_unique became that supporting index, so
         * dropping it directly fails with MySQL error 1553.
         *
         * Create a normal supporting index first. It is intentionally kept
         * after this migration because it is the stable FK index, independent
         * of the business-level composite unique constraint below.
         */
        if (
            Schema::hasColumn('teacher_subjects', 'teacher_profile_id')
            && ! Schema::hasIndex('teacher_subjects', self::PROFILE_FK_INDEX)
        ) {
            Schema::table('teacher_subjects', function (Blueprint $table) {
                $table->index('teacher_profile_id', self::PROFILE_FK_INDEX);
            });
        }

        if (Schema::hasIndex('teacher_subjects', self::OLD_PROFILE_UNIQUE)) {
            Schema::table('teacher_subjects', function (Blueprint $table) {
                $table->dropUnique(self::OLD_PROFILE_UNIQUE);
            });
        }

        if (
            Schema::hasColumn('teacher_subjects', 'teacher_profile_id')
            && Schema::hasColumn('teacher_subjects', 'curriculum_subject_id')
            && ! Schema::hasIndex('teacher_subjects', self::PROFILE_SUBJECT_UNIQUE)
        ) {
            Schema::table('teacher_subjects', function (Blueprint $table) {
                $table->unique(
                    ['teacher_profile_id', 'curriculum_subject_id'],
                    self::PROFILE_SUBJECT_UNIQUE
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('teacher_subjects')) {
            return;
        }

        if (Schema::hasIndex('teacher_subjects', self::PROFILE_SUBJECT_UNIQUE)) {
            Schema::table('teacher_subjects', function (Blueprint $table) {
                $table->dropUnique(self::PROFILE_SUBJECT_UNIQUE);
            });
        }

        // Rollback ke aturan lama hanya aman jika setiap tutor masih punya
        // maksimal satu baris kompetensi. Jangan menghapus data multi-mapel.
        $hasMultiple = DB::table('teacher_subjects')
            ->select('teacher_profile_id')
            ->groupBy('teacher_profile_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $hasMultiple && ! Schema::hasIndex('teacher_subjects', self::OLD_PROFILE_UNIQUE)) {
            Schema::table('teacher_subjects', function (Blueprint $table) {
                $table->unique('teacher_profile_id', self::OLD_PROFILE_UNIQUE);
            });
        }

        /*
         * PROFILE_FK_INDEX sengaja tidak dihapus. Foreign key tetap
         * membutuhkan index teacher_profile_id, dan mempertahankannya membuat
         * rollback aman baik ketika unique lama dapat dipasang kembali maupun
         * ketika data sudah multi-mapel.
         */
    }
};
