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
        Schema::table('teacher_profiles', function (Blueprint $table) {

            // Tambahkan data bank jika belum tersedia.
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('teacher_profiles', 'bank_name')) {
                $columnsToDrop[] = 'bank_name';
            }
            if (Schema::hasColumn('teacher_profiles', 'account_number')) {
                $columnsToDrop[] = 'account_number';
            }
            if (Schema::hasColumn('teacher_profiles', 'account_name')) {
                $columnsToDrop[] = 'account_name';
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
