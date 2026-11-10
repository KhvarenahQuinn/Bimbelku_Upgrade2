<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cheap_class_templates')) {
            Schema::create('cheap_class_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('curriculum_subject_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject_name', 120);
                $table->string('education_level', 30);
                $table->string('grade', 50);
                $table->string('chapter', 180);
                $table->string('subtopic', 220)->nullable();
                $table->text('topic')->nullable();
                $table->date('first_session_date');
                $table->time('start_time');
                $table->unsignedSmallInteger('duration_minutes')->default(60);
                $table->json('recurrence_days')->nullable();
                $table->decimal('price_per_student', 12, 2);
                $table->unsignedSmallInteger('minimum_participants')->default(2);
                $table->unsignedSmallInteger('maximum_participants')->default(6);
                $table->unsignedSmallInteger('registration_window_hours')->default(24);
                $table->unsignedSmallInteger('registration_closes_before_minutes')->default(60);
                $table->unsignedSmallInteger('payment_window_minutes')->default(60);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['is_active', 'first_session_date']);
            });
        }

        if (! Schema::hasTable('cheap_classes')) {
            Schema::create('cheap_classes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cheap_class_template_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('curriculum_subject_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject_name', 120);
                $table->string('education_level', 30);
                $table->string('grade', 50);
                $table->string('chapter', 180);
                $table->string('subtopic', 220)->nullable();
                $table->text('topic')->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->dateTime('registration_opens_at');
                $table->dateTime('registration_deadline');
                $table->decimal('price_per_student', 12, 2);
                $table->unsignedSmallInteger('minimum_participants');
                $table->unsignedSmallInteger('maximum_participants');
                $table->unsignedSmallInteger('payment_window_minutes')->default(60);
                $table->string('status', 40)->default('open');
                $table->string('meeting_link', 500)->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->unique(['cheap_class_template_id', 'starts_at'], 'cheap_class_template_occurrence_unique');
                $table->index(['status', 'registration_deadline']);
                $table->index(['teacher_id', 'starts_at', 'ends_at']);
            });
        }

        if (! Schema::hasTable('cheap_class_enrollments')) {
            Schema::create('cheap_class_enrollments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cheap_class_id')->constrained()->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('status', 40)->default('seat_held');
                $table->timestamp('seat_expires_at')->nullable();
                $table->timestamp('payment_submitted_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->unique(['cheap_class_id', 'student_id']);
                $table->index(['cheap_class_id', 'status']);
                $table->index(['student_id', 'status']);
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'cheap_class_enrollment_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('cheap_class_enrollment_id')
                    ->nullable()
                    ->unique()
                    ->constrained('cheap_class_enrollments')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'cheap_class_enrollment_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['cheap_class_enrollment_id']);
                $table->dropUnique(['cheap_class_enrollment_id']);
                $table->dropColumn('cheap_class_enrollment_id');
            });
        }
        Schema::dropIfExists('cheap_class_enrollments');
        Schema::dropIfExists('cheap_classes');
        Schema::dropIfExists('cheap_class_templates');
    }
};
