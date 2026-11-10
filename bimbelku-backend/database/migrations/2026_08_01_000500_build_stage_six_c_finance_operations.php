<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_wallets')) {
            Schema::create('customer_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->decimal('current_balance', 15, 2)->default(0);
                $table->char('currency', 3)->default('IDR');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_wallet_transactions')) {
            Schema::create('customer_wallet_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_wallet_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('refund_id')->nullable()->unique()->constrained()->nullOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_key', 190)->unique();
                $table->string('type', 50);
                $table->string('direction', 10);
                $table->decimal('amount', 15, 2);
                $table->decimal('balance_before', 15, 2);
                $table->decimal('balance_after', 15, 2);
                $table->string('description', 500);
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['user_id', 'created_at']);
                $table->index(['type', 'created_at']);
            });
        }

        if (Schema::hasTable('refunds')) {
            Schema::table('refunds', function (Blueprint $table) {
                if (! Schema::hasColumn('refunds', 'destination_method')) {
                    $table->string('destination_method', 30)->nullable()->after('status')->index();
                }
                if (! Schema::hasColumn('refunds', 'destination_bank_name')) {
                    $table->string('destination_bank_name', 120)->nullable()->after('destination_method');
                }
                if (! Schema::hasColumn('refunds', 'destination_account_name')) {
                    $table->string('destination_account_name', 160)->nullable()->after('destination_bank_name');
                }
                if (! Schema::hasColumn('refunds', 'destination_account_number')) {
                    $table->string('destination_account_number', 100)->nullable()->after('destination_account_name');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('refunds')) {
            Schema::table('refunds', function (Blueprint $table) {
                if (Schema::hasColumn('refunds', 'destination_method')) {
                    $table->dropIndex(['destination_method']);
                }
                foreach ([
                    'destination_method',
                    'destination_bank_name',
                    'destination_account_name',
                    'destination_account_number',
                ] as $column) {
                    if (Schema::hasColumn('refunds', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('customer_wallet_transactions');
        Schema::dropIfExists('customer_wallets');
    }
};
