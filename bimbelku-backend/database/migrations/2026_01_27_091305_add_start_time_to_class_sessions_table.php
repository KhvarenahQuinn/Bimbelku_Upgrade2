<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('class_sessions') || Schema::hasColumn('class_sessions', 'start_time')) {
            return;
        }

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dateTime('start_time')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('class_sessions') || ! Schema::hasColumn('class_sessions', 'start_time')) {
            return;
        }

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('start_time');
        });
    }
};
