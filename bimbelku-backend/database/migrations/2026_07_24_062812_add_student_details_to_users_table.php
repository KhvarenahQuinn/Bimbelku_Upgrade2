<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'address')) {
                $table->text('address')->nullable()->after('grade');
            }
            if (! Schema::hasColumn('users', 'maps_link')) {
                $table->string('maps_link')->nullable()->after('address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter(
                ['address', 'maps_link'],
                fn (string $column) => Schema::hasColumn('users', $column)
            );

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
