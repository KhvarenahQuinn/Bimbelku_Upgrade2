<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->timestamp('gateway_last_checked_at')->nullable()->after('gateway_processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', fn (Blueprint $table) => $table->dropColumn('gateway_last_checked_at'));
    }
};
