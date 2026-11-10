<?php

use Illuminate\Database\Migrations\Migration; // Pastikan ada ini di paling atas
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update tabel teacher_profiles (Pakai Pengecekan)
        Schema::table('teacher_profiles', function (Blueprint $table) {

            // Cek satu per satu: Kalau kolom belum ada, baru tambahkan
            if (! Schema::hasColumn('teacher_profiles', 'title')) {
                $table->string('title')->nullable()->after('linkedin');
            }
            if (! Schema::hasColumn('teacher_profiles', 'bio')) {
                $table->text('bio')->nullable()->after('linkedin');
            }
            if (! Schema::hasColumn('teacher_profiles', 'location')) {
                $table->string('location')->nullable()->after('linkedin');
            }
            if (! Schema::hasColumn('teacher_profiles', 'photo')) {
                $table->string('photo')->nullable()->after('linkedin');
            }
            // Data Bank
            if (! Schema::hasColumn('teacher_profiles', 'bank_name')) {
                $table->string('bank_name')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'account_number')) {
                $table->string('account_number')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'account_name')) {
                $table->string('account_name')->nullable();
            }
        });

        // 2. Buat tabel teacher_subjects (Cek kalau tabel belum ada)
        if (! Schema::hasTable('teacher_subjects')) {
            Schema::create('teacher_subjects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('teacher_profile_id')->constrained()->onDelete('cascade');
                $table->string('name');

                // Kemampuan layanan. Harga ditentukan terpusat oleh admin.
                $table->boolean('is_private_active')->default(true);
                $table->boolean('is_group_active')->default(true);

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Biarkan default atau sesuaikan jika perlu
        Schema::dropIfExists('teacher_subjects');
        Schema::table('teacher_profiles', function (Blueprint $table) {
            // Drop column manual kalau perlu rollback
        });
    }
};
