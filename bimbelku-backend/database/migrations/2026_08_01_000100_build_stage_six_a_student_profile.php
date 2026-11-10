<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'student_education_level')) {
                $table->string('student_education_level', 40)->nullable()->after('school_name');
            }
            if (! Schema::hasColumn('users', 'learning_needs')) {
                $table->text('learning_needs')->nullable()->after('grade');
            }
            if (! Schema::hasColumn('users', 'location_consent_at')) {
                $table->timestamp('location_consent_at')->nullable()->after('longitude');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = collect(['student_education_level', 'learning_needs', 'location_consent_at'])
                ->filter(fn (string $column) => Schema::hasColumn('users', $column))
                ->all();
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
