<?php

use App\Support\WalletLedgerSignature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_wallet_transactions')) {
            return;
        }

        Schema::table('customer_wallet_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_wallet_transactions', 'reference_type')) {
                $table->string('reference_type', 50)->nullable()->after('event_key');
            }
            if (! Schema::hasColumn('customer_wallet_transactions', 'reference_id')) {
                $table->string('reference_id', 190)->nullable()->after('reference_type');
            }
            if (! Schema::hasColumn('customer_wallet_transactions', 'checksum')) {
                $table->char('checksum', 64)->nullable()->after('metadata');
            }
        });

        $signature = app(WalletLedgerSignature::class);
        DB::table('customer_wallet_transactions')->orderBy('id')->chunkById(200, function ($rows) use ($signature) {
            foreach ($rows as $row) {
                $attributes = (array) $row;
                if (blank($attributes['reference_type'] ?? null) || blank($attributes['reference_id'] ?? null)) {
                    [$referenceType, $referenceId] = $signature->referenceFor($attributes);
                    $attributes['reference_type'] = $referenceType;
                    $attributes['reference_id'] = $referenceId;
                }
                $checksum = $signature->sign($attributes);

                DB::table('customer_wallet_transactions')->where('id', $row->id)->update([
                    'reference_type' => $attributes['reference_type'],
                    'reference_id' => $attributes['reference_id'],
                    'checksum' => $checksum,
                ]);
            }
        });

        Schema::table('customer_wallet_transactions', function (Blueprint $table) {
            $table->unique(['reference_type', 'reference_id'], 'wallet_transactions_reference_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_wallet_transactions')) {
            return;
        }

        Schema::table('customer_wallet_transactions', function (Blueprint $table) {
            $table->dropUnique('wallet_transactions_reference_unique');
        });
        Schema::table('customer_wallet_transactions', function (Blueprint $table) {
            foreach (['checksum', 'reference_id', 'reference_type'] as $column) {
                if (Schema::hasColumn('customer_wallet_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
