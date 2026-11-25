<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('module', 60)->index();
            $table->string('action', 100)->index();
            $table->text('summary');
            $table->string('target_type', 120)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('metadata')->nullable();
            $table->text('ip_address')->nullable();
            $table->longText('user_agent')->nullable();
            $table->string('outcome', 20)->default('success')->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['actor_id', 'created_at']);
            $table->index(['module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
    }
};
