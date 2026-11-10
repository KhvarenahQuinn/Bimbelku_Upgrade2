<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->upgradeTemplateTable();
        $this->upgradeClassTable();

        if (! Schema::hasTable('cheap_class_sessions')) {
            Schema::create('cheap_class_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cheap_class_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('session_number');
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->string('status', 30)->default('scheduled');
                $table->timestamps();
                $table->unique(['cheap_class_id', 'session_number']);
                $table->index(['starts_at', 'ends_at'], 'cheap_class_sessions_schedule_idx');
            });
        }

        if (Schema::hasTable('cheap_class_templates')) {
            DB::table('cheap_class_templates')->whereNull('price_per_session')->update([
                'price_per_session' => DB::raw('price_per_student'),
            ]);
        }
        if (Schema::hasTable('cheap_classes')) {
            DB::table('cheap_classes')->whereNull('price_per_session')->update([
                'price_per_session' => DB::raw('price_per_student'),
            ]);
        }

        if (Schema::hasTable('cheap_classes') && Schema::hasTable('cheap_class_sessions')) {
            DB::table('cheap_classes')
                ->orderBy('id')
                ->chunkById(200, function ($classes) {
                    foreach ($classes as $class) {
                        DB::table('cheap_class_sessions')->updateOrInsert(
                            ['cheap_class_id' => $class->id, 'session_number' => 1],
                            [
                                'starts_at' => $class->starts_at,
                                'ends_at' => $class->ends_at,
                                'status' => 'scheduled',
                                'created_at' => $class->created_at ?? now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                });
        }
    }

    private function upgradeTemplateTable(): void
    {
        if (! Schema::hasTable('cheap_class_templates')) {
            return;
        }
        if (! Schema::hasColumn('cheap_class_templates', 'curriculum_chapter_id')) {
            Schema::table('cheap_class_templates', function (Blueprint $table) {
                $table->foreignId('curriculum_chapter_id')->nullable()
                    ->after('curriculum_subject_id')->constrained('curriculum_chapters')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('cheap_class_templates', 'session_count')) {
            Schema::table('cheap_class_templates', function (Blueprint $table) {
                $table->unsignedSmallInteger('session_count')->default(1)->after('duration_minutes');
            });
        }
        if (! Schema::hasColumn('cheap_class_templates', 'price_per_session')) {
            Schema::table('cheap_class_templates', function (Blueprint $table) {
                $table->decimal('price_per_session', 12, 2)->nullable()->after('price_per_student');
            });
        }
        if (! Schema::hasColumn('cheap_class_templates', 'custom_price_per_student')) {
            Schema::table('cheap_class_templates', function (Blueprint $table) {
                $table->decimal('custom_price_per_student', 12, 2)->nullable()->after('price_per_session');
            });
        }
    }

    private function upgradeClassTable(): void
    {
        if (! Schema::hasTable('cheap_classes')) {
            return;
        }
        if (! Schema::hasColumn('cheap_classes', 'curriculum_chapter_id')) {
            Schema::table('cheap_classes', function (Blueprint $table) {
                $table->foreignId('curriculum_chapter_id')->nullable()
                    ->after('curriculum_subject_id')->constrained('curriculum_chapters')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('cheap_classes', 'session_count')) {
            Schema::table('cheap_classes', function (Blueprint $table) {
                $table->unsignedSmallInteger('session_count')->default(1)->after('ends_at');
            });
        }
        if (! Schema::hasColumn('cheap_classes', 'price_per_session')) {
            Schema::table('cheap_classes', function (Blueprint $table) {
                $table->decimal('price_per_session', 12, 2)->nullable()->after('price_per_student');
            });
        }
        if (! Schema::hasColumn('cheap_classes', 'custom_price_per_student')) {
            Schema::table('cheap_classes', function (Blueprint $table) {
                $table->decimal('custom_price_per_student', 12, 2)->nullable()->after('price_per_session');
            });
        }
    }

    public function down(): void
    {
        // Riwayat kelas dan harga dipertahankan saat rollback.
    }
};
