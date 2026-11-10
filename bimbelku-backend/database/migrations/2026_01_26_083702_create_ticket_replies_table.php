<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. Buat tabel percakapan (replies)
        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // Pengirim pesan (bisa admin/user)
            $table->text('message');
            $table->string('attachment')->nullable(); // Foto bukti
            $table->timestamps();
        });

        // 2. Update tabel tickets (Hapus kolom lama yang tidak dipakai, tambah status closed)
        Schema::table('tickets', function (Blueprint $table) {
            // Kita drop kolom lama karena isinya pindah ke ticket_replies
            // (Note: Data lama akan hilang, ini untuk fresh start fitur chat)
            $table->dropColumn(['message', 'reply', 'attachment']);

            // Update enum status biar ada 'closed'
            // Di MySQL murni harus alter native, di Laravel helper kita pakai string dulu biar aman atau modify enum
            // Cara aman & cepat: Kita ubah kolom status jadi string saja biar fleksibel
            $table->string('status')->default('open')->change();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ticket_replies');
    }
};
