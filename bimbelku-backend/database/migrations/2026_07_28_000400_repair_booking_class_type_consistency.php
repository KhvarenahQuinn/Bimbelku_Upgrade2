<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('bookings')
            || ! Schema::hasTable('booking_requests')
            || ! Schema::hasTable('booking_participants')
        ) {
            return;
        }

        DB::table('bookings')
            ->join('booking_requests', 'booking_requests.id', '=', 'bookings.booking_request_id')
            ->whereColumn('bookings.class_type', '<>', 'booking_requests.class_type')
            ->select([
                'bookings.id as booking_id',
                'booking_requests.class_type as requested_class_type',
                'booking_requests.group_pool_id as requested_group_pool_id',
            ])
            ->orderBy('bookings.id')
            ->get()
            ->each(function (object $mismatch) {
                $participantRequestTypes = DB::table('booking_participants')
                    ->join(
                        'booking_requests',
                        'booking_requests.id',
                        '=',
                        'booking_participants.booking_request_id'
                    )
                    ->where('booking_participants.booking_id', $mismatch->booking_id)
                    ->whereNotIn('booking_participants.status', [
                        'cancelled',
                        'teacher_rejected',
                        'payment_expired',
                    ])
                    ->pluck('booking_requests.class_type')
                    ->unique()
                    ->values();

                $participantCount = DB::table('booking_participants')
                    ->where('booking_id', $mismatch->booking_id)
                    ->whereNotIn('status', [
                        'cancelled',
                        'teacher_rejected',
                        'payment_expired',
                    ])
                    ->count();

                $safePrivateRepair = $mismatch->requested_class_type === 'private'
                    && $participantCount <= 1
                    && ($participantRequestTypes->isEmpty()
                        || ($participantRequestTypes->count() === 1
                            && $participantRequestTypes->first() === 'private'));
                $safeGroupRepair = $mismatch->requested_class_type === 'group'
                    && $mismatch->requested_group_pool_id !== null
                    && $participantRequestTypes->count() === 1
                    && $participantRequestTypes->first() === 'group';

                if (! $safePrivateRepair && ! $safeGroupRepair) {
                    return;
                }

                DB::table('bookings')
                    ->where('id', $mismatch->booking_id)
                    ->update([
                        'class_type' => $mismatch->requested_class_type,
                        'group_pool_id' => $mismatch->requested_class_type === 'group'
                            ? $mismatch->requested_group_pool_id
                            : null,
                        'updated_at' => now(),
                    ]);

                if (! Schema::hasTable('orders')) {
                    return;
                }

                DB::table('orders')
                    ->where('booking_id', $mismatch->booking_id)
                    ->whereNotNull('class_details_snapshot')
                    ->get(['id', 'class_details_snapshot'])
                    ->each(function (object $order) use ($mismatch) {
                        $snapshot = json_decode($order->class_details_snapshot, true);
                        if (! is_array($snapshot)) {
                            return;
                        }

                        $snapshot['type'] = $mismatch->requested_class_type === 'group'
                            ? 'Kelompok'
                            : 'Privat';
                        DB::table('orders')
                            ->where('id', $order->id)
                            ->update([
                                'class_details_snapshot' => json_encode(
                                    $snapshot,
                                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                ),
                                'updated_at' => now(),
                            ]);
                    });
            });
    }

    public function down(): void
    {
        // Rekonsiliasi data tidak dibalik karena nilai sebelumnya merupakan inkonsistensi.
    }
};
