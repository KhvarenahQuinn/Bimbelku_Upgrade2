<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_programs', function (Blueprint $table) {
            $table->json('education_levels')->nullable()->after('education_level');
        });

        DB::table('learning_programs')->select(['id', 'education_level'])->orderBy('id')->chunkById(100, function ($programs) {
            foreach ($programs as $program) {
                DB::table('learning_programs')->where('id', $program->id)
                    ->update(['education_levels' => json_encode([$program->education_level])]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('learning_programs', fn (Blueprint $table) => $table->dropColumn('education_levels'));
    }
};
