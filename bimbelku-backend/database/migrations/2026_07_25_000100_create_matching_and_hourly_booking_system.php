<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('maps_link');
            }
            if (! Schema::hasColumn('users', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
        });

        Schema::table('teacher_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_profiles', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'max_travel_km')) {
                $table->unsignedSmallInteger('max_travel_km')->default(12);
            }
            if (! Schema::hasColumn('teacher_profiles', 'assignment_count')) {
                $table->unsignedInteger('assignment_count')->default(0);
            }
            if (! Schema::hasColumn('teacher_profiles', 'last_assigned_at')) {
                $table->timestamp('last_assigned_at')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'is_accepting_requests')) {
                $table->boolean('is_accepting_requests')->default(true);
            }
        });

        Schema::table('teacher_subjects', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_subjects', 'levels')) {
                $table->json('levels')->nullable();
            }
        });

        Schema::table('teacher_availabilities', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_availabilities', 'start_time')) {
                $table->time('start_time')->nullable();
            }
            if (! Schema::hasColumn('teacher_availabilities', 'end_time')) {
                $table->time('end_time')->nullable();
            }
        });

        if (! Schema::hasTable('hourly_rates')) {
            Schema::create('hourly_rates', function (Blueprint $table) {
                $table->id();
                $table->string('subject_name', 120);
                $table->string('education_level', 30)->nullable();
                $table->enum('class_type', ['private', 'group']);
                $table->decimal('amount', 12, 2);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['subject_name', 'education_level', 'class_type', 'is_active'], 'hourly_rates_lookup_idx');
            });
        }

        if (! Schema::hasTable('booking_requests')) {
            Schema::create('booking_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('matched_teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject_name', 120);
                $table->string('education_level', 30);
                $table->string('grade', 50)->nullable();
                $table->text('topic')->nullable();
                $table->enum('learning_mode', ['online', 'offline']);
                $table->enum('class_type', ['private', 'group']);
                $table->date('scheduled_date');
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedTinyInteger('duration_hours');
                $table->text('address')->nullable();
                $table->string('maps_link')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('status', 40)->default('matching');
                $table->unsignedSmallInteger('matching_attempts')->default(0);
                $table->decimal('hourly_rate', 12, 2)->nullable();
                $table->decimal('total_amount', 12, 2)->nullable();
                $table->timestamp('teacher_response_deadline')->nullable();
                $table->timestamp('payment_due_at')->nullable();
                $table->timestamps();
                $table->index(['student_id', 'status']);
                $table->index(['scheduled_date', 'start_time', 'status']);
                $table->index(['subject_name', 'education_level', 'learning_mode', 'class_type'], 'booking_match_idx');
            });
        }

        if (! Schema::hasTable('teacher_offers')) {
            Schema::create('teacher_offers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 30)->default('pending');
                $table->decimal('distance_km', 8, 2)->nullable();
                $table->timestamp('offered_at');
                $table->timestamp('expires_at');
                $table->timestamp('responded_at')->nullable();
                $table->string('rejection_reason')->nullable();
                $table->timestamps();
                $table->unique(['booking_request_id', 'teacher_id']);
                $table->index(['teacher_id', 'status', 'expires_at']);
            });
        }

        if (! Schema::hasTable('bookings')) {
            Schema::create('bookings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_request_id')->unique()->constrained()->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->dateTime('start_at');
                $table->dateTime('end_at');
                $table->unsignedTinyInteger('duration_hours');
                $table->enum('learning_mode', ['online', 'offline']);
                $table->enum('class_type', ['private', 'group']);
                $table->decimal('hourly_rate', 12, 2);
                $table->decimal('total_amount', 12, 2);
                $table->string('status', 40)->default('awaiting_payment');
                $table->timestamp('payment_due_at')->nullable();
                $table->text('address')->nullable();
                $table->string('maps_link')->nullable();
                $table->timestamps();
                $table->index(['teacher_id', 'start_at', 'end_at', 'status'], 'teacher_booking_overlap_idx');
                $table->index(['student_id', 'status']);
            });
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'default_private_hourly_rate'],
                ['value' => '40000', 'created_at' => now(), 'updated_at' => now()]
            );
            DB::table('settings')->updateOrInsert(
                ['key' => 'default_group_hourly_rate'],
                ['value' => '40000', 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('teacher_offers');
        Schema::dropIfExists('booking_requests');
        Schema::dropIfExists('hourly_rates');

        Schema::table('teacher_availabilities', function (Blueprint $table) {
            $columns = array_filter(['start_time', 'end_time'], fn ($column) => Schema::hasColumn('teacher_availabilities', $column));
            if ($columns) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('teacher_subjects', function (Blueprint $table) {
            if (Schema::hasColumn('teacher_subjects', 'levels')) {
                $table->dropColumn('levels');
            }
        });

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $columns = array_filter(
                ['latitude', 'longitude', 'max_travel_km', 'assignment_count', 'last_assigned_at', 'is_accepting_requests'],
                fn ($column) => Schema::hasColumn('teacher_profiles', $column)
            );
            if ($columns) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter(['latitude', 'longitude'], fn ($column) => Schema::hasColumn('users', $column));
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
