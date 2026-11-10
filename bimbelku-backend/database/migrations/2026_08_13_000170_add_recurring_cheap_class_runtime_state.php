<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cheap_class_templates')) {
            return;
        }

        Schema::table('cheap_class_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('cheap_class_templates', 'last_skipped_at')) {
                $table->dateTime('last_skipped_at')->nullable()->after('last_published_at');
            }
            if (! Schema::hasColumn('cheap_class_templates', 'last_generation_failed_at')) {
                $table->dateTime('last_generation_failed_at')->nullable()->after('last_skipped_at');
            }
            if (! Schema::hasColumn('cheap_class_templates', 'last_generation_error')) {
                $table->text('last_generation_error')->nullable()->after('last_generation_failed_at');
            }
        });
    }

    public function down(): void
    {
        // Riwayat kegagalan dan periode yang dilewati dipertahankan untuk audit.
    }
};
