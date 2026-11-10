<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'has_password')) {
                $table->boolean('has_password')
                    ->default(true)
                    ->after('password');
            }
        });

        // Akun yang dibuat lewat Google memakai password acak agar kolom
        // password tetap terisi. Mereka belum pernah menetapkan password
        // sendiri, sehingga ditandai belum punya password lokal.
        DB::table('users')
            ->whereNotNull('google_id')
            ->whereNull('password_updated_at')
            ->update(['has_password' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'has_password')) {
                $table->dropColumn('has_password');
            }
        });
    }
};
