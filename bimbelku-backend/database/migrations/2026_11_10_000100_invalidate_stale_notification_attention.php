<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasColumn('notifications', 'invalidated_at')) {
            return;
        }

        // Badge berarti "perubahan yang belum dilihat". Notifikasi lama yang
        // tidak menunjuk halaman atau hanya bertipe targeted_notification tidak
        // boleh terus menyalakan indikator setelah deploy.
        DB::table('notifications')
            ->where('is_read', false)
            ->where(function ($query) {
                $query->whereNull('attention_key')
                    ->orWhere('attention_key', 'targeted_notification');
            })
            ->where('created_at', '<', now()->subDay())
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'invalidated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Status baca adalah data pengguna. Migrasi ini sengaja tidak
        // mengembalikannya agar notifikasi lama tidak muncul lagi sebagai badge.
    }
};
