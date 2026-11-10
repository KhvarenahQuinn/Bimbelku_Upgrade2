<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('teacher_subjects') || Schema::hasColumn('teacher_subjects', 'is_group_active')) {
            return;
        }

        Schema::table('teacher_subjects', function (Blueprint $table) {
            $table->boolean('is_group_active')->default(false)->after('is_private_active');
        });

        // Privat adalah layanan bawaan; kelompok harus diaktifkan eksplisit oleh tutor.
        DB::table('teacher_subjects')->whereNull('is_group_active')->update(['is_group_active' => false]);
    }

    public function down(): void
    {
        if (Schema::hasTable('teacher_subjects') && Schema::hasColumn('teacher_subjects', 'is_group_active')) {
            Schema::table('teacher_subjects', function (Blueprint $table) {
                $table->dropColumn('is_group_active');
            });
        }
    }
};
