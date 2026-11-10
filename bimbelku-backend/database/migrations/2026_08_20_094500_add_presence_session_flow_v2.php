<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'session_flow_version')) {
                $table->string('session_flow_version', 40)->default('legacy_pin_v1')->after('status');
                $table->index(['session_flow_version', 'status'], 'bookings_session_flow_status_idx');
            }
            if (! Schema::hasColumn('bookings', 'tutor_ready_at')) {
                $table->timestamp('tutor_ready_at')->nullable()->after('session_flow_version');
            }
            if (! Schema::hasColumn('bookings', 'student_confirmed_at')) {
                $table->timestamp('student_confirmed_at')->nullable()->after('tutor_ready_at');
            }
            if (! Schema::hasColumn('bookings', 'session_focus_note')) {
                $table->text('session_focus_note')->nullable()->after('student_confirmed_at');
            }
            if (! Schema::hasColumn('bookings', 'tutor_ready_latitude')) {
                $table->decimal('tutor_ready_latitude', 10, 7)->nullable()->after('session_focus_note');
            }
            if (! Schema::hasColumn('bookings', 'tutor_ready_longitude')) {
                $table->decimal('tutor_ready_longitude', 10, 7)->nullable()->after('tutor_ready_latitude');
            }
            if (! Schema::hasColumn('bookings', 'tutor_ready_accuracy_meters')) {
                $table->unsignedSmallInteger('tutor_ready_accuracy_meters')->nullable()->after('tutor_ready_longitude');
            }
            if (! Schema::hasColumn('bookings', 'tutor_ready_ip_hash')) {
                $table->string('tutor_ready_ip_hash', 64)->nullable()->after('tutor_ready_accuracy_meters');
            }
        });

        DB::table('settings')->updateOrInsert(
            ['key' => 'session_presence_review_hours'],
            ['value' => '24', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        // Compatibility first: historical session audit data is intentionally retained.
    }
};
