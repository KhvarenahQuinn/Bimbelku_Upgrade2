<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_STATUSES = ['processing', 'on_hold'];

    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->unsignedBigInteger('active_guard')->nullable()->after('status');
        });

        // Backfill before the unique index: rows that are still active must
        // carry their user id, otherwise the index lets them through as NULL.
        DB::table('payouts')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->update(['active_guard' => DB::raw('user_id')]);

        Schema::table('payouts', function (Blueprint $table) {
            $table->unique('active_guard', 'payouts_active_guard_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropUnique('payouts_active_guard_unique');
            $table->dropColumn('active_guard');
        });
    }
};
