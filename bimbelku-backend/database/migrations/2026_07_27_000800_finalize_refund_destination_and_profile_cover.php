<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'sender_account_number')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('sender_account_number', 80)->nullable()->after('sender_name');
            });
        }

        if (Schema::hasTable('teacher_profiles') && Schema::hasColumn('teacher_profiles', 'cover')) {
            Schema::table('teacher_profiles', function (Blueprint $table) {
                $table->dropColumn('cover');
            });
        }

        $this->moveLegacySensitiveFilesToPrivateStorage();
    }

    public function down(): void
    {
        // Tujuan refund dan pembersihan sampul lama dipertahankan agar data tetap aman.
    }

    private function moveLegacySensitiveFilesToPrivateStorage(): void
    {
        $sensitiveColumns = [
            'teacher_profiles' => [
                'cv_file',
                'identity_document',
                'live_selfie',
                'qualification_document',
                'certification_document',
            ],
            'booking_requests' => ['attachment'],
            'orders' => ['payment_proof'],
            'bookings' => ['completion_evidence'],
            'session_reports' => ['evidence'],
            'booking_disputes' => ['evidence'],
            'payouts' => ['proof_url'],
            'refunds' => ['proof'],
            'ticket_replies' => ['attachment'],
        ];

        foreach ($sensitiveColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)
                    ->whereNotNull($column)
                    ->where($column, '<>', '')
                    ->distinct()
                    ->pluck($column)
                    ->each(fn (string $path) => $this->movePrivateFile($path));
            }
        }
    }

    private function movePrivateFile(string $path): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');

        if (! $public->exists($path)) {
            return;
        }

        if (! $private->exists($path)) {
            $stream = $public->readStream($path);
            if ($stream === false) {
                return;
            }

            try {
                $private->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        if ($private->exists($path)) {
            $public->delete($path);
        }
    }
};
