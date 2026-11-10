<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_subjects') && Schema::hasColumn('teacher_subjects', 'is_group_active')) {
            $driver = DB::getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE teacher_subjects MODIFY is_group_active TINYINT(1) NOT NULL DEFAULT 0');
            } elseif ($driver === 'pgsql') {
                DB::statement('ALTER TABLE teacher_subjects ALTER COLUMN is_group_active SET DEFAULT FALSE');
            }
            // Layanan privat aktif secara bawaan, sedangkan kelas kelompok
            // harus diaktifkan secara sadar oleh tutor.
            DB::table('teacher_subjects')->whereNull('is_group_active')->update(['is_group_active' => false]);
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'maximum_search_hours'],
                ['value' => '24', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('teacher_subjects') && Schema::hasColumn('teacher_subjects', 'is_group_active')) {
            $driver = DB::getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE teacher_subjects MODIFY is_group_active TINYINT(1) NOT NULL DEFAULT 0');
            } elseif ($driver === 'pgsql') {
                DB::statement('ALTER TABLE teacher_subjects ALTER COLUMN is_group_active SET DEFAULT FALSE');
            }
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'maximum_search_hours')->update(['value' => '12', 'updated_at' => now()]);
        }
    }
};
