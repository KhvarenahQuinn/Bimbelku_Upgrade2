<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_wallets')) {
            Schema::table('customer_wallets', function (Blueprint $table) {
                if (! Schema::hasColumn('customer_wallets', 'reserved_balance')) {
                    $table->decimal('reserved_balance', 15, 2)->default(0)->after('current_balance');
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'wallet_reserved_amount')) {
                    $table->decimal('wallet_reserved_amount', 15, 2)->default(0)->after('amount');
                }
                if (! Schema::hasColumn('orders', 'wallet_applied_amount')) {
                    $table->decimal('wallet_applied_amount', 15, 2)->default(0)->after('wallet_reserved_amount');
                }
                if (! Schema::hasColumn('orders', 'wallet_reservation_version')) {
                    $table->unsignedInteger('wallet_reservation_version')->default(0)->after('wallet_applied_amount');
                }
            });
        }

        if (Schema::hasTable('refunds')) {
            Schema::table('refunds', function (Blueprint $table) {
                if (! Schema::hasColumn('refunds', 'destination_selected_at')) {
                    $table->timestamp('destination_selected_at')->nullable()->after('destination_account_number');
                }
                if (! Schema::hasColumn('refunds', 'destination_selection_version')) {
                    $table->unsignedInteger('destination_selection_version')->default(0)->after('destination_selected_at');
                }
            });
        }

        if (Schema::hasTable('customer_wallet_transactions')) {
            Schema::table('customer_wallet_transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('customer_wallet_transactions', 'order_id')) {
                    $table->foreignId('order_id')
                        ->nullable()
                        ->after('refund_id')
                        ->constrained('orders')
                        ->nullOnDelete();
                    $table->index(['order_id', 'created_at']);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_wallet_transactions') && Schema::hasColumn('customer_wallet_transactions', 'order_id')) {
            Schema::table('customer_wallet_transactions', function (Blueprint $table) {
                $table->dropIndex(['order_id', 'created_at']);
                $table->dropConstrainedForeignId('order_id');
            });
        }

        if (Schema::hasTable('refunds')) {
            Schema::table('refunds', function (Blueprint $table) {
                foreach (['destination_selection_version', 'destination_selected_at'] as $column) {
                    if (Schema::hasColumn('refunds', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                foreach (['wallet_reservation_version', 'wallet_applied_amount', 'wallet_reserved_amount'] as $column) {
                    if (Schema::hasColumn('orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('customer_wallets') && Schema::hasColumn('customer_wallets', 'reserved_balance')) {
            Schema::table('customer_wallets', function (Blueprint $table) {
                $table->dropColumn('reserved_balance');
            });
        }
    }
};
