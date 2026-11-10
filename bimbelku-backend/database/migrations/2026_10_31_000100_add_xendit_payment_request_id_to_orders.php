<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ID Payment Request dari Xendit respons (pr-...) disimpan terpisah
        // agar admin dapat langsung mencocokkan tagihan dengan dashboard
        // Xendit tanpa membongkar metadata webhook.
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'xendit_payment_request_id')) {
                $table->string('xendit_payment_request_id')
                    ->nullable()
                    ->after('gateway_payment_request_id')
                    ->index();
            }
        });

        // Data lama: isi dari kolom gateway agar nilai pr-... tidak hilang
        // pada tagihan yang sudah dibayar sebelum migrasi ini.
        DB::table('orders')
            ->whereNull('xendit_payment_request_id')
            ->whereNotNull('gateway_payment_request_id')
            ->update(['xendit_payment_request_id' => DB::raw('gateway_payment_request_id')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'xendit_payment_request_id')) {
                $table->dropIndex(['xendit_payment_request_id']);
                $table->dropColumn('xendit_payment_request_id');
            }
        });
    }
};
