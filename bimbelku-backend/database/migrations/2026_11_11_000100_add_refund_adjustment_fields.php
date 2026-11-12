<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            if (! Schema::hasColumn('refunds', 'cost_bearer')) {
                $table->string('cost_bearer', 20)->default('tutor_share')->after('reason');
            }
            if (! Schema::hasColumn('refunds', 'decided_by')) {
                $table->string('decided_by', 20)->nullable()->after('cost_bearer');
            }
            if (! Schema::hasColumn('refunds', 'decision_actor_id')) {
                $table->unsignedBigInteger('decision_actor_id')->nullable()->after('decided_by');
            }
            if (! Schema::hasColumn('refunds', 'decision_notes')) {
                $table->text('decision_notes')->nullable()->after('decision_actor_id');
            }
        });

        Schema::table('booking_disputes', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_disputes', 'kind')) {
                $table->string('kind', 30)->default('completion_objection')->after('booking_id');
            }
        });

        DB::table('refunds')->whereNull('cost_bearer')->update(['cost_bearer' => 'tutor_share']);

        Schema::table('teacher_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_profiles', 'payout_verification_method')) {
                $table->string('payout_verification_method', 20)->default('otp');
            }
            if (! Schema::hasColumn('teacher_profiles', 'payout_verification_method_updated_at')) {
                $table->timestamp('payout_verification_method_updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn(['cost_bearer', 'decided_by', 'decision_actor_id', 'decision_notes']);
        });
        Schema::table('booking_disputes', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn(['payout_verification_method', 'payout_verification_method_updated_at']);
        });
    }
};
