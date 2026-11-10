<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Pengirim
            $table->string('subject'); // Judul Masalah
            $table->text('message'); // Isi Pesan
            $table->text('reply')->nullable(); // Balasan Admin (Awalnya kosong)
            $table->enum('status', ['open', 'replied'])->default('open'); // Status
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('tickets');
    }
};
