<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (! Schema::hasColumn('orders', 'order_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('order_id', 40)->nullable()->index();
            });
        }

        DB::table('orders')
            ->whereNull('order_id')
            ->orderBy('id')
            ->chunkById(100, function ($orders) {
                foreach ($orders as $order) {
                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['order_id' => 'ORD-'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT)]);
                }
            });
    }

    public function down(): void
    {
        // Referensi transaksi dipertahankan agar riwayat pembayaran tidak kehilangan nomor.
    }
};
