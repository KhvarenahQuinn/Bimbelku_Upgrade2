<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_programs', function (Blueprint $table) {
            $table->foreignId('package_plan_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Program baru dapat tidak memiliki paket sesi; jangan paksa nilai yang dibuat-buat saat rollback.
    }
};
