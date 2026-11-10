<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_requests') && Schema::hasColumn('booking_requests', 'search_radius_km')) {
            Schema::table('booking_requests', function (Blueprint $table) {
                $table->unsignedSmallInteger('search_radius_km')->nullable()->default(null)->change();
            });
            DB::table('booking_requests')->where('learning_mode', 'online')->update([
                'search_radius_km' => null,
                'address' => null,
                'maps_link' => null,
                'latitude' => null,
                'longitude' => null,
            ]);
        }

        if (Schema::hasTable('learning_packages')) {
            DB::table('learning_packages')->where('learning_mode', 'online')->update([
                'address' => null,
                'maps_link' => null,
            ]);
        }

        if (Schema::hasTable('bookings')) {
            DB::table('bookings')->where('learning_mode', 'online')->update([
                'address' => null,
                'maps_link' => null,
                'tutor_ready_latitude' => null,
                'tutor_ready_longitude' => null,
                'tutor_ready_accuracy_meters' => null,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_requests') || ! Schema::hasColumn('booking_requests', 'search_radius_km')) {
            return;
        }

        DB::table('booking_requests')->whereNull('search_radius_km')->update(['search_radius_km' => 3]);
        Schema::table('booking_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('search_radius_km')->nullable(false)->default(3)->change();
        });
    }
};
