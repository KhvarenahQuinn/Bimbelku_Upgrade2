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
        // 1. Tabel KELAS UTAMA
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // ID Guru
            $table->string('title');        // Nama Kelas (ex: Matematika Batch 1)
            $table->string('subject');      // Mapel (ex: Matematika)
            $table->string('type');         // Privat / Grup
            $table->string('theme')->default('from-blue-600 to-indigo-700'); // Warna background card
            $table->string('status')->default('Open'); // Open / Closed
            $table->timestamps();
        });

        // 2. Tabel SESI PERTEMUAN (Materi & Jadwal)
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->onDelete('cascade');
            $table->string('title')->nullable();     // Judul Sesi (ex: Aljabar Dasar)
            $table->text('content')->nullable();     // Deskripsi materi
            $table->time('time')->nullable();        // Jam mulai
            $table->string('meet_link')->nullable(); // Link Zoom/Gmeet
            $table->string('pdf_url')->nullable();   // File materi upload
            $table->boolean('is_completed')->default(false);
            $table->timestamps();
        });

        // 3. Tabel PENGHUBUNG MURID & KELAS
        Schema::create('class_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade'); // ID Murid
            $table->timestamp('joined_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_students');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('classrooms');
    }
};
