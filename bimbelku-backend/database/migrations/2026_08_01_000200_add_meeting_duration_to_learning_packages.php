<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_packages', function (Blueprint $table) {
            if (! Schema::hasColumn('learning_packages', 'duration_hours')) {
                $table->unsignedTinyInteger('duration_hours')
                    ->default(1)
                    ->after('learning_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('learning_packages', function (Blueprint $table) {
            if (Schema::hasColumn('learning_packages', 'duration_hours')) {
                $table->dropColumn('duration_hours');
            }
        });
    }
};
