<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_page_contents', function (Blueprint $table) {
            $table->json('learning_journey')->nullable()->after('learning_map');
            $table->json('reasons')->nullable()->after('suitable_for');
            $table->json('articles')->nullable()->after('reasons');
        });
    }

    public function down(): void
    {
        Schema::table('subject_page_contents', function (Blueprint $table) {
            $table->dropColumn(['learning_journey', 'reasons', 'articles']);
        });
    }
};
