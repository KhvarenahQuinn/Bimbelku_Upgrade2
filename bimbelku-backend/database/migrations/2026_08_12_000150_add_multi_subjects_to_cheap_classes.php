<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cheap_class_templates', 'cheap_classes'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'subjects')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->json('subjects')->nullable()->after('curriculum_chapter_id');
            });

            DB::table($tableName)
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($tableName) {
                    foreach ($rows as $row) {
                        $item = [
                            'curriculum_subject_id' => $row->curriculum_subject_id ?? null,
                            'subject_name' => $row->subject_name ?? null,
                            'curriculum_chapter_id' => $row->curriculum_chapter_id ?? null,
                            'chapter' => $row->chapter ?? null,
                        ];
                        if (! $item['subject_name']) {
                            continue;
                        }
                        DB::table($tableName)->where('id', $row->id)->update([
                            'subjects' => json_encode([$item], JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        foreach (['cheap_class_templates', 'cheap_classes'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'subjects')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('subjects');
                });
            }
        }
    }
};
