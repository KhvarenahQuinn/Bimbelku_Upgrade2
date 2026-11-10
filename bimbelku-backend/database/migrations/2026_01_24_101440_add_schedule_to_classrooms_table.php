<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('classrooms')) {
            return;
        }

        Schema::table('classrooms', function (Blueprint $table) {
            if (! Schema::hasColumn('classrooms', 'day')) {
                $table->string('day')->nullable();
            }

            if (! Schema::hasColumn('classrooms', 'time')) {
                $table->string('time')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('classrooms')) {
            return;
        }

        Schema::table('classrooms', function (Blueprint $table) {
            foreach (['day', 'time'] as $column) {
                if (Schema::hasColumn('classrooms', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
