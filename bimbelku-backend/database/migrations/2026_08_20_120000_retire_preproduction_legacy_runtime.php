<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createChapterNativeTables();
        $this->backfillChapterProgress();
        $this->retireLegacyTablesAndColumns();
        $this->retireLegacyConfiguration();
    }

    private function createChapterNativeTables(): void
    {
        if (! Schema::hasTable('package_chapters')) {
            Schema::create('package_chapters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('package_subject_id')->constrained('package_subjects')->cascadeOnDelete();
                $table->foreignId('curriculum_chapter_id')->nullable()->constrained('curriculum_chapters')->nullOnDelete();
                $table->string('title', 220);
                $table->string('status', 30)->default('not_started');
                $table->boolean('needs_review')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(1);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['package_subject_id', 'status'], 'package_chapters_subject_status_idx');
                $table->index(['package_subject_id', 'curriculum_chapter_id'], 'package_chapters_subject_catalog_idx');
            });
        }

        if (! Schema::hasTable('package_session_chapter_logs')) {
            Schema::create('package_session_chapter_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('package_session_id')->constrained('package_sessions')->cascadeOnDelete();
                $table->foreignId('package_chapter_id')->constrained('package_chapters')->cascadeOnDelete();
                $table->foreignId('learning_progress_report_id')->nullable()
                    ->constrained('learning_progress_reports')->nullOnDelete();
                $table->string('activity_type', 20)->default('taught');
                $table->string('status_before', 30)->default('not_started');
                $table->string('status_after', 30)->default('not_started');
                $table->boolean('needs_review_after')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['package_session_id', 'package_chapter_id'], 'package_session_chapter_unique');
            });
        }
    }

    private function backfillChapterProgress(): void
    {
        if (! Schema::hasTable('package_learning_topics')) {
            return;
        }

        $legacyRows = DB::table('package_learning_topics')->orderBy('id')->get();
        if ($legacyRows->isEmpty()) {
            return;
        }

        $catalogTitles = Schema::hasTable('curriculum_chapters')
            ? DB::table('curriculum_chapters')->pluck('title', 'id')
            : collect();

        $groups = $legacyRows->groupBy(function ($row) {
            $catalogKey = $row->curriculum_chapter_id ? 'catalog:'.$row->curriculum_chapter_id : null;
            $fallback = mb_strtolower(trim((string) ($row->chapter ?: $row->title ?: 'Materi belajar')));

            return $row->package_subject_id.'|'.($catalogKey ?: 'title:'.$fallback);
        });

        $legacyToChapter = [];
        foreach ($groups as $rows) {
            $first = $rows->first();
            $title = $first->curriculum_chapter_id
                ? (string) ($catalogTitles[$first->curriculum_chapter_id] ?? $first->chapter ?? $first->title)
                : (string) ($first->chapter ?: $first->title ?: 'Materi belajar');
            $statuses = $rows->pluck('status')->map(fn ($value) => (string) $value);
            $allCompleted = $statuses->isNotEmpty() && $statuses->every(fn ($value) => $value === 'completed');
            $hasProgress = $statuses->contains(fn ($value) => in_array($value, ['in_progress', 'completed', 'review_needed'], true));
            $status = $allCompleted ? 'completed' : ($hasProgress ? 'in_progress' : 'not_started');
            $startedAt = $rows->pluck('started_at')->filter()->sort()->first();
            $completedAt = $status === 'completed' ? $rows->pluck('completed_at')->filter()->sort()->last() : null;
            $now = now();

            $chapterId = DB::table('package_chapters')->insertGetId([
                'package_subject_id' => $first->package_subject_id,
                'curriculum_chapter_id' => $first->curriculum_chapter_id,
                'title' => trim($title) !== '' ? trim($title) : 'Materi belajar',
                'status' => $status,
                'needs_review' => $rows->contains(fn ($row) => (bool) $row->needs_review),
                'sort_order' => (int) ($rows->pluck('sort_order')->filter(fn ($value) => $value !== null)->min() ?? 1),
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'created_at' => $rows->pluck('created_at')->filter()->sort()->first() ?: $now,
                'updated_at' => $rows->pluck('updated_at')->filter()->sort()->last() ?: $now,
            ]);

            foreach ($rows as $row) {
                $legacyToChapter[(int) $row->id] = $chapterId;
            }
        }

        if (! Schema::hasTable('package_session_topic_logs')) {
            return;
        }

        $logGroups = DB::table('package_session_topic_logs')
            ->orderBy('id')
            ->get()
            ->filter(fn ($row) => isset($legacyToChapter[(int) $row->package_learning_topic_id]))
            ->groupBy(fn ($row) => $row->package_session_id.'|'.$legacyToChapter[(int) $row->package_learning_topic_id]);

        foreach ($logGroups as $logs) {
            $first = $logs->first();
            $last = $logs->last();
            $chapterId = $legacyToChapter[(int) $first->package_learning_topic_id];
            $reportId = $logs->pluck('learning_progress_report_id')->filter()->first();
            $notes = $logs->pluck('notes')->filter()->map(fn ($value) => trim((string) $value))->unique()->implode("\n");

            DB::table('package_session_chapter_logs')->updateOrInsert(
                [
                    'package_session_id' => $first->package_session_id,
                    'package_chapter_id' => $chapterId,
                ],
                [
                    'learning_progress_report_id' => $reportId ?: null,
                    'activity_type' => (string) ($last->activity_type ?: $first->activity_type ?: 'taught'),
                    'status_before' => (string) ($first->status_before ?: 'not_started'),
                    'status_after' => (string) ($last->status_after ?: 'not_started'),
                    'needs_review_after' => $logs->contains(fn ($row) => (bool) $row->needs_review_after),
                    'notes' => $notes !== '' ? $notes : null,
                    'created_at' => $logs->pluck('created_at')->filter()->sort()->first() ?: now(),
                    'updated_at' => $logs->pluck('updated_at')->filter()->sort()->last() ?: now(),
                ]
            );
        }
    }

    private function retireLegacyTablesAndColumns(): void
    {
        Schema::dropIfExists('package_session_topic_logs');
        Schema::dropIfExists('package_learning_topics');
        Schema::dropIfExists('learning_plans');
        Schema::dropIfExists('learning_topics');

        Schema::dropIfExists('group_members');
        $this->dropForeignColumn('booking_requests', 'group_pool_id');
        $this->dropForeignColumn('bookings', 'group_pool_id');
        Schema::dropIfExists('group_pools');

        $this->dropColumns('package_subjects', ['learning_topic_ids', 'subtopic']);
        $this->dropColumns('booking_requests', ['subtopic', 'topic', 'attachment']);
        $this->dropColumns('bookings', [
            'session_pin_hash',
            'session_pin_expires_at',
            'completion_evidence',
            'completion_capture_source',
            'completion_captured_at',
        ]);
        $this->dropColumns('session_attendances', ['pin_verified_at']);
        $this->dropColumns('teacher_subjects', ['is_group_active']);
        $this->dropColumns('cheap_class_templates', ['subtopic']);
        $this->dropColumns('cheap_classes', ['subtopic']);
    }

    private function retireLegacyConfiguration(): void
    {
        if (Schema::hasTable('hourly_rates')) {
            DB::table('hourly_rates')->where('class_type', 'group')->delete();
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->whereIn('key', [
                'session_pin_minutes',
                'completion_upload_grace_minutes',
                'default_group_hourly_rate',
                'default_group_online_rate',
                'default_group_offline_rate',
                'group_min_participants',
                'group_max_participants',
            ])->delete();
        }
    }

    private function dropForeignColumn(string $tableName, string $column): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column) {
            $table->dropConstrainedForeignId($column);
        });
    }

    private function dropColumns(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($tableName, $column)));
        if ($existing === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: this migration merges retired Subbab history
        // into Bab-native progress and removes pre-production-only legacy systems.
        // Restore the database backup taken before deployment if rollback is needed.
    }
};
