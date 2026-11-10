<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('locations') && ! Schema::hasColumn('locations', 'image')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->string('image')->nullable();
            });
        }

        if (! Schema::hasTable('location_teacher')) {
            Schema::create('location_teacher', function (Blueprint $table) {
                $table->id();
                $table->foreignId('teacher_profile_id')->constrained('teacher_profiles')->cascadeOnDelete();
                $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['teacher_profile_id', 'location_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('location_teacher');
        if (Schema::hasTable('locations') && Schema::hasColumn('locations', 'image')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('image');
            });
        }
    }
};
