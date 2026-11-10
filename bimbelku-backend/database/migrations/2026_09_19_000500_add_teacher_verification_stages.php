<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->string('verification_stage', 40)->default('submitted')->index();
            $table->timestamp('documents_checked_at')->nullable();
            $table->timestamp('whatsapp_test_scheduled_at')->nullable();
            $table->timestamp('whatsapp_test_passed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropIndex(['verification_stage']);
            $table->dropColumn(['verification_stage', 'documents_checked_at', 'whatsapp_test_scheduled_at', 'whatsapp_test_passed_at']);
        });
    }
};
