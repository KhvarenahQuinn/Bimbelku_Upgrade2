<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cheap_class_sessions', 'teacher_started_at')) {
            Schema::table('cheap_class_sessions', function (Blueprint $table) {
                $table->dateTime('teacher_started_at')->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('cheap_class_sessions', 'teacher_started_by')) {
            Schema::table('cheap_class_sessions', function (Blueprint $table) {
                $table->foreignId('teacher_started_by')
                    ->nullable()
                    ->after('teacher_started_at')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cheap_class_sessions', 'teacher_started_by')) {
            Schema::table('cheap_class_sessions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('teacher_started_by');
            });
        }

        if (Schema::hasColumn('cheap_class_sessions', 'teacher_started_at')) {
            Schema::table('cheap_class_sessions', function (Blueprint $table) {
                $table->dropColumn('teacher_started_at');
            });
        }
    }
};
