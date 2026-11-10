<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Murid
            $table->foreignId('classroom_id')->constrained()->onDelete('cascade'); // Kelas
            $table->decimal('amount', 10, 2); // Harga
            $table->string('status')->default('pending'); // pending, paid, rejected
            $table->string('payment_proof')->nullable(); // Foto bukti transfer
            $table->string('bank_name')->nullable(); // Bank pengirim
            $table->string('sender_name')->nullable(); // Nama pengirim
            $table->string('sender_account_number', 80)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
