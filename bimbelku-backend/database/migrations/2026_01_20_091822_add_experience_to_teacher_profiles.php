<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('teacher_profiles') || Schema::hasColumn('teacher_profiles', 'experience')) {
            return;
        }

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->string('experience')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('teacher_profiles') || ! Schema::hasColumn('teacher_profiles', 'experience')) {
            return;
        }

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn('experience');
        });
    }
};
