<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoAddReanRinaSession extends Command
{
    protected $signature = 'demo:add-rean-rina-session
        {--settle : Tandai sesi selesai dan lunasi hak tutor}
        {--date=2026-11-10}
        {--time=14:00}';

    protected $description = 'Menambahkan satu sesi demo Rean dan Rina pada slot tertentu (idempoten)';

    private const STUDENT_EMAIL = 'rean.magangjogja@gmail.com';

    private const TEACHER_EMAIL = 'rina.matematika@bimbelku.com';

    private const SUBJECT_NAME = 'Matematika';

    private const CHAPTER = 'Persamaan Linear';

    private const HOURLY_RATE = 40000;

    private const COMMISSION_PERCENT = 20;

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Command demo hanya boleh dijalankan pada environment local/testing.');

            return self::FAILURE;
        }

        $student = User::query()->where('email', self::STUDENT_EMAIL)->first();
        $teacher = User::query()->where('email', self::TEACHER_EMAIL)->first();

        if (! $student || ! $teacher) {
            $missing = array_filter([
                ! $student ? 'Rean ('.self::STUDENT_EMAIL.')' : null,
                ! $teacher ? 'Rina ('.self::TEACHER_EMAIL.')' : null,
            ]);
            $this->error('Akun tidak ditemukan: '.implode(', ', $missing).'.');

            return self::FAILURE;
        }

        $start = Carbon::parse($this->option('date').' '.$this->option('time'), 'Asia/Jakarta');
        $end = $start->copy()->addHour();

        $existing = Booking::query()
            ->where('teacher_id', $teacher->id)
            ->where('student_id', $student->id)
            ->where('start_at', $start)
            ->first();

        if ($existing) {
            $this->warn('Sesi Rean + Rina pada slot ini sudah ada, tidak ada perubahan.');
            $this->printSummary($existing, $existing->order?->order_id);

            return self::SUCCESS;
        }

        $foreignConflict = Booking::query()
            ->where('teacher_id', $teacher->id)
            ->where('student_id', '!=', $student->id)
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->exists();

        if ($foreignConflict) {
            $this->error('Rina masih punya sesi murid lain pada slot '.$start->format('H:i').'-'.$end->format('H:i').' WIB.');

            return self::FAILURE;
        }

        $settle = (bool) $this->option('settle');
        $teacherNet = (int) round(self::HOURLY_RATE * (100 - self::COMMISSION_PERCENT) / 100);

        $summary = DB::transaction(function () use ($student, $teacher, $start, $end, $settle, $teacherNet) {
            $order = Order::query()
                ->where('user_id', $student->id)
                ->where('status', 'paid')
                ->whereNotNull('learning_package_id')
                ->orderByDesc('id')
                ->first();

            if (! $order) {
                $order = Order::create([
                    'user_id' => $student->id,
                    'order_id' => 'DEMO-REAN-RINA-'.$start->format('YmdHis'),
                    'amount' => 40000,
                    'subtotal_amount' => 40000,
                    'discount_amount' => 0,
                    'status' => 'paid',
                    'verified_at' => now('Asia/Jakarta'),
                    'class_details_snapshot' => [
                        'is_demo_seed' => true,
                        'subject' => self::SUBJECT_NAME,
                        'teacher_name' => $teacher->name,
                        'type' => 'Kelas Privat',
                        'method' => 'online',
                        'session_count' => 1,
                        'duration_hours' => 1,
                    ],
                ]);
            }

            $request = BookingRequest::create([
                'student_id' => $student->id,
                'matched_teacher_id' => $teacher->id,
                'subject_name' => self::SUBJECT_NAME,
                'education_level' => $student->student_education_level ?: 'SMP',
                'grade' => $student->grade ?: 'Kelas 8',
                'chapter' => self::CHAPTER,
                'learning_goal' => 'Latihan '.self::CHAPTER.'.',
                'learning_mode' => 'online',
                'class_type' => 'private',
                'scheduled_date' => $start->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
                'duration_hours' => 1,
                'status' => $settle ? 'completed' : 'confirmed',
                'matching_attempts' => 1,
                'hourly_rate' => self::HOURLY_RATE,
                'total_amount' => self::HOURLY_RATE,
            ]);

            $booking = Booking::create([
                'booking_request_id' => $request->id,
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'order_id' => $order->id,
                'start_at' => $start,
                'end_at' => $end,
                'duration_hours' => 1,
                'learning_mode' => 'online',
                'class_type' => 'private',
                'hourly_rate' => self::HOURLY_RATE,
                'total_amount' => self::HOURLY_RATE,
                'gross_amount' => self::HOURLY_RATE,
                'teacher_net_amount' => $teacherNet,
                'commission_percent' => self::COMMISSION_PERCENT,
                'status' => 'scheduled',
                'payout_status' => 'locked',
            ]);

            BookingParticipant::create([
                'booking_id' => $booking->id,
                'booking_request_id' => $request->id,
                'student_id' => $student->id,
                'order_id' => $order->id,
                'amount' => self::HOURLY_RATE,
                'status' => 'approved',
                'approved_at' => now('Asia/Jakarta'),
            ]);

            $request->update(['booking_id' => $booking->id]);

            return ['booking' => $booking, 'order' => $order];
        }, 3);

        $booking = $summary['booking'];

        if ($settle) {
            $booking->update([
                'status' => 'completed',
                'completed_at' => $end,
                'student_approved_at' => $end,
                'payout_status' => 'ready',
            ]);
            $booking = $booking->fresh();
        }

        $this->info('Sesi demo Rean + Rina dibuat.');
        $this->printSummary($booking, $summary['order']->order_id);
        if ($settle) {
            $this->line('Jurnal tutor: diselesaikan melalui payout_status ready (event_key booking:'.$booking->id.':settled).');
        }

        return self::SUCCESS;
    }

    private function printSummary(Booking $booking, ?string $orderCode): void
    {
        $this->line('Booking ID : '.$booking->id);
        $this->line('Jadwal     : '.$booking->start_at?->timezone('Asia/Jakarta')->format('d M Y H:i').'–'.$booking->end_at?->timezone('Asia/Jakarta')->format('H:i').' WIB');
        $this->line('Status     : '.$booking->status.' · payout '.$booking->payout_status);
        $this->line('Order      : '.$orderCode);
    }
}
