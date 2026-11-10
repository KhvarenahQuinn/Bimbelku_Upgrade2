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
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // ID Guru
            $table->string('period'); // Contoh: "01 Jan - 07 Jan 2026"
            $table->decimal('amount', 15, 2); // Nominal Gaji Bersih
            $table->integer('total_classes'); // Jumlah kelas/sesi
            $table->string('proof_url'); // <--- PENTING: Foto Bukti Transfer
            $table->string('status')->default('completed');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
