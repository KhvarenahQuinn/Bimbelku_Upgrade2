<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'privacy_accepted_at')) {
                $table->timestamp('privacy_accepted_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'policy_version')) {
                $table->string('policy_version', 40)->nullable();
            }
            if (! Schema::hasColumn('users', 'consent_ip')) {
                $table->string('consent_ip', 45)->nullable();
            }
            if (! Schema::hasColumn('users', 'consent_user_agent')) {
                $table->string('consent_user_agent', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter(
                ['terms_accepted_at', 'privacy_accepted_at', 'policy_version', 'consent_ip', 'consent_user_agent'],
                fn (string $column) => Schema::hasColumn('users', $column)
            );

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
