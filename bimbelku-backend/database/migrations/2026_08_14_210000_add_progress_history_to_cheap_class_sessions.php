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

        $hasUpdates = Schema::hasColumn('cheap_class_sessions', 'progress_updates');
        $hasNotes = Schema::hasColumn('cheap_class_sessions', 'progress_notes');
        $hasRecordedAt = Schema::hasColumn('cheap_class_sessions', 'progress_recorded_at');
        $hasRecordedBy = Schema::hasColumn('cheap_class_sessions', 'progress_recorded_by');

        if ($hasUpdates && $hasNotes && $hasRecordedAt && $hasRecordedBy) {
            return;
        }

        Schema::table('cheap_class_sessions', function (Blueprint $table) use ($hasUpdates, $hasNotes, $hasRecordedAt, $hasRecordedBy) {
            if (! $hasUpdates) {
                $table->json('progress_updates')->nullable()->after('status');
            }
            if (! $hasNotes) {
                $table->text('progress_notes')->nullable()->after('progress_updates');
            }
            if (! $hasRecordedAt) {
                $table->dateTime('progress_recorded_at')->nullable()->after('progress_notes');
            }
            if (! $hasRecordedBy) {
                $table->foreignId('progress_recorded_by')->nullable()->after('progress_recorded_at')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cheap_class_sessions')) {
            return;
        }

        $hasRecordedBy = Schema::hasColumn('cheap_class_sessions', 'progress_recorded_by');
        $columns = collect(['progress_updates', 'progress_notes', 'progress_recorded_at'])
            ->filter(fn (string $column) => Schema::hasColumn('cheap_class_sessions', $column))
            ->all();

        if (! $hasRecordedBy && $columns === []) {
            return;
        }

        Schema::table('cheap_class_sessions', function (Blueprint $table) use ($hasRecordedBy, $columns) {
            if ($hasRecordedBy) {
                $table->dropConstrainedForeignId('progress_recorded_by');
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
