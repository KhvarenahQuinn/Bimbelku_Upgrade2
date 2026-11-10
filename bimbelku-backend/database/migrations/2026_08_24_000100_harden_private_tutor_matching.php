<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_requests') && ! Schema::hasColumn('booking_requests', 'next_matching_at')) {
            Schema::table('booking_requests', function (Blueprint $table) {
                $table->timestamp('next_matching_at')->nullable()->after('teacher_response_deadline')->index();
            });
        }

        if (Schema::hasTable('settings')) {
            $now = now();
            foreach ([
                'booking_lead_hours' => '24',
                'renewal_booking_lead_hours' => '12',
                'maximum_search_hours' => '12',
                'matching_cutoff_hours' => '2',
                'teacher_response_online_minutes' => '60',
                'teacher_response_offline_minutes' => '60',
                'teacher_offer_retry_cooldown_minutes' => '120',
                'matching_wait_retry_minutes' => '30',
            ] as $key => $value) {
                DB::table('settings')->insertOrIgnore([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Hanya pindahkan nilai bawaan versi lama. Nilai lain dianggap
            // konfigurasi khusus instalasi dan tidak boleh ditimpa migrasi.
            foreach ([
                'booking_lead_hours' => ['72', '24'],
                'renewal_booking_lead_hours' => ['24', '12'],
                'maximum_search_hours' => ['72', '12'],
                'teacher_response_online_minutes' => ['30', '60'],
                'teacher_response_offline_minutes' => ['120', '60'],
            ] as $key => [$legacyValue, $newValue]) {
                DB::table('settings')
                    ->where('key', $key)
                    ->where('value', $legacyValue)
                    ->update(['value' => $newValue, 'updated_at' => $now]);
            }
        }

        if (Schema::hasTable('learning_time_slots')) {
            foreach ([21, 22] as $hour) {
                DB::table('learning_time_slots')->insertOrIgnore([
                    'start_time' => sprintf('%02d:00:00', $hour),
                    'label' => sprintf('%02d.00 WIB', $hour),
                    'sort_order' => $hour,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('booking_requests') && Schema::hasColumn('booking_requests', 'next_matching_at')) {
            Schema::table('booking_requests', function (Blueprint $table) {
                $table->dropIndex(['next_matching_at']);
                $table->dropColumn('next_matching_at');
            });
        }

        // Konfigurasi dan slot waktu dipertahankan saat rollback karena migrasi
        // tidak dapat membedakan data instalasi lama dari data yang disisipkan.
    }
};
