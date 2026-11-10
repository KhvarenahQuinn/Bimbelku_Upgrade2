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
            // Cek dulu biar tidak error "Column already exists"
            if (! Schema::hasColumn('classrooms', 'method')) {
                $table->string('method')->default('online')->after('status');
            }
            if (! Schema::hasColumn('classrooms', 'day')) {
                $table->string('day')->nullable()->after('method');
            }
            if (! Schema::hasColumn('classrooms', 'time')) {
                $table->string('time')->nullable()->after('day');
            }
            if (! Schema::hasColumn('classrooms', 'location_id')) {
                $table->foreignId('location_id')->nullable()->after('time');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            //
        });
    }
};
