<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->text('gateway_failure_message')->nullable()->after('gateway_failure_code');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', fn (Blueprint $table) => $table->dropColumn('gateway_failure_message'));
    }
};
