<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Pemilik catatan
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->string('color')->default('white'); // white, red, orange, yellow, green, teal, blue, indigo, purple, pink
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('notes');
    }
};
