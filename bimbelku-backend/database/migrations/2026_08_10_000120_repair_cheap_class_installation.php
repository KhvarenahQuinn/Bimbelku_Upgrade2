<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('orders')
            && Schema::hasTable('cheap_class_enrollments')
            && ! Schema::hasColumn('orders', 'cheap_class_enrollment_id')
        ) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('cheap_class_enrollment_id')
                    ->nullable()
                    ->unique()
                    ->constrained('cheap_class_enrollments')
                    ->nullOnDelete();
            });
        }

        if (
            Schema::hasTable('cheap_class_enrollments')
            && ! Schema::hasIndex('cheap_class_enrollments', 'cheap_class_enrollments_status_expiry_index')
        ) {
            Schema::table('cheap_class_enrollments', function (Blueprint $table) {
                $table->index(
                    ['status', 'seat_expires_at'],
                    'cheap_class_enrollments_status_expiry_index'
                );
            });
        }
    }

    public function down(): void
    {
        // Migration ini hanya memperbaiki pemasangan parsial. Skema utama
        // tetap dimiliki oleh migration create_cheap_class_workflow.
    }
};
