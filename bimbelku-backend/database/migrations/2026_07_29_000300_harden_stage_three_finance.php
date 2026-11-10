<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendUsers();
        $this->extendTeacherProfiles();
        $this->extendOrders();
        $this->createFinancialJournals();
        $this->createFinancialAuditLogs();
        $this->createIdempotencyRecords();
        $this->createFinanceAuthorizations();
        $this->createPayoutApprovals();
        $this->extendPayouts();
        $this->seedSettings();
    }

    public function down(): void
    {
        if (Schema::hasTable('payouts') && Schema::hasColumn('payouts', 'payout_approval_id')) {
            Schema::table('payouts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('payout_approval_id');
            });
        }

        Schema::dropIfExists('payout_approvals');
        Schema::dropIfExists('finance_authorizations');
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('financial_audit_logs');
        Schema::dropIfExists('financial_ledger_entries');
        Schema::dropIfExists('financial_journals');

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                foreach (['payment_provider', 'provider_reference'] as $column) {
                    if (Schema::hasColumn('orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('teacher_profiles')) {
            Schema::table('teacher_profiles', function (Blueprint $table) {
                foreach ([
                    'bank_account_changed_at',
                    'payout_hold_until',
                    'bank_details_version',
                    'bank_account_fingerprint',
                ] as $column) {
                    if (Schema::hasColumn('teacher_profiles', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                foreach (['finance_totp_secret', 'finance_totp_confirmed_at'] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function extendUsers(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'finance_totp_secret')) {
                $table->text('finance_totp_secret')->nullable();
            }
            if (! Schema::hasColumn('users', 'finance_totp_confirmed_at')) {
                $table->timestamp('finance_totp_confirmed_at')->nullable();
            }
        });
    }

    private function extendTeacherProfiles(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_profiles', 'bank_account_changed_at')) {
                $table->timestamp('bank_account_changed_at')->nullable();
            }
            if (! Schema::hasColumn('teacher_profiles', 'payout_hold_until')) {
                $table->timestamp('payout_hold_until')->nullable()->index();
            }
            if (! Schema::hasColumn('teacher_profiles', 'bank_details_version')) {
                $table->unsignedInteger('bank_details_version')->default(0);
            }
            if (! Schema::hasColumn('teacher_profiles', 'bank_account_fingerprint')) {
                $table->char('bank_account_fingerprint', 64)->nullable();
            }
        });
    }

    private function extendOrders(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'payment_provider')) {
                $table->string('payment_provider', 40)->default('manual_transfer');
            }
            if (! Schema::hasColumn('orders', 'provider_reference')) {
                $table->string('provider_reference', 120)->nullable()->unique();
            }
        });
    }

    private function createFinancialJournals(): void
    {
        if (! Schema::hasTable('financial_journals')) {
            Schema::create('financial_journals', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('event_key', 190)->unique();
                $table->string('event_type', 80);
                $table->string('reference_type', 80)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('description', 500);
                $table->char('previous_hash', 64)->nullable();
                $table->char('entry_hash', 64)->unique();
                $table->timestamp('occurred_at');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['reference_type', 'reference_id']);
                $table->index(['event_type', 'occurred_at']);
            });
        }

        if (! Schema::hasTable('financial_ledger_entries')) {
            Schema::create('financial_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('financial_journal_id')
                    ->constrained('financial_journals')
                    ->cascadeOnDelete();
                $table->string('account_code', 60);
                $table->string('side', 6);
                $table->decimal('amount', 15, 2);
                $table->char('currency', 3)->default('IDR');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['account_code', 'created_at']);
            });
        }
    }

    private function createFinancialAuditLogs(): void
    {
        if (Schema::hasTable('financial_audit_logs')) {
            return;
        }

        Schema::create('financial_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('action', 120);
            $table->string('route_name', 180)->nullable();
            $table->string('method', 10);
            $table->unsignedSmallInteger('response_status');
            $table->string('target_type', 80)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->longText('payload')->nullable();
            $table->text('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('entry_hash', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    private function createIdempotencyRecords(): void
    {
        if (Schema::hasTable('idempotency_records')) {
            return;
        }

        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 120);
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(
                ['actor_id', 'action', 'idempotency_key'],
                'idempotency_actor_action_key_unique'
            );
        });
    }

    private function createFinanceAuthorizations(): void
    {
        if (Schema::hasTable('finance_authorizations')) {
            return;
        }

        Schema::create('finance_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_fingerprint', 64);
            $table->timestamp('verified_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'token_fingerprint'], 'finance_auth_user_token_unique');
        });
    }

    private function createPayoutApprovals(): void
    {
        if (Schema::hasTable('payout_approvals')) {
            return;
        }

        Schema::create('payout_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->json('booking_ids');
            $table->decimal('amount', 15, 2);
            $table->char('fingerprint', 64);
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['fingerprint', 'status'], 'payout_approval_fingerprint_status_idx');
            $table->index(['status', 'created_at']);
        });
    }

    private function extendPayouts(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('payouts', 'payout_approval_id')) {
                $table->foreignId('payout_approval_id')
                    ->nullable()
                    ->unique()
                    ->constrained('payout_approvals')
                    ->nullOnDelete();
            }
        });
    }

    private function seedSettings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ([
            'high_value_payout_threshold' => '5000000',
            'bank_change_hold_hours' => '24',
            'finance_2fa_window_minutes' => '10',
            'finance_ledger_chain_lock' => '1',
            'finance_audit_chain_lock' => '1',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
};
