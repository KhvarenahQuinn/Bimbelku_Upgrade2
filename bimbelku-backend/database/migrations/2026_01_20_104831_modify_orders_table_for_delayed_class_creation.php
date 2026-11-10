<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'classroom_id')) {
                $table->unsignedBigInteger('classroom_id')->nullable()->change();
            }

            if (! Schema::hasColumn('orders', 'class_details_snapshot')) {
                $table->json('class_details_snapshot')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'class_details_snapshot')) {
                $table->dropColumn('class_details_snapshot');
            }

            // classroom_id tidak dipaksa kembali non-null agar rollback tidak gagal
            // ketika pesanan baru belum memiliki kelas.
        });
    }
};
