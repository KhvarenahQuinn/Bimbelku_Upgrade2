<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_page_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_subject_id')->unique()->constrained('curriculum_subjects')->cascadeOnDelete();
            $table->text('hero_intro')->nullable();
            $table->text('learning_approach')->nullable();
            $table->json('facts')->nullable();
            $table->json('learning_map')->nullable();
            $table->json('benefits')->nullable();
            $table->json('suitable_for')->nullable();
            $table->json('faqs')->nullable();
            $table->string('source_note', 500)->nullable();
            $table->date('reviewed_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::table('website_testimonials', function (Blueprint $table) {
            $table->foreignId('curriculum_subject_id')->nullable()->after('rating_id')
                ->constrained('curriculum_subjects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('website_testimonials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('curriculum_subject_id');
        });
        Schema::dropIfExists('subject_page_contents');
    }
};
