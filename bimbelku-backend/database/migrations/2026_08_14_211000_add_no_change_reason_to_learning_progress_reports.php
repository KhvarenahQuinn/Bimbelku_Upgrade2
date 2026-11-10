<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_progress_reports')) {
            return;
        }

        $hasNoChange = Schema::hasColumn('learning_progress_reports', 'no_material_change');
        $hasReason = Schema::hasColumn('learning_progress_reports', 'no_change_reason');
        if ($hasNoChange && $hasReason) {
            return;
        }

        Schema::table('learning_progress_reports', function (Blueprint $table) use ($hasNoChange, $hasReason) {
            if (! $hasNoChange) {
                $table->boolean('no_material_change')->default(false)->after('progress_percent');
            }
            if (! $hasReason) {
                $table->string('no_change_reason', 40)->nullable()->after('no_material_change');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('learning_progress_reports')) {
            return;
        }
        $columns = [];
        if (Schema::hasColumn('learning_progress_reports', 'no_change_reason')) {
            $columns[] = 'no_change_reason';
        }
        if (Schema::hasColumn('learning_progress_reports', 'no_material_change')) {
            $columns[] = 'no_material_change';
        }
        if ($columns !== []) {
            Schema::table('learning_progress_reports', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
