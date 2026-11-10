<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('teacher_availability_exceptions')) {
            Schema::create('teacher_availability_exceptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->date('start_date');
                $table->date('end_date');
                $table->string('reason', 255)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'start_date', 'end_date'], 'teacher_exception_date_idx');
            });
        }

        if (! Schema::hasColumn('schedule_change_requests', 'scope')) {
            Schema::table('schedule_change_requests', function (Blueprint $table) {
                $table->string('scope', 20)->default('single')->after('requester_role');
            });
        }
        if (! Schema::hasColumn('schedule_change_requests', 'affected_schedules')) {
            Schema::table('schedule_change_requests', function (Blueprint $table) {
                $table->json('affected_schedules')->nullable()->after('proposed_end_at');
            });
        }

        if (! Schema::hasColumn('package_subjects', 'curriculum_chapter_id')) {
            Schema::table('package_subjects', function (Blueprint $table) {
                $table->foreignId('curriculum_chapter_id')->nullable()->after('curriculum_subject_id')
                    ->constrained('curriculum_chapters')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('package_subjects', 'curriculum_chapter_ids')) {
            Schema::table('package_subjects', function (Blueprint $table) {
                $table->json('curriculum_chapter_ids')->nullable()->after('curriculum_chapter_id');
            });
        }
        if (! Schema::hasColumn('package_subjects', 'learning_topic_ids')) {
            Schema::table('package_subjects', function (Blueprint $table) {
                $table->json('learning_topic_ids')->nullable()->after('subtopic');
            });
        }

        if (! Schema::hasTable('package_learning_topics')) {
            Schema::create('package_learning_topics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('package_subject_id')->constrained('package_subjects')->cascadeOnDelete();
                $table->foreignId('curriculum_chapter_id')->nullable()->constrained('curriculum_chapters')->nullOnDelete();
                $table->foreignId('learning_topic_id')->nullable()->constrained('learning_topics')->nullOnDelete();
                $table->string('chapter', 180)->nullable();
                $table->string('title', 220);
                $table->string('normalized_title', 220);
                $table->string('status', 30)->default('not_started');
                $table->boolean('needs_review')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(1);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['package_subject_id', 'learning_topic_id'], 'package_learning_topics_subject_catalog_unique');
                $table->index(['package_subject_id', 'status']);
                $table->index(['package_subject_id', 'normalized_title'], 'package_learning_topics_subject_title_idx');
            });
        }

        if (! Schema::hasTable('package_session_topic_logs')) {
            Schema::create('package_session_topic_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('package_session_id')->constrained('package_sessions')->cascadeOnDelete();
                $table->foreignId('package_learning_topic_id')->constrained('package_learning_topics')->cascadeOnDelete();
                $table->foreignId('learning_progress_report_id')->nullable()
                    ->constrained('learning_progress_reports')->nullOnDelete();
                $table->string('activity_type', 20)->default('taught');
                $table->string('status_before', 30);
                $table->string('status_after', 30);
                $table->boolean('needs_review_after')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['package_session_id', 'package_learning_topic_id'], 'package_session_topic_unique');
            });
        }

        if (! Schema::hasColumn('learning_plans', 'package_subject_id')) {
            Schema::table('learning_plans', function (Blueprint $table) {
                $table->foreignId('package_subject_id')->nullable()->after('booking_id')
                    ->constrained('package_subjects')->nullOnDelete();
                $table->index(['package_subject_id', 'student_id']);
            });
        }

        DB::table('learning_plans')->whereNull('package_subject_id')->orderBy('id')->chunkById(100, function ($plans) {
            foreach ($plans as $plan) {
                $packageSubjectId = DB::table('bookings')
                    ->join('booking_requests', 'booking_requests.id', '=', 'bookings.booking_request_id')
                    ->where('bookings.id', $plan->booking_id)
                    ->value('booking_requests.package_subject_id');
                if ($packageSubjectId) {
                    DB::table('learning_plans')->where('id', $plan->id)->update(['package_subject_id' => $packageSubjectId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_session_topic_logs');
        Schema::dropIfExists('package_learning_topics');

        if (Schema::hasColumn('learning_plans', 'package_subject_id')) {
            Schema::table('learning_plans', fn (Blueprint $table) => $table->dropConstrainedForeignId('package_subject_id'));
        }
        if (Schema::hasColumn('package_subjects', 'learning_topic_ids')) {
            Schema::table('package_subjects', fn (Blueprint $table) => $table->dropColumn('learning_topic_ids'));
        }
        if (Schema::hasColumn('package_subjects', 'curriculum_chapter_ids')) {
            Schema::table('package_subjects', fn (Blueprint $table) => $table->dropColumn('curriculum_chapter_ids'));
        }
        if (Schema::hasColumn('package_subjects', 'curriculum_chapter_id')) {
            Schema::table('package_subjects', fn (Blueprint $table) => $table->dropConstrainedForeignId('curriculum_chapter_id'));
        }
        if (Schema::hasColumn('schedule_change_requests', 'affected_schedules')) {
            Schema::table('schedule_change_requests', fn (Blueprint $table) => $table->dropColumn('affected_schedules'));
        }
        if (Schema::hasColumn('schedule_change_requests', 'scope')) {
            Schema::table('schedule_change_requests', fn (Blueprint $table) => $table->dropColumn('scope'));
        }
        Schema::dropIfExists('teacher_availability_exceptions');
    }
};
