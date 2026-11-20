<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu keputusan admin atas sesi kelompok menghasilkan satu refund per murid
 * dengan `source_type`/`source_id` yang sama. Kunci idempotensi harus ikut
 * menyertakan murid agar refund anggota kedua tidak ditolak database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('refunds', 'refunds_source_unique')) {
            Schema::table('refunds', fn (Blueprint $table) => $table->dropUnique('refunds_source_unique'));
        }
        if (! Schema::hasIndex('refunds', 'refunds_source_participant_unique')) {
            Schema::table('refunds', function (Blueprint $table) {
                $table->unique(['source_type', 'source_id', 'user_id'], 'refunds_source_participant_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('refunds', 'refunds_source_participant_unique')) {
            Schema::table('refunds', fn (Blueprint $table) => $table->dropUnique('refunds_source_participant_unique'));
        }

        $hasDuplicateSources = DB::table('refunds')
            ->select('source_type', 'source_id')
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->groupBy('source_type', 'source_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if (! $hasDuplicateSources && ! Schema::hasIndex('refunds', 'refunds_source_unique')) {
            Schema::table('refunds', function (Blueprint $table) {
                $table->unique(['source_type', 'source_id'], 'refunds_source_unique');
            });
        }
    }
};
