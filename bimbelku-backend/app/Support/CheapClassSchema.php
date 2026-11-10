<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CheapClassSchema
{
    public static function status(): array
    {
        try {
            $status = Cache::remember(
                'schema.cheap_classes.v8',
                now()->addMinutes(5),
                fn () => self::inspect()
            );

            // Hasil "belum siap" tidak boleh ditahan cache. Setelah admin
            // menjalankan migrate, muat ulang berikutnya harus langsung
            // memeriksa skema baru tanpa menunggu lima menit.
            if (! $status['ready']) {
                Cache::forget('schema.cheap_classes.v8');
            }

            return $status;
        } catch (Throwable) {
            // Instalasi lama kadang belum memiliki tabel cache. Diagnostik
            // skema tetap harus bisa dimuat tanpa menjadikan cache syarat fitur.
            try {
                return self::inspect();
            } catch (Throwable) {
                return [
                    'ready' => false,
                    'missing' => ['database_connection'],
                    'message' => 'Database belum dapat diperiksa. Pastikan MySQL aktif, pengaturan .env benar, lalu jalankan php artisan migrate.',
                ];
            }
        }
    }

    private static function inspect(): array
    {
        $missing = [];
        $required = [
            'users' => ['id', 'name', 'role', 'status'],
            'curriculum_subjects' => [
                'id', 'name', 'group_name', 'education_levels', 'grades', 'is_active',
            ],
            'curriculum_chapters' => [
                'id', 'curriculum_subject_id', 'education_level', 'grade',
                'title', 'is_active',
            ],
            'teacher_profiles' => [
                'id', 'user_id', 'photo', 'verified_at', 'is_accepting_requests',
                'suspended_until',
            ],
            'teacher_subjects' => [
                'id', 'teacher_profile_id', 'curriculum_subject_id', 'name',
                'levels', 'is_active', 'is_online',
            ],
            'teacher_availabilities' => [
                'id', 'user_id', 'day', 'start_time', 'end_time', 'is_active',
            ],
            'teacher_availability_exceptions' => [
                'id', 'user_id', 'start_date', 'end_date',
            ],
            'bookings' => ['id', 'teacher_id', 'status', 'start_at', 'end_at'],
            'booking_participants' => ['id', 'booking_id', 'student_id', 'status'],
            'cheap_class_templates' => [
                'id', 'template_code', 'curriculum_subject_id', 'curriculum_chapter_id', 'subjects', 'teacher_id', 'subject_name',
                'education_level', 'grade', 'chapter', 'topic',
                'first_session_date', 'start_time', 'duration_minutes', 'session_count',
                'recurrence_days', 'recurrence_enabled', 'recurrence_anchor_at',
                'next_publish_at', 'last_published_at', 'last_skipped_at',
                'last_generation_failed_at', 'last_generation_error', 'settings_version',
                'price_per_student', 'price_per_session',
                'custom_price_per_student', 'minimum_participants',
                'maximum_participants', 'registration_window_hours',
                'registration_closes_before_minutes', 'payment_window_minutes',
                'is_active', 'created_at', 'updated_at',
            ],
            'cheap_classes' => [
                'id', 'package_code', 'cheap_class_template_id', 'occurrence_week_start',
                'generation_source', 'template_settings_version', 'template_snapshot',
                'curriculum_subject_id', 'curriculum_chapter_id', 'subjects',
                'teacher_id', 'subject_name', 'education_level', 'grade',
                'chapter', 'topic', 'starts_at', 'ends_at', 'session_count',
                'registration_opens_at', 'registration_deadline',
                'price_per_student', 'price_per_session', 'custom_price_per_student', 'minimum_participants',
                'maximum_participants', 'payment_window_minutes', 'status',
                'meeting_link', 'cancellation_reason', 'confirmed_at',
                'cancelled_at', 'created_at', 'updated_at',
            ],
            'cheap_class_sessions' => [
                'id', 'cheap_class_id', 'session_number', 'starts_at',
                'ends_at', 'status', 'progress_updates', 'progress_notes',
                'progress_recorded_at', 'progress_recorded_by', 'attended_participants_count',
                'report_submitted_at', 'report_submitted_by', 'admin_review_notes',
                'admin_reviewed_at', 'admin_reviewed_by', 'report_revision_count',
                'created_at', 'updated_at',
            ],
            'cheap_class_enrollments' => [
                'id', 'cheap_class_id', 'student_id', 'amount', 'status',
                'seat_expires_at', 'payment_submitted_at', 'confirmed_at',
                'cancelled_at', 'created_at', 'updated_at',
            ],
            'orders' => [
                'id', 'order_id', 'user_id', 'cheap_class_enrollment_id',
                'amount', 'subtotal_amount', 'discount_amount', 'status',
                'wallet_reserved_amount', 'wallet_applied_amount', 'wallet_reservation_version',
                'payment_proof', 'payment_rejection_reason',
                'payment_submitted_at', 'verified_at', 'verified_by',
                'class_details_snapshot', 'sender_name', 'bank_name',
                'sender_account_number',
            ],
            'idempotency_records' => [
                'id', 'actor_id', 'action', 'idempotency_key', 'request_hash',
                'status', 'response_status', 'response_body', 'expires_at',
            ],
            'refunds' => [
                'id', 'refund_code', 'order_id', 'user_id', 'booking_id', 'amount', 'reason',
                'status', 'destination_method', 'destination_bank_name',
                'destination_account_name', 'destination_account_number', 'destination_selected_at', 'destination_selection_version', 'proof',
                'processed_by', 'processed_at', 'notes',
            ],
            'customer_wallets' => ['id', 'user_id', 'current_balance', 'reserved_balance', 'currency'],
            'customer_wallet_transactions' => [
                'id', 'customer_wallet_id', 'user_id', 'refund_id', 'order_id', 'actor_id',
                'event_key', 'type', 'direction', 'amount', 'balance_before',
                'balance_after', 'description', 'metadata', 'created_at',
            ],
            'notifications' => ['id', 'user_id', 'title', 'message', 'type', 'target_url'],
        ];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;

                continue;
            }

            $available = array_flip(Schema::getColumnListing($table));
            foreach ($columns as $column) {
                if (! isset($available[$column])) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }

        return [
            'ready' => $missing === [],
            'missing' => $missing,
            'message' => $missing === []
                ? null
                : 'Database Kelas Kelompok belum diperbarui. Jalankan php artisan migrate dari folder bimbelku-backend.',
        ];
    }

    public static function ensureReady(): void
    {
        $status = self::status();
        abort_unless($status['ready'], 503, $status['message']);
    }
}
