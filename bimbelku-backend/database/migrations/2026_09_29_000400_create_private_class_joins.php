<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_packages', function (Blueprint $table) {
            $table->string('class_join_code', 16)->nullable()->unique();
        });

        Schema::create('private_class_joins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['learning_package_id', 'student_id']);
            $table->index(['learning_package_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_class_joins');
        Schema::table('learning_packages', fn (Blueprint $table) => $table->dropUnique(['class_join_code']));
        Schema::table('learning_packages', fn (Blueprint $table) => $table->dropColumn('class_join_code'));
    }
};
