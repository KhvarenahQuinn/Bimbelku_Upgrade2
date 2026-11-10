<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'session_pin_hash')) {
                $table->string('session_pin_hash', 255)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'session_pin_expires_at')) {
                $table->timestamp('session_pin_expires_at')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'session_started_at')) {
                $table->timestamp('session_started_at')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'session_ended_at')) {
                $table->timestamp('session_ended_at')->nullable();
            }
        });

        if (! Schema::hasTable('classroom_messages')) {
            Schema::create('classroom_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->text('body');
                $table->timestamps();
                $table->index(['booking_id', 'id'], 'classroom_messages_booking_id_idx');
            });
        }

        if (! Schema::hasTable('learning_plans')) {
            Schema::create('learning_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
                $table->text('initial_assessment');
                $table->text('strengths')->nullable();
                $table->text('challenges')->nullable();
                $table->text('learning_target');
                $table->text('success_indicator');
                $table->decimal('baseline_score', 5, 2)->nullable();
                $table->decimal('target_score', 5, 2)->nullable();
                $table->unsignedTinyInteger('progress_percent')->default(0);
                $table->string('status', 30)->default('waiting_student');
                $table->timestamp('assessed_at')->nullable();
                $table->timestamp('student_acknowledged_at')->nullable();
                $table->timestamps();
                $table->index(['student_id', 'status']);
                $table->index(['teacher_id', 'status']);
            });
        }

        if (! Schema::hasTable('learning_progress_reports')) {
            Schema::create('learning_progress_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedSmallInteger('session_number')->default(1);
                $table->text('material_covered');
                $table->text('mastered_skills');
                $table->text('difficulties')->nullable();
                $table->text('next_exercise');
                $table->string('attendance', 30)->default('present');
                $table->unsignedSmallInteger('actual_duration_minutes');
                $table->unsignedTinyInteger('progress_percent');
                $table->text('notes')->nullable();
                $table->timestamp('published_at');
                $table->timestamps();
                $table->unique(
                    ['booking_id', 'student_id', 'session_number'],
                    'learning_progress_booking_student_session_unique'
                );
                $table->index(['student_id', 'published_at']);
            });
        }

        if (! Schema::hasTable('session_attendances')) {
            Schema::create('session_attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 20);
                $table->timestamp('check_in_at');
                $table->timestamp('check_out_at')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->unsignedSmallInteger('accuracy_meters')->nullable();
                $table->timestamp('pin_verified_at')->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->timestamps();
                $table->unique(['booking_id', 'user_id']);
                $table->index(['booking_id', 'check_in_at']);
            });
        }

        foreach ([
            'session_pin_minutes' => '10',
            'session_checkin_before_minutes' => '30',
            'session_checkin_after_minutes' => '60',
            'session_location_tolerance_km' => '1',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        // Data belajar dan kehadiran dipertahankan saat rollback agar riwayat tidak hilang.
    }
};
