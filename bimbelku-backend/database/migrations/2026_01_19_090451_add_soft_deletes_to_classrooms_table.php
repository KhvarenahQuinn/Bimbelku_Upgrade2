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
        Schema::table('classrooms', function (Blueprint $table) {
            $table->softDeletes(); // Menambah kolom deleted_at
        });
    }

    public function down()
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
