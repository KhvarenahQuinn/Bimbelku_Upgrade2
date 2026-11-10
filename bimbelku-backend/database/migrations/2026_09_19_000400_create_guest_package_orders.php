<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_package_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('email')->index();
            $table->string('name', 120);
            $table->string('phone', 30);
            $table->json('payload');
            $table->decimal('quoted_total_amount', 12, 2);
            $table->string('status', 24)->default('pending');
            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('learning_package_id')->nullable()->constrained('learning_packages')->nullOnDelete();
            $table->json('result_snapshot')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_package_orders');
    }
};
