<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('matching_operation_logs')) {
            Schema::create('matching_operation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_request_id')
                    ->constrained('booking_requests')
                    ->cascadeOnDelete();
                $table->foreignId('actor_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->string('action', 60);
                $table->text('reason')->nullable();
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['booking_request_id', 'created_at'], 'matching_logs_request_created_idx');
                $table->index(['action', 'created_at'], 'matching_logs_action_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('matching_operation_logs');
    }
};
