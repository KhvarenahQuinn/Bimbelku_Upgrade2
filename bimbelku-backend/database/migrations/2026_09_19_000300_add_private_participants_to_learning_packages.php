<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_packages', function (Blueprint $table) {
            $table->unsignedTinyInteger('participant_count')->default(1);
            $table->json('participant_names')->nullable();
        });
        Schema::table('package_subjects', function (Blueprint $table) {
            $table->decimal('tutor_hourly_gross', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('package_subjects', fn (Blueprint $table) => $table->dropColumn('tutor_hourly_gross'));
        Schema::table('learning_packages', fn (Blueprint $table) => $table->dropColumn(['participant_count', 'participant_names']));
    }
};
