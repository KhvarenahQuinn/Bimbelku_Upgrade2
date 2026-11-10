<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('grade');
            }
            if (! Schema::hasColumn('users', 'guardian_name')) {
                $table->string('guardian_name')->nullable()->after('date_of_birth');
            }
            if (! Schema::hasColumn('users', 'guardian_phone')) {
                $table->string('guardian_phone', 30)->nullable()->after('guardian_name');
            }
            if (! Schema::hasColumn('users', 'guardian_relationship')) {
                $table->string('guardian_relationship', 40)->nullable()->after('guardian_phone');
            }
            if (! Schema::hasColumn('users', 'guardian_consent_at')) {
                $table->timestamp('guardian_consent_at')->nullable()->after('privacy_accepted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter(
                [
                    'date_of_birth',
                    'guardian_name',
                    'guardian_phone',
                    'guardian_relationship',
                    'guardian_consent_at',
                ],
                fn (string $column) => Schema::hasColumn('users', $column)
            );

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
