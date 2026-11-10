<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classroom_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('classroom_messages', 'message_type')) {
                $table->string('message_type', 20)->default('user')->after('body');
            }
            if (! Schema::hasColumn('classroom_messages', 'system_event_key')) {
                $table->string('system_event_key', 120)->nullable()->after('message_type');
            }
            if (! Schema::hasColumn('classroom_messages', 'metadata')) {
                $table->json('metadata')->nullable()->after('system_event_key');
            }
        });

        Schema::table('classroom_messages', function (Blueprint $table) {
            $table->unique(
                ['booking_id', 'system_event_key'],
                'classroom_messages_system_event_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('classroom_messages', function (Blueprint $table) {
            $table->dropUnique('classroom_messages_system_event_unique');
            $table->dropColumn(['message_type', 'system_event_key', 'metadata']);
        });
    }
};
