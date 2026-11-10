<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();
        foreach ([
            'teacher_response_online_minutes' => '30',
            'teacher_response_offline_minutes' => '120',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        if (! Schema::hasTable('teacher_offers') || ! Schema::hasTable('booking_requests')) {
            return;
        }

        DB::table('teacher_offers')
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(100, function ($offers): void {
                foreach ($offers as $offer) {
                    $bookingRequest = DB::table('booking_requests')
                        ->where('id', $offer->booking_request_id)
                        ->first([
                            'id',
                            'learning_mode',
                            'scheduled_date',
                            'start_time',
                            'search_expires_at',
                            'teacher_response_deadline',
                        ]);

                    if (! $bookingRequest || ! $offer->offered_at) {
                        continue;
                    }

                    $minutes = $bookingRequest->learning_mode === 'offline' ? 120 : 30;
                    $deadline = Carbon::parse($offer->offered_at)->addMinutes($minutes);

                    if ($offer->expires_at) {
                        $deadline = $deadline->min(Carbon::parse($offer->expires_at));
                    }
                    if ($bookingRequest->search_expires_at) {
                        $deadline = $deadline->min(Carbon::parse($bookingRequest->search_expires_at));
                    }
                    if ($bookingRequest->scheduled_date && $bookingRequest->start_time) {
                        $classStart = Carbon::parse(
                            Carbon::parse($bookingRequest->scheduled_date)->toDateString().' '.$bookingRequest->start_time,
                            config('app.timezone', 'Asia/Jakarta')
                        );
                        $deadline = $deadline->min($classStart);
                    }

                    DB::table('teacher_offers')
                        ->where('id', $offer->id)
                        ->where('status', 'pending')
                        ->update([
                            'expires_at' => $deadline,
                            'updated_at' => now(),
                        ]);

                    DB::table('booking_requests')
                        ->where('id', $bookingRequest->id)
                        ->where('status', 'teacher_pending')
                        ->update([
                            'teacher_response_deadline' => $deadline,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', [
                'teacher_response_online_minutes',
                'teacher_response_offline_minutes',
            ])
            ->delete();
    }
};
