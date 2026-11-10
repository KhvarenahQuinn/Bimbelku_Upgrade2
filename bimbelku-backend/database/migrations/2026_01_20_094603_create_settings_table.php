<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();   // misal: 'footer_address'
            $table->text('value')->nullable(); // misal: 'Jl. Sudirman No 1...'
            $table->timestamps();
        });

        // Insert data default (Optional)
        DB::table('settings')->insert([
            ['key' => 'footer_address', 'value' => 'Jakarta Selatan, Indonesia'],
            ['key' => 'footer_phone', 'value' => '+62 812 3456 7890'],
            ['key' => 'footer_email', 'value' => 'info@bimbelku.com'],
            ['key' => 'footer_ig', 'value' => 'https://instagram.com'],
            ['key' => 'footer_tiktok', 'value' => 'https://tiktok.com'],
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('settings');
    }
};
