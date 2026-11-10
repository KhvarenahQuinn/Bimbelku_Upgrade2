<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {

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
    }

    public function down(): void
    {
        // Kosongkan saja agar aman
    }
};
