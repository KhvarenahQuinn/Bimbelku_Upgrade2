<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('teacher_profiles')) {
            return;
        }

        Schema::table('teacher_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_profiles', 'whatsapp_number')) {
                $table->string('whatsapp_number')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('teacher_profiles')) {
            return;
        }

        Schema::table('teacher_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('teacher_profiles', 'whatsapp_number')) {
                $table->dropColumn('whatsapp_number');
            }
        });
    }
};
