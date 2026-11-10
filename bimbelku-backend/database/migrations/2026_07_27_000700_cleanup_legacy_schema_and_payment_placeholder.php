<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_settings')) {
            DB::table('payment_settings')
                ->where('bank_name', 'BCA')
                ->where('account_number', '1234567890')
                ->whereIn('account_name', ['PT BimbelKu', 'PT BimbelKu Edukasi'])
                ->update([
                    'bank_name' => '',
                    'account_number' => '',
                    'account_name' => '',
                    'updated_at' => now(),
                ]);
        }

        Schema::dropIfExists('subjects');

        $this->dropColumnsIfPresent('teacher_profiles', [
            'price_online',
            'price_offline',
            'cover',
            'cover_image',
            'bank_account_number',
            'bank_account_holder',
        ]);

        $this->dropColumnsIfPresent('teacher_subjects', [
            'price_private',
            'price_group',
            'start_date',
            'close_date',
            'price_online',
            'price_offline',
            'price_online_private',
            'price_online_group',
            'price_offline_private',
            'price_offline_group',
            'price_online_4',
            'price_online_8',
            'price_online_12',
            'price_offline_4',
            'price_offline_8',
            'price_offline_12',
            'price_online_group_4',
            'price_online_group_8',
            'price_online_group_12',
            'price_offline_group_4',
            'price_offline_group_8',
            'price_offline_group_12',
        ]);
    }

    public function down(): void
    {
        // Data dan skema lama sengaja tidak dipulihkan.
    }

    private function dropColumnsIfPresent(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $existing = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        if ($existing === []) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($existing) {
            $blueprint->dropColumn($existing);
        });
    }
};
