<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cheap_class_sessions')) {
            return;
        }

        $hasAttended = Schema::hasColumn('cheap_class_sessions', 'attended_participants_count');
        $hasSubmittedAt = Schema::hasColumn('cheap_class_sessions', 'report_submitted_at');
        $hasSubmittedBy = Schema::hasColumn('cheap_class_sessions', 'report_submitted_by');
        $hasReviewNotes = Schema::hasColumn('cheap_class_sessions', 'admin_review_notes');
        $hasReviewedAt = Schema::hasColumn('cheap_class_sessions', 'admin_reviewed_at');
        $hasReviewedBy = Schema::hasColumn('cheap_class_sessions', 'admin_reviewed_by');
        $hasRevisionCount = Schema::hasColumn('cheap_class_sessions', 'report_revision_count');

        if ($hasAttended && $hasSubmittedAt && $hasSubmittedBy && $hasReviewNotes && $hasReviewedAt && $hasReviewedBy && $hasRevisionCount) {
            return;
        }

        Schema::table('cheap_class_sessions', function (Blueprint $table) use (
            $hasAttended,
            $hasSubmittedAt,
            $hasSubmittedBy,
            $hasReviewNotes,
            $hasReviewedAt,
            $hasReviewedBy,
            $hasRevisionCount
        ) {
            if (! $hasAttended) {
                $table->unsignedSmallInteger('attended_participants_count')->nullable()->after('progress_recorded_by');
            }
            if (! $hasSubmittedAt) {
                $table->dateTime('report_submitted_at')->nullable()->after('attended_participants_count');
            }
            if (! $hasSubmittedBy) {
                $table->foreignId('report_submitted_by')->nullable()->after('report_submitted_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! $hasReviewNotes) {
                $table->text('admin_review_notes')->nullable()->after('report_submitted_by');
            }
            if (! $hasReviewedAt) {
                $table->dateTime('admin_reviewed_at')->nullable()->after('admin_review_notes');
            }
            if (! $hasReviewedBy) {
                $table->foreignId('admin_reviewed_by')->nullable()->after('admin_reviewed_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! $hasRevisionCount) {
                $table->unsignedSmallInteger('report_revision_count')->default(0)->after('admin_reviewed_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cheap_class_sessions')) {
            return;
        }

        $hasReviewedBy = Schema::hasColumn('cheap_class_sessions', 'admin_reviewed_by');
        $hasSubmittedBy = Schema::hasColumn('cheap_class_sessions', 'report_submitted_by');
        $columns = collect([
            'attended_participants_count',
            'report_submitted_at',
            'admin_review_notes',
            'admin_reviewed_at',
            'report_revision_count',
        ])->filter(fn (string $column) => Schema::hasColumn('cheap_class_sessions', $column))->all();

        if (! $hasReviewedBy && ! $hasSubmittedBy && $columns === []) {
            return;
        }

        Schema::table('cheap_class_sessions', function (Blueprint $table) use ($hasReviewedBy, $hasSubmittedBy, $columns) {
            if ($hasReviewedBy) {
                $table->dropConstrainedForeignId('admin_reviewed_by');
            }
            if ($hasSubmittedBy) {
                $table->dropConstrainedForeignId('report_submitted_by');
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
