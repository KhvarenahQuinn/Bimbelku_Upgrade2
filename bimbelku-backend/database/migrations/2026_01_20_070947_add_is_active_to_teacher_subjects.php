<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('teacher_subjects', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('name');
            // Default true artinya kelas BUKA saat pertama dibuat
        });
    }

    public function down()
    {
        Schema::table('teacher_subjects', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
