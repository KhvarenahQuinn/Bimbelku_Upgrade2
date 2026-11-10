<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\LearningPackage;
use App\Models\LearningProgressReport;
use App\Models\Order;
use App\Models\PackageChapter;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSessionChapterLog;
use App\Models\PackageSubject;
use App\Models\ParticipantAttendance;
use App\Models\SessionAttendance;
use App\Models\Setting;
use App\Models\User;
use App\Services\ScheduleReleaseService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetReanRinaStage4 extends Command
{
    protected $signature = 'demo:reset-rean-rina-stage4';

    protected $description = 'Membersihkan jadwal Rean dan Rina, lalu membuat satu paket Stage 4 bersih berisi 12 sesi dan 5 Bab';

    private const STUDENT_EMAIL = 'rean.magangjogja@gmail.com';

    private const TEACHER_EMAIL = 'rina.matematika@bimbelku.com';

    private const PACKAGE_CODE = 'BKU-STAGE4-REAN-0001';

    private const CHAPTER_TITLES = [
        'Transformasi Fungsi',
        'Geometri Analitik',
        'Matrix',
        'Kalkulus Dasar',
        'Trigonometri',
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Command reset hanya boleh dijalankan pada environment local/testing.');

            return self::FAILURE;
        }

        $student = User::query()->where('email', self::STUDENT_EMAIL)->first();
        $teacher = User::query()->where('email', self::TEACHER_EMAIL)->first();

        if (! $student || ! $teacher) {
            $this->error('Akun Rean atau Rina tidak ditemukan.');

            return self::FAILURE;
        }

        $start = Carbon::today('Asia/Jakarta')->setTime(16, 0, 0);
        $end = $start->copy()->addHour();

        $foreignConflict = Booking::query()
            ->where('teacher_id', $teacher->id)
            ->where('student_id', '!=', $student->id)
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->exists();

        if ($foreignConflict) {
            $this->error('Rina masih punya sesi murid lain pada slot 16:00-17:00 WIB. Slot tidak dibersihkan otomatis.');

            return self::FAILURE;
        }

        try {
            $summary = DB::transaction(function () use ($student, $teacher, $start, $end) {
                $scheduleRelease = app(ScheduleReleaseService::class);
                $plan = PackagePlan::query()->find(4)
                    ?? PackagePlan::query()->where('session_count', 12)->orderBy('id')->firstOrFail();

                $targetPackage = LearningPackage::query()
                    ->where('package_code', self::PACKAGE_CODE)
                    ->lockForUpdate()
                    ->first();

                if (! $targetPackage) {
                    $targetPackage = LearningPackage::create([
                        'student_id' => $student->id,
                        'package_plan_id' => $plan->id,
                        'package_code' => self::PACKAGE_CODE,
                        'education_level' => 'SMA',
                        'grade' => 'Kelas 11',
                        'learning_mode' => 'online',
                        'duration_hours' => 1,
                        'status' => 'active',
                        'total_sessions' => 12,
                        'used_sessions' => 0,
                        'subtotal_amount' => 480000,
                        'discount_amount' => 0,
                        'total_amount' => 480000,
                        'participant_count' => 1,
                        'purchaser_participates' => true,
                        'participant_names' => [$student->name],
                        'participant_details' => [],
                        'starts_at' => $start,
                        'expires_at' => $start->copy()->addDays(30),
                    ]);
                }

                $targetSubject = PackageSubject::query()
                    ->where('learning_package_id', $targetPackage->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $targetSubject) {
                    $targetSubject = PackageSubject::create([
                        'learning_package_id' => $targetPackage->id,
                        'curriculum_subject_id' => 9,
                        'curriculum_chapter_id' => null,
                        'curriculum_chapter_ids' => [],
                        'assigned_teacher_id' => $teacher->id,
                        'subject_name' => 'Matematika',
                        'chapter' => 'Transformasi Fungsi +4',
                        'allocated_sessions' => 12,
                        'unit_price' => 40000,
                        'tutor_hourly_gross' => 40000,
                        'subtotal_amount' => 480000,
                        'status' => 'active',
                    ]);
                }

                $targetRequest = BookingRequest::query()
                    ->where('package_subject_id', $targetSubject->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $targetBooking = $targetRequest?->booking()->lockForUpdate()->first()
                    ?? Booking::query()
                        ->where('student_id', $student->id)
                        ->where('teacher_id', $teacher->id)
                        ->where('start_at', $start)
                        ->lockForUpdate()
                        ->first();

                // Bersihkan paket aktif lain milik Rean agar hanya satu paket
                // yang tersisa dan tidak ada slot lama yang masih memblokir jadwal.
                $packagesToCancel = LearningPackage::query()
                    ->where('student_id', $student->id)
                    ->where('id', '!=', $targetPackage->id)
                    ->whereIn('status', ['active', 'matching', 'teacher_pending'])
                    ->get();

                foreach ($packagesToCancel as $package) {
                    $scheduleRelease->releasePackage($package, 'cancelled', 'cancelled');
                    $package->subjects()->update(['status' => 'cancelled']);
                    $package->update(['status' => 'cancelled', 'completed_at' => null]);
                }

                $bookingsToCancel = Booking::query()
                    ->where('student_id', $student->id)
                    ->when($targetBooking, fn ($query) => $query->where('id', '!=', $targetBooking->id))
                    ->whereNotIn('status', ['cancelled', 'completed', 'refunded', 'payment_expired', 'teacher_replaced'])
                    ->get();

                foreach ($bookingsToCancel as $booking) {
                    $scheduleRelease->releaseBooking($booking, 'cancelled');
                }

                BookingRequest::query()
                    ->where('student_id', $student->id)
                    ->where('status', 'confirmed')
                    ->when($targetRequest, fn ($query) => $query->where('id', '!=', $targetRequest->id))
                    ->update(['status' => 'cancelled']);

                if (! $targetRequest) {
                    $targetRequest = BookingRequest::create([
                        'student_id' => $student->id,
                        'matched_teacher_id' => $teacher->id,
                        'package_subject_id' => $targetSubject->id,
                        'curriculum_subject_id' => 9,
                        'subject_name' => 'Matematika',
                        'education_level' => 'SMA',
                        'grade' => 'Kelas 11',
                        'learning_mode' => 'online',
                        'class_type' => 'private',
                        'scheduled_date' => $start->toDateString(),
                        'start_time' => $start->format('H:i:s'),
                        'end_time' => $end->format('H:i:s'),
                        'duration_hours' => 1,
                        'status' => 'confirmed',
                        'matching_attempts' => 1,
                        'hourly_rate' => 40000,
                        'total_amount' => 40000,
                        'chapter' => 'Transformasi Fungsi +4',
                    ]);
                }

                if (! $targetBooking) {
                    $targetBooking = Booking::create([
                        'booking_request_id' => $targetRequest->id,
                        'student_id' => $student->id,
                        'teacher_id' => $teacher->id,
                        'start_at' => $start,
                        'end_at' => $end,
                        'duration_hours' => 1,
                        'learning_mode' => 'online',
                        'class_type' => 'private',
                        'hourly_rate' => 40000,
                        'total_amount' => 40000,
                        'status' => 'confirmed',
                        'session_flow_version' => 'presence_confirmation_v2',
                        'commission_percent' => 20,
                        'gross_amount' => 40000,
                        'teacher_net_amount' => 32000,
                        'payout_status' => 'locked',
                    ]);
                    $targetRequest->update(['booking_id' => $targetBooking->id]);
                }

                $targetOrder = Order::query()
                    ->where('learning_package_id', $targetPackage->id)
                    ->orWhere('booking_id', $targetBooking->id)
                    ->orderByDesc('id')
                    ->first();

                if (! $targetOrder) {
                    $targetOrder = Order::create([
                        'user_id' => $student->id,
                        'learning_package_id' => $targetPackage->id,
                        'booking_id' => $targetBooking->id,
                        'order_id' => 'PKG-STAGE4-REAN-0001',
                        'amount' => 480000,
                        'subtotal_amount' => 480000,
                        'discount_amount' => 0,
                        'external_received_amount' => 480000,
                        'payment_outstanding_amount' => 0,
                        'payment_surplus_amount' => 0,
                        'payment_reconciliation_status' => 'exact',
                        'status' => 'paid',
                    ]);
                }

                $targetRequest->update([
                    'student_id' => $student->id,
                    'matched_teacher_id' => $teacher->id,
                    'package_subject_id' => $targetSubject->id,
                    'curriculum_subject_id' => 9,
                    'subject_name' => 'Matematika',
                    'education_level' => 'SMA',
                    'grade' => 'Kelas 11',
                    'learning_mode' => 'online',
                    'class_type' => 'private',
                    'scheduled_date' => $start->toDateString(),
                    'start_time' => $start->format('H:i:s'),
                    'end_time' => $end->format('H:i:s'),
                    'duration_hours' => 1,
                    'status' => 'confirmed',
                    'matching_attempts' => 1,
                    'hourly_rate' => 40000,
                    'total_amount' => 40000,
                    'chapter' => 'Transformasi Fungsi +4',
                    'learning_goal' => 'Penguatan Transformasi Fungsi, Geometri Analitik, Matrix, Kalkulus Dasar, dan Trigonometri.',
                    'booking_id' => $targetBooking->id,
                    'teacher_rejection_reason' => null,
                    'teacher_response_deadline' => null,
                    'next_matching_at' => null,
                    'payment_due_at' => null,
                    'search_started_at' => null,
                    'search_expires_at' => null,
                    'teacher_decision_deadline' => null,
                ]);

                $targetBooking->update([
                    'booking_request_id' => $targetRequest->id,
                    'student_id' => $student->id,
                    'teacher_id' => $teacher->id,
                    'order_id' => $targetOrder->id,
                    'start_at' => $start,
                    'end_at' => $end,
                    'duration_hours' => 1,
                    'learning_mode' => 'online',
                    'class_type' => 'private',
                    'hourly_rate' => 40000,
                    'total_amount' => 40000,
                    'status' => 'confirmed',
                    'session_flow_version' => 'presence_confirmation_v2',
                    'tutor_ready_at' => now('Asia/Jakarta'),
                    'student_confirmed_at' => null,
                    'session_focus_note' => 'Transformasi Fungsi - sesi manual Stage 4.',
                    'tutor_ready_latitude' => null,
                    'tutor_ready_longitude' => null,
                    'tutor_ready_accuracy_meters' => null,
                    'tutor_ready_ip_hash' => null,
                    'payment_due_at' => null,
                    'address' => null,
                    'maps_link' => null,
                    'commission_percent' => 20,
                    'gross_amount' => 40000,
                    'teacher_net_amount' => 32000,
                    'teacher_paid_amount' => 0,
                    'teacher_reserved_amount' => 0,
                    'meeting_link' => 'https://zoom.us/j/99999999999',
                    'completion_notes' => null,
                    'completion_submitted_at' => null,
                    'objection_deadline' => null,
                    'student_approved_at' => null,
                    'admin_review_required_at' => null,
                    'completed_at' => null,
                    'payout_status' => 'locked',
                    'session_started_at' => null,
                    'session_ended_at' => null,
                    'payout_request_id' => null,
                ]);

                $targetOrder->update([
                    'user_id' => $student->id,
                    'learning_package_id' => $targetPackage->id,
                    'booking_id' => $targetBooking->id,
                    'amount' => 480000,
                    'subtotal_amount' => 480000,
                    'discount_amount' => 0,
                    'external_received_amount' => 480000,
                    'payment_outstanding_amount' => 0,
                    'payment_surplus_amount' => 0,
                    'payment_reconciliation_status' => 'exact',
                    'status' => 'paid',
                    'class_details_snapshot' => [
                        'flow_version' => 6,
                        'learning_package_id' => $targetPackage->id,
                        'package_code' => $targetPackage->package_code,
                        'package_name' => $plan->name,
                        'subject' => 'Matematika',
                        'teacher_name' => $teacher->name,
                        'type' => 'Paket Privat',
                        'method' => 'online',
                        'session_count' => 12,
                        'duration_hours' => 1,
                        'total_learning_hours' => 12,
                        'subtotal_amount' => 480000,
                        'discount_amount' => 0,
                        'promotion_title' => null,
                        'promotion_code' => null,
                        'start_at' => $start->toIso8601String(),
                    ],
                ]);

                $targetPackage->update([
                    'student_id' => $student->id,
                    'package_plan_id' => $plan->id,
                    'package_code' => self::PACKAGE_CODE,
                    'education_level' => 'SMA',
                    'grade' => 'Kelas 11',
                    'learning_mode' => 'online',
                    'duration_hours' => 1,
                    'status' => 'active',
                    'total_sessions' => 12,
                    'used_sessions' => 0,
                    'subtotal_amount' => 480000,
                    'discount_amount' => 0,
                    'total_amount' => 480000,
                    'payment_due_at' => null,
                    'starts_at' => $start,
                    'expires_at' => $start->copy()->addDays(30),
                    'completed_at' => null,
                    'participant_count' => 1,
                    'purchaser_participates' => true,
                    'participant_names' => [$student->name],
                    'participant_details' => [],
                ]);

                $targetSubject->update([
                    'learning_package_id' => $targetPackage->id,
                    'curriculum_subject_id' => 9,
                    'curriculum_chapter_id' => null,
                    'curriculum_chapter_ids' => [],
                    'assigned_teacher_id' => $teacher->id,
                    'preferred_teacher_id' => null,
                    'subject_name' => 'Matematika',
                    'chapter' => 'Transformasi Fungsi +4',
                    'learning_goal' => 'Penguatan Transformasi Fungsi, Geometri Analitik, Matrix, Kalkulus Dasar, dan Trigonometri.',
                    'allocated_sessions' => 12,
                    'unit_price' => 40000,
                    'tutor_hourly_gross' => 40000,
                    'subtotal_amount' => 480000,
                    'status' => 'active',
                ]);

                $participant = BookingParticipant::query()->where('booking_id', $targetBooking->id)->first();
                $participantPayload = [
                    'booking_id' => $targetBooking->id,
                    'booking_request_id' => $targetRequest->id,
                    'student_id' => $student->id,
                    'order_id' => $targetOrder->id,
                    'amount' => 40000,
                    'status' => 'paid',
                ];
                if ($participant) {
                    $participant->update($participantPayload);
                } else {
                    BookingParticipant::create($participantPayload);
                }

                // Reset seluruh jejak belajar sesi target sebelum sesi dibuat ulang.
                $reportIds = LearningProgressReport::query()
                    ->where('booking_id', $targetBooking->id)
                    ->pluck('id');
                $sessionIds = PackageSession::query()
                    ->where('package_subject_id', $targetSubject->id)
                    ->pluck('id');

                PackageSessionChapterLog::query()
                    ->whereIn('learning_progress_report_id', $reportIds)
                    ->orWhereIn('package_session_id', $sessionIds)
                    ->delete();
                LearningProgressReport::query()->whereIn('id', $reportIds)->delete();
                SessionAttendance::query()->where('booking_id', $targetBooking->id)->delete();
                ParticipantAttendance::query()->where('booking_id', $targetBooking->id)->delete();
                PackageSession::query()->whereIn('id', $sessionIds)->delete();

                PackageChapter::query()->where('package_subject_id', $targetSubject->id)->delete();
                foreach (self::CHAPTER_TITLES as $index => $title) {
                    PackageChapter::create([
                        'package_subject_id' => $targetSubject->id,
                        'curriculum_chapter_id' => null,
                        'title' => $title,
                        'status' => 'not_started',
                        'needs_review' => false,
                        'sort_order' => $index + 1,
                    ]);
                }

                for ($sequence = 1; $sequence <= 12; $sequence++) {
                    $sessionStart = $start->copy()->addDays(($sequence - 1) * 2);
                    PackageSession::create([
                        'package_subject_id' => $targetSubject->id,
                        'booking_id' => $sequence === 1 ? $targetBooking->id : null,
                        'sequence' => $sequence,
                        'scheduled_start_at' => $sessionStart,
                        'scheduled_end_at' => $sessionStart->copy()->addHour(),
                        'status' => 'scheduled',
                    ]);
                }

                return [
                    'package' => $targetPackage->fresh(),
                    'subject' => $targetSubject->fresh(),
                    'booking' => $targetBooking->fresh(),
                    'order' => $targetOrder->fresh(),
                    'cancelled_packages' => $packagesToCancel->count(),
                    'cancelled_bookings' => $bookingsToCancel->count(),
                ];
            }, 3);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        Setting::query()->updateOrCreate(
            ['key' => 'session_checkin_after_minutes'],
            ['value' => '240']
        );

        $collisions = Booking::query()
            ->where(function ($query) use ($student, $teacher) {
                $query->where('student_id', $student->id)
                    ->orWhere('teacher_id', $teacher->id);
            })
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->get();

        $foreignCollisions = $collisions->where('id', '!=', $summary['booking']->id);
        if ($foreignCollisions->isNotEmpty()) {
            $this->error('Masih ada sesi bentrok: '.$foreignCollisions->pluck('id')->implode(', '));

            return self::FAILURE;
        }

        $activePackages = LearningPackage::query()
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->get();

        $chapterCount = PackageChapter::query()
            ->where('package_subject_id', $summary['subject']->id)
            ->where('status', 'not_started')
            ->count();
        $sessionCount = PackageSession::query()
            ->where('package_subject_id', $summary['subject']->id)
            ->count();

        $this->info('Reset Stage 4 Rean + Rina selesai.');
        $this->line('Paket aktif: '.$summary['package']->id.' · '.$summary['package']->package_code);
        $this->line('Total sesi paket: '.$summary['package']->total_sessions.' · terpakai: '.$summary['package']->used_sessions);
        $this->line('Booking target: '.$summary['booking']->id.' · status '.$summary['booking']->status.' · tutor ready '.$summary['booking']->tutor_ready_at?->format('d M Y H:i').' WIB');
        $this->line('Jadwal: '.$summary['booking']->start_at?->timezone('Asia/Jakarta')->format('d M Y H:i').'–'.$summary['booking']->end_at?->timezone('Asia/Jakarta')->format('H:i').' WIB');
        $this->line('Paket dibatalkan: '.$summary['cancelled_packages'].' · booking dibatalkan: '.$summary['cancelled_bookings']);
        $this->line('Bab siap: '.$chapterCount.'/5 · package sessions: '.$sessionCount.'/12');
        $this->line('Paket aktif Rean: '.$activePackages->count().' · sesi bentrok: '.$foreignCollisions->count());

        if ($activePackages->count() !== 1 || $activePackages->first()?->id !== $summary['package']->id) {
            $this->error('Jumlah paket aktif Rean bukan tepat satu.');

            return self::FAILURE;
        }

        if ($chapterCount !== 5 || $sessionCount !== 12) {
            $this->error('Jumlah Bab atau sesi paket tidak sesuai.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
