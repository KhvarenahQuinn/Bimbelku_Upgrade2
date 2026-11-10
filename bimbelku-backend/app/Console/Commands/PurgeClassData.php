<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\CheapClass;
use App\Models\CheapClassEnrollment;
use App\Models\CheapClassTemplate;
use App\Models\Classroom;
use App\Models\ClassroomMessage;
use App\Models\LearningPackage;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurgeClassData extends Command
{
    protected $signature = 'demo:purge-class-data
        {--force : Hapus tanpa konfirmasi}
        {--dry-run : Tampilkan jumlah data yang akan dihapus tanpa mengeksekusi}';

    protected $description = 'Menghapus seluruh data kelas milik Rean, Murid Demo, Rina Kartika, dan Budi Santoso';

    private const STUDENT_EMAILS = [
        'rean.magangjogja@gmail.com',
        'murid@bimbelku.com',
    ];

    private const TEACHER_EMAILS = [
        'rina.matematika@bimbelku.com',
        'budi@guru.com',
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Command purge hanya boleh dijalankan pada environment local/testing.');

            return self::FAILURE;
        }

        $students = User::query()->whereIn('email', self::STUDENT_EMAILS)->get(['id', 'name', 'email']);
        $teachers = User::query()->whereIn('email', self::TEACHER_EMAILS)->get(['id', 'name', 'email']);

        if ($students->count() !== count(self::STUDENT_EMAILS) || $teachers->count() !== count(self::TEACHER_EMAILS)) {
            $this->error('Akun target tidak lengkap. Ditemukan '.$students->count().' murid dan '.$teachers->count().' guru.');

            return self::FAILURE;
        }

        $studentIds = $students->pluck('id');
        $teacherIds = $teachers->pluck('id');

        $packageIds = LearningPackage::query()
            ->whereIn('student_id', $studentIds)
            ->orWhereHas('subjects', fn ($query) => $query->whereIn('assigned_teacher_id', $teacherIds))
            ->pluck('id');

        $subjectIds = PackageSubject::query()
            ->whereIn('learning_package_id', $packageIds)
            ->orWhereIn('assigned_teacher_id', $teacherIds)
            ->pluck('id');

        $sessionIds = PackageSession::query()
            ->whereIn('package_subject_id', $subjectIds)
            ->pluck('id');

        $bookingIds = Booking::query()
            ->whereIn('student_id', $studentIds)
            ->orWhereIn('teacher_id', $teacherIds)
            ->pluck('id');

        $requestIds = BookingRequest::query()
            ->whereIn('student_id', $studentIds)
            ->orWhereIn('matched_teacher_id', $teacherIds)
            ->pluck('id');

        $messageIds = ClassroomMessage::query()
            ->whereIn('booking_id', $bookingIds)
            ->pluck('id');

        $reportIds = DB::table('learning_progress_reports')
            ->whereIn('booking_id', $bookingIds)
            ->pluck('id');

        $scheduleRequestIds = DB::table('schedule_change_requests')
            ->whereIn('booking_id', $bookingIds)
            ->pluck('id');

        $cheapClassIds = CheapClass::query()
            ->whereIn('teacher_id', $teacherIds)
            ->pluck('id');

        $enrollmentIds = CheapClassEnrollment::query()
            ->whereIn('student_id', $studentIds)
            ->orWhereIn('cheap_class_id', $cheapClassIds)
            ->pluck('id');

        $templateIds = CheapClassTemplate::query()
            ->whereIn('teacher_id', $teacherIds)
            ->pluck('id');

        $classroomIds = Classroom::query()
            ->whereIn('user_id', $teacherIds)
            ->pluck('id');

        $counts = $this->counts(
            $studentIds,
            $teacherIds,
            $packageIds,
            $subjectIds,
            $sessionIds,
            $bookingIds,
            $requestIds,
            $messageIds,
            $reportIds,
            $scheduleRequestIds,
            $cheapClassIds,
            $enrollmentIds,
            $classroomIds
        );

        $this->line('Akun murid : '.$students->map(fn ($user) => "{$user->name} (#{$user->id})")->implode(', '));
        $this->line('Akun guru  : '.$teachers->map(fn ($user) => "{$user->name} (#{$user->id})")->implode(', '));
        $this->newLine();

        $this->table(['Tabel', 'Jumlah'], collect($counts)->map(fn ($total, $label) => [$label, $total])->values()->all());

        if ($this->option('dry-run')) {
            $this->info('Mode dry-run: tidak ada data yang dihapus.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Hapus permanen seluruh data kelas di atas?', false)) {
            $this->warn('Dibatalkan.');

            return self::SUCCESS;
        }

        $ordersBefore = DB::table('orders')->whereIn('user_id', $studentIds)->count();
        $refundsBefore = DB::table('refunds')->whereIn('user_id', $studentIds)->count();

        DB::transaction(function () use (
            $studentIds,
            $teacherIds,
            $packageIds,
            $subjectIds,
            $sessionIds,
            $bookingIds,
            $requestIds,
            $messageIds,
            $reportIds,
            $scheduleRequestIds,
            $cheapClassIds,
            $enrollmentIds,
            $templateIds,
            $classroomIds
        ) {
            // Jejak bab per sesi dan laporan progres.
            DB::table('package_session_chapter_logs')
                ->whereIn('package_session_id', $sessionIds)
                ->orWhereIn('learning_progress_report_id', $reportIds)
                ->delete();

            // Penggantian tutor memakai RESTRICT pada booking/sesi lama.
            DB::table('teacher_replacement_sessions')
                ->whereIn('package_session_id', $sessionIds)
                ->orWhereIn('old_booking_id', $bookingIds)
                ->orWhereIn('new_booking_id', $bookingIds)
                ->delete();

            DB::table('teacher_replacement_requests')
                ->whereIn('learning_package_id', $packageIds)
                ->orWhereIn('package_subject_id', $subjectIds)
                ->orWhereIn('student_id', $studentIds)
                ->delete();

            // Pengajuan perubahan jadwal.
            DB::table('schedule_change_responses')
                ->whereIn('schedule_change_request_id', $scheduleRequestIds)
                ->delete();

            DB::table('schedule_change_requests')
                ->whereIn('booking_id', $bookingIds)
                ->delete();

            // Kehadiran dan laporan sesi.
            DB::table('participant_attendances')->whereIn('booking_id', $bookingIds)->delete();
            DB::table('session_attendances')->whereIn('booking_id', $bookingIds)->delete();
            DB::table('session_reports')->whereIn('booking_id', $bookingIds)->delete();
            DB::table('learning_progress_reports')->whereIn('id', $reportIds)->delete();

            // Obrolan kelas.
            DB::table('classroom_message_reads')->whereIn('classroom_message_id', $messageIds)->delete();
            DB::table('classroom_conversation_reads')->whereIn('booking_id', $bookingIds)->delete();
            DB::table('classroom_messages')->whereIn('booking_id', $bookingIds)->delete();

            // Partisipan dan sengketa.
            DB::table('booking_disputes')->whereIn('booking_id', $bookingIds)->delete();
            DB::table('booking_participants')->whereIn('booking_id', $bookingIds)->delete();

            // Rating kelas.
            DB::table('ratings')
                ->whereIn('booking_id', $bookingIds)
                ->orWhereIn('student_id', $studentIds)
                ->orWhereIn('teacher_id', $teacherIds)
                ->delete();

            // Booking lebih dulu, baru permintaan booking.
            DB::table('bookings')->whereIn('id', $bookingIds)->delete();
            DB::table('booking_requests')->whereIn('id', $requestIds)->delete();

            // Isi paket belajar.
            DB::table('package_sessions')->whereIn('package_subject_id', $subjectIds)->delete();
            DB::table('package_chapters')->whereIn('package_subject_id', $subjectIds)->delete();
            DB::table('package_renewals')
                ->whereIn('old_package_id', $packageIds)
                ->orWhereIn('new_package_id', $packageIds)
                ->delete();
            DB::table('private_class_joins')->whereIn('learning_package_id', $packageIds)->delete();
            DB::table('learning_packages')->whereIn('id', $packageIds)->delete();

            // Kelas murah.
            DB::table('cheap_class_sessions')->whereIn('cheap_class_id', $cheapClassIds)->delete();
            DB::table('cheap_class_enrollments')->whereIn('id', $enrollmentIds)->delete();
            DB::table('cheap_classes')->whereIn('id', $cheapClassIds)->delete();
            DB::table('cheap_class_templates')->whereIn('id', $templateIds)->delete();

            // Kelas legacy berbasis classrooms.
            DB::table('orders')->whereIn('classroom_id', $classroomIds)->update(['classroom_id' => null]);
            DB::table('class_sessions')->whereIn('classroom_id', $classroomIds)->delete();
            DB::table('class_students')->whereIn('classroom_id', $classroomIds)->delete();
            DB::table('classrooms')->whereIn('id', $classroomIds)->delete();

            // State UI obrolan milik akun target.
            DB::table('classroom_conversation_states')
                ->whereIn('user_id', $studentIds->merge($teacherIds))
                ->delete();
        }, 3);

        $remaining = [
            'Paket belajar' => LearningPackage::query()->whereIn('id', $packageIds)->count(),
            'Mapel paket' => PackageSubject::query()->whereIn('id', $subjectIds)->count(),
            'Sesi paket' => PackageSession::query()->whereIn('id', $sessionIds)->count(),
            'Bab paket' => DB::table('package_chapters')->whereIn('package_subject_id', $subjectIds)->count(),
            'Booking' => Booking::query()->whereIn('id', $bookingIds)->count(),
            'Permintaan booking' => BookingRequest::query()->whereIn('id', $requestIds)->count(),
            'Pesan kelas' => DB::table('classroom_messages')->whereIn('booking_id', $bookingIds)->count(),
            'Kelas legacy' => Classroom::query()->whereIn('id', $classroomIds)->count(),
        ];

        $this->newLine();
        $this->table(['Tabel', 'Sisa'], collect($remaining)->map(fn ($total, $label) => [$label, $total])->values()->all());

        $ordersAfter = DB::table('orders')->whereIn('user_id', $studentIds)->count();
        $refundsAfter = DB::table('refunds')->whereIn('user_id', $studentIds)->count();

        $this->line("Order dipertahankan: {$ordersBefore} → {$ordersAfter}");
        $this->line("Refund dipertahankan: {$refundsBefore} → {$refundsAfter}");

        $leftover = array_sum($remaining);

        if ($leftover !== 0) {
            $this->error('Masih ada data kelas tersisa sebanyak '.$leftover.' baris.');

            return self::FAILURE;
        }

        if ($ordersAfter !== $ordersBefore || $refundsAfter !== $refundsBefore) {
            $this->error('Jumlah order/refund berubah. Periksa kembali data finansial.');

            return self::FAILURE;
        }

        $this->info('Purge data kelas selesai. Semua data kelas target sudah dihapus.');

        return self::SUCCESS;
    }

    private function counts(
        Collection $studentIds,
        Collection $teacherIds,
        Collection $packageIds,
        Collection $subjectIds,
        Collection $sessionIds,
        Collection $bookingIds,
        Collection $requestIds,
        Collection $messageIds,
        Collection $reportIds,
        Collection $scheduleRequestIds,
        Collection $cheapClassIds,
        Collection $enrollmentIds,
        Collection $classroomIds
    ): array {
        return [
            'Paket belajar' => $packageIds->count(),
            'Mapel paket' => $subjectIds->count(),
            'Sesi paket' => $sessionIds->count(),
            'Bab paket' => DB::table('package_chapters')->whereIn('package_subject_id', $subjectIds)->count(),
            'Log bab sesi' => DB::table('package_session_chapter_logs')
                ->whereIn('package_session_id', $sessionIds)
                ->orWhereIn('learning_progress_report_id', $reportIds)
                ->count(),
            'Booking' => $bookingIds->count(),
            'Permintaan booking' => $requestIds->count(),
            'Partisipan booking' => DB::table('booking_participants')->whereIn('booking_id', $bookingIds)->count(),
            'Pesan kelas' => $messageIds->count(),
            'Laporan progres' => $reportIds->count(),
            'Perubahan jadwal' => $scheduleRequestIds->count(),
            'Peserta didik kelas' => DB::table('class_students')->whereIn('classroom_id', $classroomIds)->count(),
            'Kelas legacy' => $classroomIds->count(),
            'Kelas murah' => $cheapClassIds->count(),
            'Pendaftaran kelas murah' => $enrollmentIds->count(),
            'Order (dipertahankan)' => DB::table('orders')->whereIn('user_id', $studentIds)->count(),
            'Refund (dipertahankan)' => DB::table('refunds')->whereIn('user_id', $studentIds)->count(),
        ];
    }
}
