<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('curriculum_subjects')) {
            Schema::create('curriculum_subjects', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('normalized_name', 150)->unique();
                $table->string('group_name', 80)->default('Wajib');
                $table->json('education_levels')->nullable();
                $table->json('grades')->nullable();
                $table->boolean('is_elective')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('curriculum_name', 100)->default('Kurikulum Merdeka');
                $table->string('edition', 80)->nullable();
                $table->string('source_url', 500)->nullable();
                $table->timestamps();
                $table->index(['is_active', 'group_name']);
            });
        }

        if (! Schema::hasTable('curriculum_chapters')) {
            Schema::create('curriculum_chapters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('curriculum_subject_id')
                    ->constrained('curriculum_subjects')
                    ->cascadeOnDelete();
                $table->string('education_level', 30);
                $table->string('grade', 50);
                $table->string('title', 220);
                $table->string('normalized_title', 220);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('source_reference', 500)->nullable();
                $table->timestamps();
                $table->unique(
                    ['curriculum_subject_id', 'grade', 'normalized_title'],
                    'curriculum_chapters_grade_title_unique'
                );
                $table->index(
                    ['education_level', 'grade', 'is_active'],
                    'curriculum_chapters_catalog_idx'
                );
            });
        }

        $this->addSubjectReference('hourly_rates');
        $this->addSubjectReference('teacher_subjects');
        $this->addSubjectReference('booking_requests');

        if (Schema::hasTable('settings')) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'payment_window_minutes'],
                ['value' => '30', 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->openLegacyTeacherDecisions();
    }

    public function down(): void
    {
        foreach (['booking_requests', 'teacher_subjects', 'hourly_rates'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'curriculum_subject_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('curriculum_subject_id');
                });
            }
        }

        Schema::dropIfExists('curriculum_chapters');
        Schema::dropIfExists('curriculum_subjects');
    }

    private function addSubjectReference(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'curriculum_subject_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->foreignId('curriculum_subject_id')
                ->nullable()
                ->constrained('curriculum_subjects')
                ->nullOnDelete();
        });
    }

    private function openLegacyTeacherDecisions(): void
    {
        if (
            ! Schema::hasTable('bookings')
            || ! Schema::hasTable('orders')
            || ! Schema::hasTable('booking_participants')
            || ! Schema::hasTable('booking_requests')
        ) {
            return;
        }

        $futureBookingIds = DB::table('bookings')
            ->where('status', 'teacher_selected')
            ->where('start_at', '>', now())
            ->pluck('id');

        if ($futureBookingIds->isNotEmpty()) {
            DB::table('bookings')
                ->whereIn('id', $futureBookingIds)
                ->update([
                    'status' => 'awaiting_payment',
                    'payment_due_at' => now()->addMinutes(30),
                    'updated_at' => now(),
                ]);
            DB::table('orders')
                ->whereIn('booking_id', $futureBookingIds)
                ->where('status', 'teacher_decision')
                ->update(['status' => 'pending', 'updated_at' => now()]);
            DB::table('booking_participants')
                ->whereIn('booking_id', $futureBookingIds)
                ->where('status', 'teacher_decision')
                ->update(['status' => 'awaiting_payment', 'updated_at' => now()]);
            DB::table('booking_requests')
                ->whereIn('booking_id', $futureBookingIds)
                ->whereIn('status', ['teacher_selected', 'teacher_accepted_waiting_group'])
                ->update([
                    'status' => 'awaiting_payment',
                    'payment_due_at' => now()->addMinutes(30),
                    'updated_at' => now(),
                ]);
        }

        $pastBookingIds = DB::table('bookings')
            ->where('status', 'teacher_selected')
            ->where('start_at', '<=', now())
            ->pluck('id');

        if ($pastBookingIds->isEmpty()) {
            return;
        }

        DB::table('bookings')
            ->whereIn('id', $pastBookingIds)
            ->update(['status' => 'payment_expired', 'updated_at' => now()]);
        DB::table('orders')
            ->whereIn('booking_id', $pastBookingIds)
            ->where('status', 'teacher_decision')
            ->update(['status' => 'expired', 'updated_at' => now()]);
        DB::table('booking_participants')
            ->whereIn('booking_id', $pastBookingIds)
            ->where('status', 'teacher_decision')
            ->update(['status' => 'payment_expired', 'updated_at' => now()]);
    }
};
