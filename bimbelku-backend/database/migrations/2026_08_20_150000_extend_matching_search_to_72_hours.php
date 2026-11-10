<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $existing = DB::table('settings')->where('key', 'maximum_search_hours')->first();
        if (! $existing) {
            DB::table('settings')->insert([
                'key' => 'maximum_search_hours',
                'value' => '72',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif ((int) $existing->value <= 48) {
            DB::table('settings')
                ->where('key', 'maximum_search_hours')
                ->update(['value' => '72', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->where('key', 'maximum_search_hours')
                ->where('value', '72')
                ->update(['value' => '48', 'updated_at' => now()]);
        }
    }
};
