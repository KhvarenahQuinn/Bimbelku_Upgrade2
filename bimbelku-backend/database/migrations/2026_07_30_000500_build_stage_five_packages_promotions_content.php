<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('package_plans')) {
            Schema::create('package_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 100)->unique();
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('session_count');
                $table->unsignedSmallInteger('validity_days');
                $table->unsignedTinyInteger('maximum_subjects')->default(1);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('promotions')) {
            Schema::create('promotions', function (Blueprint $table) {
                $table->id();
                $table->string('title', 160);
                $table->string('code', 60)->nullable()->unique();
                $table->text('description')->nullable();
                $table->enum('discount_type', ['percentage', 'fixed']);
                $table->decimal('discount_value', 12, 2);
                $table->decimal('maximum_discount', 12, 2)->nullable();
                $table->decimal('minimum_purchase', 12, 2)->default(0);
                $table->unsignedInteger('total_quota')->nullable();
                $table->unsignedSmallInteger('per_user_limit')->default(1);
                $table->json('target_plan_ids')->nullable();
                $table->json('target_levels')->nullable();
                $table->json('target_subjects')->nullable();
                $table->json('target_modes')->nullable();
                $table->boolean('new_students_only')->default(false);
                $table->boolean('claim_required')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();
                $table->index(['is_active', 'starts_at', 'ends_at']);
            });
        }

        if (! Schema::hasTable('learning_time_slots')) {
            Schema::create('learning_time_slots', function (Blueprint $table) {
                $table->id();
                $table->time('start_time')->unique();
                $table->string('label', 80)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['is_active', 'sort_order']);
            });
        }

        if (! Schema::hasTable('learning_packages')) {
            Schema::create('learning_packages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('package_plan_id')->constrained('package_plans')->restrictOnDelete();
                $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
                $table->foreignId('renewal_of_id')->nullable()->constrained('learning_packages')->nullOnDelete();
                $table->string('package_code', 40)->unique();
                $table->string('education_level', 30);
                $table->string('grade', 50)->nullable();
                $table->enum('learning_mode', ['online', 'offline']);
                $table->text('address')->nullable();
                $table->string('maps_link', 500)->nullable();
                $table->string('status', 40)->default('matching');
                $table->unsignedSmallInteger('total_sessions');
                $table->unsignedSmallInteger('used_sessions')->default(0);
                $table->decimal('subtotal_amount', 12, 2);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('total_amount', 12, 2);
                $table->timestamp('payment_due_at')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['student_id', 'status', 'expires_at']);
            });
        }

        if (! Schema::hasTable('package_subjects')) {
            Schema::create('package_subjects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('learning_package_id')->constrained('learning_packages')->cascadeOnDelete();
                $table->foreignId('curriculum_subject_id')->nullable()->constrained('curriculum_subjects')->nullOnDelete();
                $table->foreignId('assigned_teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('preferred_teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject_name', 120);
                $table->string('chapter', 180)->nullable();
                $table->string('subtopic', 220)->nullable();
                $table->text('learning_goal')->nullable();
                $table->unsignedSmallInteger('allocated_sessions');
                $table->decimal('unit_price', 12, 2);
                $table->decimal('subtotal_amount', 12, 2);
                $table->string('status', 40)->default('matching');
                $table->timestamps();
                $table->unique(['learning_package_id', 'subject_name']);
                $table->index(['assigned_teacher_id', 'status']);
            });
        }

        if (! Schema::hasTable('package_sessions')) {
            Schema::create('package_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('package_subject_id')->constrained('package_subjects')->cascadeOnDelete();
                $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
                $table->unsignedSmallInteger('sequence');
                $table->dateTime('scheduled_start_at');
                $table->dateTime('scheduled_end_at');
                $table->string('status', 30)->default('planned');
                $table->timestamps();
                $table->unique(['package_subject_id', 'sequence']);
                $table->index(['scheduled_start_at', 'status']);
            });
        }

        if (! Schema::hasTable('promotion_claims')) {
            Schema::create('promotion_claims', function (Blueprint $table) {
                $table->id();
                $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('learning_package_id')->nullable()->constrained('learning_packages')->nullOnDelete();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->string('status', 30)->default('available');
                $table->timestamp('claimed_at');
                $table->timestamp('reserved_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
                $table->index(['promotion_id', 'status']);
            });
        }

        if (! Schema::hasTable('dynamic_banners')) {
            Schema::create('dynamic_banners', function (Blueprint $table) {
                $table->id();
                $table->string('title', 160);
                $table->string('description', 300)->nullable();
                $table->string('button_text', 60)->nullable();
                $table->string('image_path')->nullable();
                $table->string('audience', 20)->default('student');
                $table->enum('destination_kind', ['internal', 'external'])->default('internal');
                $table->string('destination_url', 500);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();
                $table->index(['audience', 'is_active', 'sort_order']);
            });
        }

        if (! Schema::hasTable('tutorials')) {
            Schema::create('tutorials', function (Blueprint $table) {
                $table->id();
                $table->string('role', 20);
                $table->string('context', 100)->default('dashboard');
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['role', 'context', 'is_active']);
            });
        }

        if (! Schema::hasTable('tutorial_steps')) {
            Schema::create('tutorial_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tutorial_id')->constrained('tutorials')->cascadeOnDelete();
                $table->string('title', 160);
                $table->text('body');
                $table->string('image_path')->nullable();
                $table->json('callout')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['tutorial_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('package_renewals')) {
            Schema::create('package_renewals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('old_package_id')->constrained('learning_packages')->cascadeOnDelete();
                $table->foreignId('new_package_id')->constrained('learning_packages')->cascadeOnDelete();
                $table->foreignId('old_package_subject_id')->nullable()->constrained('package_subjects')->nullOnDelete();
                $table->foreignId('old_teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 30)->default('requested');
                $table->timestamps();
                $table->unique(['new_package_id', 'old_package_subject_id']);
            });
        }

        Schema::table('booking_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_requests', 'package_subject_id')) {
                $table->foreignId('package_subject_id')
                    ->nullable()
                    ->constrained('package_subjects')
                    ->nullOnDelete();
                $table->index(['package_subject_id', 'status']);
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'learning_package_id')) {
                $table->foreignId('learning_package_id')
                    ->nullable()
                    ->constrained('learning_packages')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'promotion_id')) {
                $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'subtotal_amount')) {
                $table->decimal('subtotal_amount', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('orders', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        // Riwayat paket, promosi, tagihan, dan pembelajaran tidak dihapus otomatis.
    }
};
