<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->boolean('public_profile_enabled')->default(false)->after('is_accepting_requests');
            $table->timestamp('public_profile_consent_at')->nullable()->after('public_profile_enabled');
            $table->string('public_credentials', 300)->nullable()->after('public_profile_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn(['public_profile_enabled', 'public_profile_consent_at', 'public_credentials']);
        });
    }
};
