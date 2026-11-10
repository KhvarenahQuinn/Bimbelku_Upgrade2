<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_packages', function (Blueprint $table) {
            $table->boolean('purchaser_participates')->default(true)->after('participant_count');
            $table->json('participant_details')->nullable()->after('participant_names');
        });
    }

    public function down(): void
    {
        Schema::table('learning_packages', function (Blueprint $table) {
            $table->dropColumn(['purchaser_participates', 'participant_details']);
        });
    }
};
