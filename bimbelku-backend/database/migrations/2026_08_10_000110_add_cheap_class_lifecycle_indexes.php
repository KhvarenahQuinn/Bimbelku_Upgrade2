<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('cheap_class_enrollments')
            && ! Schema::hasIndex('cheap_class_enrollments', 'cheap_class_enrollments_status_expiry_index')
        ) {
            Schema::table('cheap_class_enrollments', function (Blueprint $table) {
                // Digunakan oleh pekerjaan per menit untuk melepas kursi yang
                // tidak dibayar tanpa memindai seluruh riwayat enrollment.
                $table->index(['status', 'seat_expires_at'], 'cheap_class_enrollments_status_expiry_index');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('cheap_class_enrollments')
            && Schema::hasIndex('cheap_class_enrollments', 'cheap_class_enrollments_status_expiry_index')
        ) {
            Schema::table('cheap_class_enrollments', function (Blueprint $table) {
                $table->dropIndex('cheap_class_enrollments_status_expiry_index');
            });
        }
    }
};
