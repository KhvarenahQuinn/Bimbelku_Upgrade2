<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'payment_pin_hash')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('payment_pin_hash')->nullable()->after('password');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'payment_pin_hash')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('payment_pin_hash');
            });
        }
    }
};
