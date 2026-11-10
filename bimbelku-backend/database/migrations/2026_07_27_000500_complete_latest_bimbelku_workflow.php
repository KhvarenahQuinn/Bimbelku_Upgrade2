<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendUsers();
        $this->extendTeacherProfiles();
        $this->extendHourlyRates();
        $this->createLearningTopics();
        $this->createGroupPools();
        $this->extendBookingRequests();
        $this->extendTeacherOffers();
        $this->extendBookings();
        $this->extendOrders();
        $this->createBookingParticipants();
        $this->createOperationalCases();
        $this->extendPayoutsAndRatings();
        $this->seedWorkflowSettings();
    }

    private function extendUsers(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'teacher_rejection_streak')) {
                $table->unsignedSmallInteger('teacher_rejection_streak')->default(0);
            }
            if (! Schema::hasColumn('users', 'last_teacher_rejection_at')) {
                $table->timestamp('last_teacher_rejection_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'search_cooldown_until')) {
                $table->timestamp('search_cooldown_until')->nullable();
            }
        });
    }

    private function extendTeacherProfiles(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_profiles', 'identity_document')) {
                $table->string('identity_document')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'live_selfie')) {
                $table->string('live_selfie')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'qualification_document')) {
                $table->string('qualification_document')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'certification_document')) {
                $table->string('certification_document')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'verification_notes')) {
                $table->text('verification_notes')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'verified_at')) {
                $table->timestamp('verified_at')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'verified_by')) {
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('teacher_profiles', 'suspended_until')) {
                $table->timestamp('suspended_until')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'no_response_streak')) {
                $table->unsignedSmallInteger('no_response_streak')->default(0);
            }
            if (! Schema::hasColumn('teacher_profiles', 'no_response_window_started_at')) {
                $table->timestamp('no_response_window_started_at')->nullable();
            }
        });
    }

    private function extendHourlyRates(): void
    {
        Schema::table('hourly_rates', function (Blueprint $table) {
            if (! Schema::hasColumn('hourly_rates', 'learning_mode')) {
                $table->string('learning_mode', 20)->nullable();
                $table->index(
                    ['subject_name', 'education_level', 'class_type', 'learning_mode', 'is_active'],
                    'hourly_rates_mode_lookup_idx'
                );
            }
        });
    }

    private function createLearningTopics(): void
    {
        if (Schema::hasTable('learning_topics')) {
            return;
        }

        Schema::create('learning_topics', function (Blueprint $table) {
            $table->id();
            $table->string('subject_name', 120);
            $table->string('education_level', 30);
            $table->string('grade', 50);
            $table->string('chapter', 180);
            $table->string('name', 220);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(
                ['subject_name', 'education_level', 'grade', 'is_active'],
                'learning_topics_catalog_idx'
            );
        });
    }

    private function createGroupPools(): void
    {
        if (! Schema::hasTable('group_pools')) {
            Schema::create('group_pools', function (Blueprint $table) {
                $table->id();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject_name', 120);
                $table->string('education_level', 30);
                $table->string('grade', 50)->nullable();
                $table->string('chapter', 180)->nullable();
                $table->string('subtopic', 220)->nullable();
                $table->text('topic')->nullable();
                $table->string('learning_mode', 20);
                $table->date('scheduled_date');
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedTinyInteger('duration_hours');
                $table->text('address')->nullable();
                $table->string('maps_link', 500)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->unsignedSmallInteger('minimum_participants')->default(2);
                $table->unsignedSmallInteger('maximum_participants')->default(5);
                $table->string('status', 40)->default('forming');
                $table->timestamp('join_deadline')->nullable();
                $table->timestamp('decision_deadline')->nullable();
                $table->timestamps();
                $table->index(
                    ['subject_name', 'education_level', 'scheduled_date', 'start_time', 'status'],
                    'group_pool_matching_idx'
                );
            });
        }
    }

    private function extendBookingRequests(): void
    {
        Schema::table('booking_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_requests', 'group_pool_id')) {
                $table->foreignId('group_pool_id')->nullable()->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('booking_requests', 'chapter')) {
                $table->string('chapter', 180)->nullable();
            }
            if (! Schema::hasColumn('booking_requests', 'subtopic')) {
                $table->string('subtopic', 220)->nullable();
            }
            if (! Schema::hasColumn('booking_requests', 'learning_goal')) {
                $table->text('learning_goal')->nullable();
            }
            if (! Schema::hasColumn('booking_requests', 'attachment')) {
                $table->string('attachment')->nullable();
            }
            if (! Schema::hasColumn('booking_requests', 'search_radius_km')) {
                $table->unsignedSmallInteger('search_radius_km')->default(3);
            }
            if (! Schema::hasColumn('booking_requests', 'search_started_at')) {
                $table->timestamp('search_started_at')->nullable();
            }
            if (! Schema::hasColumn('booking_requests', 'search_expires_at')) {
                $table->timestamp('search_expires_at')->nullable();
            }
            if (! Schema::hasColumn('booking_requests', 'teacher_decision_deadline')) {
                $table->timestamp('teacher_decision_deadline')->nullable();
            }
            if (! Schema::hasColumn('booking_requests', 'teacher_rejection_reason')) {
                $table->string('teacher_rejection_reason', 500)->nullable();
            }
            if (! Schema::hasColumn('booking_requests', 'booking_id')) {
                $table->unsignedBigInteger('booking_id')->nullable();
                $table->index('booking_id', 'booking_requests_booking_idx');
            }
        });

        if (! Schema::hasTable('group_members')) {
            Schema::create('group_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_pool_id')->constrained()->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('booking_request_id')->constrained()->cascadeOnDelete();
                $table->string('status', 40)->default('waiting');
                $table->timestamps();
                $table->unique(['group_pool_id', 'student_id']);
                $table->unique('booking_request_id');
            });
        }
    }

    private function extendTeacherOffers(): void
    {
        Schema::table('teacher_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_offers', 'no_response_penalty_applied')) {
                $table->boolean('no_response_penalty_applied')->default(false);
            }
        });
    }

    private function extendBookings(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'group_pool_id')) {
                $table->foreignId('group_pool_id')->nullable()->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('bookings', 'commission_percent')) {
                $table->decimal('commission_percent', 5, 2)->default(20);
            }
            if (! Schema::hasColumn('bookings', 'gross_amount')) {
                $table->decimal('gross_amount', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'teacher_net_amount')) {
                $table->decimal('teacher_net_amount', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'completion_evidence')) {
                $table->string('completion_evidence')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'meeting_link')) {
                $table->string('meeting_link', 500)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'completion_notes')) {
                $table->text('completion_notes')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'completion_submitted_at')) {
                $table->timestamp('completion_submitted_at')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'objection_deadline')) {
                $table->timestamp('objection_deadline')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'student_approved_at')) {
                $table->timestamp('student_approved_at')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'admin_review_required_at')) {
                $table->timestamp('admin_review_required_at')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'payout_status')) {
                $table->string('payout_status', 30)->default('locked');
            }
        });
    }

    private function extendOrders(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'booking_id')) {
                $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'payment_rejection_reason')) {
                $table->text('payment_rejection_reason')->nullable();
            }
            if (! Schema::hasColumn('orders', 'payment_submitted_at')) {
                $table->timestamp('payment_submitted_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'sender_account_number')) {
                $table->string('sender_account_number', 80)->nullable();
            }
            if (! Schema::hasColumn('orders', 'verified_at')) {
                $table->timestamp('verified_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'verified_by')) {
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    private function createBookingParticipants(): void
    {
        if (Schema::hasTable('booking_participants')) {
            return;
        }

        Schema::create('booking_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 40)->default('awaiting_payment');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'student_id']);
            $table->index(['student_id', 'status']);
        });
    }

    private function createOperationalCases(): void
    {
        if (! Schema::hasTable('session_reports')) {
            Schema::create('session_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reported_student_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 40);
                $table->string('incident_type', 120)->nullable();
                $table->text('chronology');
                $table->timestamp('incident_at')->nullable();
                $table->string('incident_location', 500)->nullable();
                $table->text('impact')->nullable();
                $table->string('evidence')->nullable();
                $table->string('status', 30)->default('pending');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('review_notes')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->index(['type', 'status']);
            });
        }

        if (! Schema::hasTable('booking_disputes')) {
            Schema::create('booking_disputes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->text('reason');
                $table->string('evidence')->nullable();
                $table->string('status', 30)->default('pending');
                $table->string('resolution', 40)->nullable();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('resolution_notes')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->unique(['booking_id', 'student_id'], 'booking_disputes_booking_student_unique');
                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('refunds')) {
            Schema::create('refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('reason', 180);
                $table->string('status', 30)->default('pending');
                $table->string('proof')->nullable();
                $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('processed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique('order_id');
                $table->index(['status', 'created_at']);
            });
        }
    }

    private function extendPayoutsAndRatings(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('payouts', 'booking_id')) {
                $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('payouts', 'gross_amount')) {
                $table->decimal('gross_amount', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('payouts', 'commission_amount')) {
                $table->decimal('commission_amount', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('payouts', 'processed_by')) {
                $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('payouts', 'processed_at')) {
                $table->timestamp('processed_at')->nullable();
            }
        });

        Schema::table('ratings', function (Blueprint $table) {
            if (! Schema::hasColumn('ratings', 'booking_id')) {
                $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
                $table->unique(['booking_id', 'student_id'], 'ratings_booking_student_unique');
            }
        });
    }

    private function seedWorkflowSettings(): void
    {
        $defaults = [
            'admin_fee' => '20',
            'default_private_online_rate' => '40000',
            'default_private_offline_rate' => '40000',
            'default_group_online_rate' => '40000',
            'default_group_offline_rate' => '40000',
            'teacher_response_hours' => '12',
            'maximum_search_hours' => '48',
            'payment_window_minutes' => '60',
            'student_objection_hours' => '48',
            'group_min_participants' => '2',
            'group_max_participants' => '5',
            'group_wait_hours' => '24',
        ];

        foreach ($defaults as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('booking_disputes');
        Schema::dropIfExists('session_reports');
        Schema::dropIfExists('booking_participants');
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('group_pools');
        Schema::dropIfExists('learning_topics');

        // Kolom tambahan dipertahankan saat rollback untuk mencegah hilangnya data operasional.
    }
};
