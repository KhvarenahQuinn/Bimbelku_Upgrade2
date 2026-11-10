<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('class_sessions') || Schema::hasColumn('class_sessions', 'date')) {
            return;
        }

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->date('date')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('class_sessions') || ! Schema::hasColumn('class_sessions', 'date')) {
            return;
        }

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('date');
        });
    }
};
