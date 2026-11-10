<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_page_contents', function (Blueprint $table) {
            $table->string('hero_image_path')->nullable()->after('hero_intro');
        });
    }

    public function down(): void
    {
        Schema::table('subject_page_contents', function (Blueprint $table) {
            $table->dropColumn('hero_image_path');
        });
    }
};
