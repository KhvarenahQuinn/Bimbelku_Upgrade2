<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->boolean('allow_multi_mapel')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('program_curriculum_subject', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('curriculum_subject_id')->constrained('curriculum_subjects')->cascadeOnDelete();
            $table->primary(['program_id', 'curriculum_subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_curriculum_subject');
        Schema::dropIfExists('programs');
    }
};
