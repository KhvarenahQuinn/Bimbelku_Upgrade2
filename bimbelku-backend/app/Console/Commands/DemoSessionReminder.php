<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\LearningPackage;
use App\Models\Order;
use App\Models\PackageChapter;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoSessionReminder extends Command
{
    protected $signature = 'demo:session-reminder
        {stage=setup : setup|checkout-ready|status|reset}';

    protected $description = 'Menyiapkan Paket Belajar lokal lengkap untuk menguji Session Action Reminder tanpa menunggu aturan booking 24 jam';

    private const STUDENT_EMAIL = 'demo.student@bimbelku.local';

    private const TEACHER_EMAIL = 'demo.tutor@bimbelku.local';

    private const PASSWORD = 'password';

    private const PACKAGE_PREFIX = 'DEMO-SESSION-';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Command demo hanya boleh dijalankan pada environment local/testing.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('stage')) {
            'setup' => $this->setup(),
            'checkout-ready' => $this->checkoutReady(),
            'status' => $this->status(),
            'reset' => $this->resetDemo(),
            default => $this->invalidStage(),
        };
    }

    private function setup(): int
    {
        [$student, $teacher] = $this->ensureDemoUsers();
        $booking = DB::transaction(function () use ($student, $teacher) {
            $this->archivePreviousDemo($student->id, $teacher->id);

            $plan = PackagePlan::query()->updateOrCreate(
                ['slug' => 'demo-session-reminder'],
                [
                    'name' => 'Coba Belajar (Demo Lokal)',
                    'description' => 'Fixture lokal tersembunyi untuk menguji alur sesi BimbelKu.',
                    'session_count' => 1,
                    'validity_days' => 7,
                    'maximum_subjects' => 1,
                    'sort_order' => 9999,
                    'is_active' => false,
                ]
            );

            $catalogSubject = CurriculumSubject::query()->firstOrCreate(
                ['normalized_name' => 'demo-matematika-session'],
                [
                    'name' => 'Matematika Demo Session',
                    'group_name' => 'Demo',
                    'education_levels' => ['SMP'],
                    'grades' => ['Kelas 7'],
                    'is_elective' => false,
                    'is_active' => false,
                    'curriculum_name' => 'Kurikulum Merdeka',
                ]
            );

            $chapter = CurriculumChapter::query()->firstOrCreate(
                [
                    'curriculum_subject_id' => $catalogSubject->id,
                    'grade' => 'Kelas 7',
                    'normalized_title' => 'persamaan linear',
                ],
                [
                    'education_level' => 'SMP',
                    'title' => 'Persamaan Linear',
                    'sort_order' => 1,
                    'is_active' => true,
                    'source_reference' => 'demo-local',
                ]
            );

            // setup menaruh sesi pada jendela check-in sekarang. Rule pemesanan 24 jam
            // tidak disentuh karena command hanya membentuk fixture lokal setelah order dianggap paid.
            $start = now()->subMinutes(2)->startOfMinute();
            $end = $start->copy()->addHour();
            $amount = 50000;

            $package = LearningPackage::create([
                'student_id' => $student->id,
                'package_plan_id' => $plan->id,
                'package_code' => self::PACKAGE_PREFIX.now()->format('YmdHis').'-'.$student->id,
                'education_level' => 'SMP',
                'grade' => 'Kelas 7',
                'learning_mode' => 'online',
                'duration_hours' => 1,
                'status' => 'active',
                'total_sessions' => 1,
                'used_sessions' => 0,
                'subtotal_amount' => $amount,
                'discount_amount' => 0,
                'total_amount' => $amount,
                'starts_at' => $start,
                'expires_at' => $start->copy()->addDays(7),
            ]);

            $packageSubject = PackageSubject::create([
                'learning_package_id' => $package->id,
                'curriculum_subject_id' => $catalogSubject->id,
                'curriculum_chapter_id' => $chapter->id,
                'curriculum_chapter_ids' => [$chapter->id],
                'assigned_teacher_id' => $teacher->id,
                'subject_name' => 'Matematika',
                'chapter' => 'Persamaan Linear',
                'learning_goal' => 'Memahami persamaan linear dan menyelesaikan soal secara mandiri.',
                'allocated_sessions' => 1,
                'unit_price' => $amount,
                'subtotal_amount' => $amount,
                'status' => 'active',
            ]);

            PackageChapter::create([
                'package_subject_id' => $packageSubject->id,
                'curriculum_chapter_id' => $chapter->id,
                'title' => $chapter->title,
                'status' => 'not_started',
                'needs_review' => false,
                'sort_order' => 1,
            ]);

            $bookingRequest = BookingRequest::create([
                'student_id' => $student->id,
                'matched_teacher_id' => $teacher->id,
                'package_subject_id' => $packageSubject->id,
                'curriculum_subject_id' => $catalogSubject->id,
                'subject_name' => 'Matematika',
                'education_level' => 'SMP',
                'grade' => 'Kelas 7',
                'chapter' => 'Persamaan Linear',
                'learning_goal' => 'Memahami persamaan linear dan menyelesaikan soal secara mandiri.',
                'learning_mode' => 'online',
                'class_type' => 'private',
                'scheduled_date' => $start->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
                'duration_hours' => 1,
                'status' => 'confirmed',
                'hourly_rate' => $amount,
                'total_amount' => $amount,
            ]);

            $order = Order::create([
                'user_id' => $student->id,
                'learning_package_id' => $package->id,
                'order_id' => 'DEMO-PKG-'.now()->format('YmdHis').'-'.$package->id,
                'subtotal_amount' => $amount,
                'discount_amount' => 0,
                'amount' => $amount,
                'status' => 'paid',
                'verified_at' => now(),
                'class_details_snapshot' => [
                    'flow_version' => 6,
                    'learning_package_id' => $package->id,
                    'package_code' => $package->package_code,
                    'package_name' => $plan->name,
                    'subject' => 'Matematika',
                    'teacher_name' => $teacher->name,
                    'type' => 'Paket Privat',
                    'method' => 'online',
                    'session_count' => 1,
                    'duration_hours' => 1,
                    'total_learning_hours' => 1,
                ],
            ]);

            $booking = Booking::create([
                'booking_request_id' => $bookingRequest->id,
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'order_id' => $order->id,
                'start_at' => $start,
                'end_at' => $end,
                'duration_hours' => 1,
                'learning_mode' => 'online',
                'class_type' => 'private',
                'hourly_rate' => $amount,
                'total_amount' => $amount,
                'status' => 'confirmed',
                'session_flow_version' => 'presence_confirmation_v2',
                // Fixture demo menyediakan URL agar alur Zoom dapat diuji end-to-end.
                'meeting_link' => 'https://zoom.us/j/12345678901',
                'commission_percent' => 20,
                'gross_amount' => $amount,
                'teacher_net_amount' => $amount * 0.8,
                'payout_status' => 'locked',
            ]);

            BookingParticipant::create([
                'booking_id' => $booking->id,
                'booking_request_id' => $bookingRequest->id,
                'student_id' => $student->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'status' => 'paid',
                'approved_at' => null,
            ]);

            $session = PackageSession::create([
                'package_subject_id' => $packageSubject->id,
                'booking_id' => $booking->id,
                'sequence' => 1,
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
                'status' => 'scheduled',
            ]);

            $bookingRequest->update(['booking_id' => $booking->id]);
            $order->update(['booking_id' => $booking->id]);

            return $booking->fresh(['packageSession.subject.package', 'participants.order']);
        });

        $this->newLine();
        $this->info('Demo Paket Belajar + Session Action Reminder siap.');
        $this->line('Murid : '.self::STUDENT_EMAIL.' / '.self::PASSWORD);
        $this->line('Tutor : '.self::TEACHER_EMAIL.' / '.self::PASSWORD);
        $this->line('Paket : '.$booking->packageSession?->subject?->package?->package_code);
        $this->line('Booking ID : '.$booking->id);
        $this->line('Jadwal : '.$booking->start_at->format('d-m-Y H:i').' - '.$booking->end_at->format('H:i'));
        $this->newLine();
        $this->comment('Session Flow V2 aktif.');
        $this->comment('1) Tutor: buka Ruang Belajar -> Saya Siap Mengajar.');
        $this->comment('2) Murid: buka Kelas Saya -> Saya Sudah Hadir.');
        $this->comment('3) Belajar seperti biasa. Jalankan checkout-ready saat ingin mempercepat akhir sesi.');
        $this->comment('4) Tutor: Akhiri Sesi -> Isi Hasil Belajar per Bab.');
        $this->comment('5) Murid: pilih Sesi Sesuai atau Ada masalah.');
        $this->comment('Rule pemesanan 24 jam production tidak diubah.');

        return self::SUCCESS;
    }

    private function checkoutReady(): int
    {
        $booking = $this->latestDemoBooking();
        if (! $booking) {
            $this->error('Belum ada booking demo. Jalankan: php artisan demo:session-reminder setup');

            return self::FAILURE;
        }

        if (! in_array($booking->status, ['confirmed', 'in_progress'], true)) {
            $this->error('Booking demo tidak lagi berada pada sesi aktif. Jalankan setup untuk skenario baru.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($booking) {
            $locked = Booking::query()->with('packageSession')->lockForUpdate()->findOrFail($booking->id);
            $end = now()->subMinute()->startOfMinute();
            $start = $end->copy()->subHour();
            $locked->update(['start_at' => $start, 'end_at' => $end]);
            $locked->bookingRequest?->update([
                'scheduled_date' => $start->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
            ]);
            $locked->packageSession?->update([
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
            ]);
        });

        $booking = $booking->fresh('packageSession');
        $this->info('Sesi demo sekarang berada pada jendela Akhiri Sesi/check-out.');
        $this->line('Booking ID : '.$booking->id);
        $this->line('Jadwal demo : '.$booking->start_at->format('H:i').' - '.$booking->end_at->format('H:i'));
        $this->comment('Paket tetap aktif; yang dipercepat hanya waktu sesi demo lokal. Refresh browser tutor.');

        return self::SUCCESS;
    }

    private function status(): int
    {
        $booking = $this->latestDemoBooking();
        if (! $booking) {
            $this->warn('Belum ada booking demo terbaru.');

            return self::SUCCESS;
        }

        $booking->load([
            'sessionAttendances', 'participantAttendances', 'learningProgressReports',
            'packageSession.subject.package', 'packageSession.subject.chapters', 'participants.order',
        ]);
        $teacherAttendance = $booking->sessionAttendances->firstWhere('user_id', $booking->teacher_id);
        $package = $booking->packageSession?->subject?->package;
        $chapters = $booking->packageSession?->subject?->chapters ?? collect();

        $this->table(['Data', 'Nilai'], [
            ['Paket Belajar', $package?->package_code ?: '-'],
            ['Status paket', $package?->status ?: '-'],
            ['Booking ID', $booking->id],
            ['Status sesi', $booking->status],
            ['Flow sesi', 'presence_confirmation_v2'],
            ['Tutor siap', $booking->tutor_ready_at ? 'ya' : 'belum'],
            ['Murid konfirmasi hadir', $booking->student_confirmed_at ? 'ya' : 'belum'],
            ['Mulai', optional($booking->start_at)->format('d-m-Y H:i')],
            ['Selesai', optional($booking->end_at)->format('d-m-Y H:i')],
            ['Order paid', $booking->participants->first()?->order?->status === 'paid' ? 'ya' : 'tidak'],
            ['Bab paket', $chapters->count()],
            ['Tutor check-in', $teacherAttendance?->check_in_at ? 'ya' : 'belum'],
            ['Kehadiran murid', $booking->participantAttendances->count() ? 'tersimpan' : 'belum'],
            ['Tutor check-out', $teacherAttendance?->check_out_at ? 'ya' : 'belum'],
            ['Laporan progress', $booking->learningProgressReports->count()],
            ['Menunggu persetujuan murid', $booking->status === 'awaiting_student_approval' ? 'ya' : 'tidak'],
        ]);

        return self::SUCCESS;
    }

    private function resetDemo(): int
    {
        $studentId = User::query()->where('email', self::STUDENT_EMAIL)->value('id');
        $teacherId = User::query()->where('email', self::TEACHER_EMAIL)->value('id');
        if (! $studentId || ! $teacherId) {
            $this->info('Belum ada akun demo. Tidak ada yang perlu direset.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->archivePreviousDemo((int) $studentId, (int) $teacherId));
        $this->info('Skenario demo aktif dinonaktifkan. Akun demo tetap dipertahankan.');

        return self::SUCCESS;
    }

    private function archivePreviousDemo(int $studentId, int $teacherId): void
    {
        Booking::query()
            ->where('student_id', $studentId)
            ->where('teacher_id', $teacherId)
            ->whereHas('packageSession.subject.package', fn ($query) => $query->where('package_code', 'like', self::PACKAGE_PREFIX.'%'))
            ->whereNotIn('status', ['completed', 'refunded', 'cancelled'])
            ->get()
            ->each(function (Booking $booking) {
                $booking->update([
                    'status' => 'completed',
                    'objection_deadline' => null,
                    'completed_at' => now(),
                    'payout_status' => 'cancelled',
                ]);
            });

        LearningPackage::query()
            ->where('student_id', $studentId)
            ->where('package_code', 'like', self::PACKAGE_PREFIX.'%')
            ->whereNotIn('status', ['completed', 'cancelled', 'refunded'])
            ->update(['status' => 'completed', 'completed_at' => now()]);
    }

    private function ensureDemoUsers(): array
    {
        $catalogSubject = CurriculumSubject::query()->updateOrCreate(
            ['normalized_name' => 'demo-matematika-session'],
            [
                'name' => 'Matematika Demo Session',
                'group_name' => 'Demo',
                'education_levels' => ['SMP'],
                'grades' => ['Kelas 7'],
                'is_elective' => false,
                'is_active' => true,
                'curriculum_name' => 'Kurikulum Merdeka',
            ]
        );

        $student = User::query()->updateOrCreate(
            ['email' => self::STUDENT_EMAIL],
            [
                'name' => 'Demo Murid',
                'password' => self::PASSWORD,
                'role' => 'student',
                'status' => 'active',
                'email_verified_at' => now(),
                'student_education_level' => 'SMP',
                'grade' => 'Kelas 7',
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
                'policy_version' => 'demo-local',
            ]
        );

        $teacher = User::query()->updateOrCreate(
            ['email' => self::TEACHER_EMAIL],
            [
                'name' => 'Demo Tutor',
                'password' => self::PASSWORD,
                'role' => 'teacher',
                'status' => 'active',
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
                'policy_version' => 'demo-local',
            ]
        );

        TeacherProfile::query()->updateOrCreate(
            ['user_id' => $teacher->id],
            [
                'expertise' => 'Matematika',
                'teaching_method' => 'Online',
                'bio' => 'Tutor demo lokal untuk pengujian alur sesi BimbelKu.',
                'verified_at' => now(),
                'is_accepting_requests' => true,
            ]
        );

        $profile = TeacherProfile::query()->where('user_id', $teacher->id)->firstOrFail();
        TeacherSubject::query()->where('teacher_profile_id', $profile->id)->delete();
        TeacherSubject::query()->create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $catalogSubject->id,
            'name' => 'Matematika',
            'levels' => ['SMP'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);

        return [$student->fresh(), $teacher->fresh('teacherProfile')];
    }

    private function latestDemoBooking(): ?Booking
    {
        $studentId = User::query()->where('email', self::STUDENT_EMAIL)->value('id');
        $teacherId = User::query()->where('email', self::TEACHER_EMAIL)->value('id');
        if (! $studentId || ! $teacherId) {
            return null;
        }

        return Booking::query()
            ->where('student_id', $studentId)
            ->where('teacher_id', $teacherId)
            ->whereHas('packageSession.subject.package', fn ($query) => $query->where('package_code', 'like', self::PACKAGE_PREFIX.'%'))
            ->latest('id')
            ->first();
    }

    private function invalidStage(): int
    {
        $this->error('Stage tidak dikenal. Gunakan: setup, checkout-ready, status, atau reset.');

        return self::INVALID;
    }
}
