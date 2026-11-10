<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('locations', function (Blueprint $table) {
            // Cek dulu, kalau kolom 'maps_link' BELUM ada, baru kita buat
            if (! Schema::hasColumn('locations', 'maps_link')) {
                $table->string('maps_link')->nullable()->after('address');
            }
        });
    }

    public function down()
    {
        Schema::table('locations', function (Blueprint $table) {
            if (Schema::hasColumn('locations', 'maps_link')) {
                $table->dropColumn('maps_link');
            }
        });
    }
};
