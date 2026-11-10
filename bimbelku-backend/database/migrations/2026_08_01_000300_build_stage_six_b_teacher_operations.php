<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'target_url')) {
                $table->string('target_url', 500)->nullable()->after('type');
            }
            if (! Schema::hasColumn('notifications', 'unique_key')) {
                $table->string('unique_key', 190)->nullable()->unique()->after('target_url');
            }
        });

        Schema::table('classroom_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('classroom_messages', 'attachment_path')) {
                $table->string('attachment_path')->nullable()->after('body');
            }
            if (! Schema::hasColumn('classroom_messages', 'attachment_name')) {
                $table->string('attachment_name', 255)->nullable()->after('attachment_path');
            }
            if (! Schema::hasColumn('classroom_messages', 'attachment_mime')) {
                $table->string('attachment_mime', 120)->nullable()->after('attachment_name');
            }
            if (! Schema::hasColumn('classroom_messages', 'attachment_size')) {
                $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime');
            }
            if (! Schema::hasColumn('classroom_messages', 'client_token')) {
                $table->uuid('client_token')->nullable()->after('attachment_size');
                $table->unique(
                    ['booking_id', 'sender_id', 'client_token'],
                    'classroom_messages_sender_token_unique'
                );
            }
        });

        if (! Schema::hasTable('classroom_message_reads')) {
            Schema::create('classroom_message_reads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('classroom_message_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamp('read_at');
                $table->timestamps();
                $table->unique(
                    ['classroom_message_id', 'user_id'],
                    'classroom_message_reads_message_user_unique'
                );
                $table->index(['user_id', 'read_at']);
            });
        }

        if (! Schema::hasTable('participant_attendances')) {
            Schema::create('participant_attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->foreignId('booking_participant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('marked_by')->constrained('users')->restrictOnDelete();
                $table->string('status', 30);
                $table->text('notes')->nullable();
                $table->timestamp('marked_at');
                $table->timestamps();
                $table->unique('booking_participant_id');
                $table->index(['booking_id', 'status']);
                $table->index(['student_id', 'marked_at']);
            });
        }

        if (! Schema::hasTable('schedule_change_requests')) {
            Schema::create('schedule_change_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
                $table->string('requester_role', 20);
                $table->dateTime('original_start_at');
                $table->dateTime('original_end_at');
                $table->dateTime('proposed_start_at');
                $table->dateTime('proposed_end_at');
                $table->text('reason');
                $table->string('status', 30)->default('pending');
                $table->timestamp('decided_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->index(['booking_id', 'status']);
                $table->index(['status', 'expires_at']);
            });
        }

        if (! Schema::hasTable('schedule_change_responses')) {
            Schema::create('schedule_change_responses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_change_request_id')
                    ->constrained('schedule_change_requests')
                    ->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('decision', 20)->default('pending');
                $table->text('notes')->nullable();
                $table->timestamp('responded_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['schedule_change_request_id', 'user_id'],
                    'schedule_change_responses_request_user_unique'
                );
            });
        }

        if (! Schema::hasTable('teacher_payout_requests')) {
            Schema::create('teacher_payout_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
                $table->json('booking_ids');
                $table->decimal('gross_amount', 15, 2);
                $table->decimal('commission_amount', 15, 2);
                $table->decimal('net_amount', 15, 2);
                $table->string('bank_name', 100);
                $table->string('account_number', 80);
                $table->string('account_name', 150);
                $table->unsignedInteger('bank_details_version')->default(0);
                $table->string('status', 30)->default('pending');
                $table->foreignId('payout_id')->nullable()->constrained()->nullOnDelete();
                $table->text('review_notes')->nullable();
                $table->timestamp('requested_at');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->index(['teacher_id', 'status']);
                $table->index(['status', 'requested_at']);
            });
        }

        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'completion_capture_source')) {
                $table->string('completion_capture_source', 30)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'completion_captured_at')) {
                $table->timestamp('completion_captured_at')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'payout_request_id')) {
                $table->foreignId('payout_request_id')
                    ->nullable()
                    ->constrained('teacher_payout_requests')
                    ->nullOnDelete();
            }
        });

        foreach ([
            'schedule_change_min_notice_hours' => '6',
            'schedule_change_response_hours' => '24',
            'chat_attachment_max_mb' => '5',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'payout_request_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropConstrainedForeignId('payout_request_id');
            });
        }
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['completion_capture_source', 'completion_captured_at'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('teacher_payout_requests');
        Schema::dropIfExists('schedule_change_responses');
        Schema::dropIfExists('schedule_change_requests');
        Schema::dropIfExists('participant_attendances');
        Schema::dropIfExists('classroom_message_reads');

        Schema::table('classroom_messages', function (Blueprint $table) {
            if (Schema::hasColumn('classroom_messages', 'client_token')) {
                $table->dropUnique('classroom_messages_sender_token_unique');
            }
            foreach ([
                'attachment_path',
                'attachment_name',
                'attachment_mime',
                'attachment_size',
                'client_token',
            ] as $column) {
                if (Schema::hasColumn('classroom_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'unique_key')) {
                $table->dropUnique(['unique_key']);
                $table->dropColumn('unique_key');
            }
            if (Schema::hasColumn('notifications', 'target_url')) {
                $table->dropColumn('target_url');
            }
        });
    }
};
