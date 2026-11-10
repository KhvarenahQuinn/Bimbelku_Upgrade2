<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('website_sections')
            ->where('section_key', 'hero')
            ->where('title', 'Belajar lebih pas, mulai dari tutor yang tepat.')
            ->update([
                'eyebrow' => 'Bimbel privat SD–SMA di Yogyakarta',
                'title' => 'Bimbel privat yang dimulai dari kebutuhan belajar anak.',
                'description' => 'Pilih mata pelajaran, Bab, jadwal, serta mode online atau tatap muka. Harga terlihat sejak awal, lalu pencarian tutor dimulai setelah pembayaran terverifikasi.',
                'updated_at' => now(),
            ]);

        DB::table('website_settings')
            ->where('brand_description', 'Bimbingan belajar SD–SMA di Yogyakarta dengan pilihan belajar online dan offline.')
            ->update([
                'brand_description' => 'Bimbel privat SD, SMP, dan SMA di Yogyakarta dengan pilihan belajar online atau tatap muka, materi terarah, serta proses pemesanan yang transparan.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Konten dapat diedit admin; rollback tidak menimpa perubahan yang sudah dipublikasikan.
    }
};
