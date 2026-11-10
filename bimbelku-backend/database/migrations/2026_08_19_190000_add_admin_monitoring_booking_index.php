<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            // `status + start_at` already exists from the Stage Five query-optimization
            // migration. This companion index keeps type/status/date monitoring filters
            // efficient without changing any booking lifecycle semantics.
            $table->index(
                ['class_type', 'status', 'start_at'],
                'bookings_class_type_status_start_idx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_class_type_status_start_idx');
        });
    }
};
