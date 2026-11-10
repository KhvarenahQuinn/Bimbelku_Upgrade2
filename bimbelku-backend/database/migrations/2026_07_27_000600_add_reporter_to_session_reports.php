<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('session_reports') && ! Schema::hasColumn('session_reports', 'reported_by')) {
            Schema::table('session_reports', function (Blueprint $table) {
                $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Kolom dipertahankan agar jejak pelapor tidak hilang saat rollback.
    }
};
