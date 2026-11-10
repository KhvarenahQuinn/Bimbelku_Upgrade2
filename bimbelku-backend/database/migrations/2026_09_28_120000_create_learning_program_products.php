<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->foreignId('catalog_category_id')->nullable()->constrained('curriculum_subject_groups')->nullOnDelete();
            $table->foreignId('package_plan_id')->constrained('package_plans')->restrictOnDelete();
            $table->string('education_level', 20);
            $table->string('grade', 50)->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('learning_program_subject', function (Blueprint $table) {
            $table->foreignId('learning_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_subject_id')->constrained('curriculum_subjects')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['learning_program_id', 'curriculum_subject_id'], 'learning_program_subject_pk');
        });
        Schema::table('learning_packages', function (Blueprint $table) {
            $table->foreignId('learning_program_id')->nullable()->after('package_plan_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('learning_packages', fn (Blueprint $table) => $table->dropConstrainedForeignId('learning_program_id'));
        Schema::dropIfExists('learning_program_subject');
        Schema::dropIfExists('learning_programs');
    }
};
