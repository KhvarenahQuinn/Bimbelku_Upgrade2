<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'payment_pin_failed_attempts')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedTinyInteger('payment_pin_failed_attempts')->default(0)->after('payment_pin_hash');
            });
        }

        if (! Schema::hasColumn('users', 'payment_pin_locked_until')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('payment_pin_locked_until')->nullable()->after('payment_pin_failed_attempts');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'payment_pin_locked_until')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('payment_pin_locked_until');
            });
        }

        if (Schema::hasColumn('users', 'payment_pin_failed_attempts')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('payment_pin_failed_attempts');
            });
        }
    }
};
