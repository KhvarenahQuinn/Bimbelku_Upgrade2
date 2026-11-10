<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('teacher_subjects', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_subjects', 'is_online')) {
                $table->boolean('is_online')->default(true);
            }
            if (! Schema::hasColumn('teacher_subjects', 'is_offline')) {
                $table->boolean('is_offline')->default(true);
            }
        });
    }

    public function down()
    {
        Schema::table('teacher_subjects', function (Blueprint $table) {
            foreach (['is_online', 'is_offline'] as $col) {
                if (Schema::hasColumn('teacher_subjects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
