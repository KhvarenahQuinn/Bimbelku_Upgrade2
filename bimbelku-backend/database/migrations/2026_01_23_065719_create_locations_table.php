<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');         // Nama Tempat (Misal: McD Basuki Rahmat)
            $table->string('city');         // Kota (Surabaya)
            $table->text('address');        // Alamat Lengkap
            $table->string('maps_link')->nullable(); // Link Google Maps
            $table->string('photo')->nullable();     // Foto tempat (Opsional)
            $table->boolean('is_active')->default(true); // Biar Admin bisa tutup sementara
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
