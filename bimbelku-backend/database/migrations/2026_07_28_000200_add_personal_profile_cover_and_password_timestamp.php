<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'profile_cover')) {
                $table->string('profile_cover')->nullable();
            }
            if (! Schema::hasColumn('users', 'password_updated_at')) {
                $table->timestamp('password_updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Data sampul dan riwayat keamanan dipertahankan saat rollback.
    }
};
