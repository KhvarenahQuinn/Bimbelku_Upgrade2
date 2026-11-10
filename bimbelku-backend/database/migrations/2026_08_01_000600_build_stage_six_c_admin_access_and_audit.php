<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'admin_type')) {
                $table->string('admin_type', 30)->nullable()->after('role')->index();
            }
            if (! Schema::hasColumn('users', 'admin_permissions')) {
                $table->json('admin_permissions')->nullable()->after('admin_type');
            }
            if (! Schema::hasColumn('users', 'admin_permissions_updated_by')) {
                $table->foreignId('admin_permissions_updated_by')
                    ->nullable()
                    ->after('admin_permissions')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'admin_permissions_updated_at')) {
                $table->timestamp('admin_permissions_updated_at')->nullable()->after('admin_permissions_updated_by');
            }
        });

        // Seluruh admin lama tetap dapat masuk setelah migrasi. Superadmin utama
        // kemudian dapat membatasi admin lain dari halaman Kontrol Akses.
        DB::table('users')
            ->where('role', 'admin')
            ->whereNull('admin_type')
            ->update([
                'admin_type' => 'super_admin',
                'admin_permissions' => null,
                'admin_permissions_updated_at' => now(),
            ]);

        if (! Schema::hasTable('admin_audit_logs')) {
            Schema::create('admin_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name')->nullable();
                $table->string('actor_email')->nullable();
                $table->uuid('request_id')->unique();
                $table->string('action', 255);
                $table->string('category', 60)->index();
                $table->string('permission_code', 100)->nullable()->index();
                $table->string('route_name', 255);
                $table->string('method', 10);
                $table->unsignedSmallInteger('response_status')->index();
                $table->string('target_type', 120)->nullable()->index();
                $table->unsignedBigInteger('target_id')->nullable()->index();
                $table->text('reason')->nullable();
                $table->longText('request_payload')->nullable();
                $table->longText('before_state')->nullable();
                $table->longText('after_state')->nullable();
                $table->longText('metadata')->nullable();
                $table->text('ip_address')->nullable();
                $table->longText('user_agent')->nullable();
                $table->char('previous_hash', 64)->nullable();
                $table->char('entry_hash', 64)->unique();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->index(['actor_id', 'created_at']);
                $table->index(['target_type', 'target_id', 'created_at'], 'admin_audit_target_created_idx');
            });
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'admin_audit_chain_lock'],
            ['value' => '1', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        DB::table('settings')->where('key', 'admin_audit_chain_lock')->delete();

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'admin_permissions_updated_by')) {
                $table->dropConstrainedForeignId('admin_permissions_updated_by');
            }
            foreach (['admin_permissions_updated_at', 'admin_permissions', 'admin_type'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
