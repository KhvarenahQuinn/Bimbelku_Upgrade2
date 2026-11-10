<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_offers') && ! Schema::hasColumn('teacher_offers', 'reminder_sent_at')) {
            Schema::table('teacher_offers', function (Blueprint $table) {
                $table->timestamp('reminder_sent_at')->nullable()->after('offered_at')->index();
            });
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->insertOrIgnore([
                'key' => 'teacher_offer_reminder_minutes',
                'value' => '30',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('teacher_offers') && Schema::hasColumn('teacher_offers', 'reminder_sent_at')) {
            Schema::table('teacher_offers', function (Blueprint $table) {
                $table->dropIndex(['reminder_sent_at']);
                $table->dropColumn('reminder_sent_at');
            });
        }
    }
};
