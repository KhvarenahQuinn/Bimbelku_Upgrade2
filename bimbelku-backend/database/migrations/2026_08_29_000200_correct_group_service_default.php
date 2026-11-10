<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('teacher_subjects') || ! Schema::hasColumn('teacher_subjects', 'is_group_active')) {
            return;
        }
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE teacher_subjects MODIFY is_group_active TINYINT(1) NOT NULL DEFAULT 0');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE teacher_subjects ALTER COLUMN is_group_active SET DEFAULT FALSE');
        }
        DB::table('teacher_subjects')->whereNull('is_group_active')->update(['is_group_active' => false]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('teacher_subjects') || ! Schema::hasColumn('teacher_subjects', 'is_group_active')) {
            return;
        }
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE teacher_subjects MODIFY is_group_active TINYINT(1) NOT NULL DEFAULT 1');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE teacher_subjects ALTER COLUMN is_group_active SET DEFAULT TRUE');
        }
    }
};
