<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\LearningPackage;
use App\Models\LearningTimeSlot;
use App\Models\Order;
use App\Models\PackageChapter;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\Refund;
use App\Models\TeacherAvailability;
use App\Models\TeacherProfile;
use App\Models\TeacherReplacementRequest;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\CustomerWalletService;
use App\Services\PartialPackageRefundService;
use App\Services\TeacherMatchingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoTeacherReplacement extends Command
{
    protected $signature = 'demo:teacher-replacement
        {stage=setup : setup|status|candidate-off|candidate-on|no-teacher|refund-ready|complete-refund|reset}';

    protected $description = 'Demo penggantian guru dari paket aktif, matching berulang, sampai refund sesi tersisa';

    private const STUDENT_EMAIL = 'demo.replacement.student@bimbelku.local';

    private const OLD_TEACHER_EMAIL = 'demo.replacement.old@bimbelku.local';

    private const NEW_TEACHER_EMAIL = 'demo.replacement.new@bimbelku.local';

    private const ADMIN_EMAIL = 'demo.replacement.admin@bimbelku.local';

    private const PASSWORD = 'password';

    private const PACKAGE_PREFIX = 'DEMO-GGR-';

    private const SUBJECT_NAME = 'Matematika Demo Ganti Guru';

    public function handle(
        TeacherMatchingService $matching,
        PartialPackageRefundService $partialRefunds,
        CustomerWalletService $wallets,
    ): int {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Command demo hanya boleh dijalankan pada environment local/testing.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('stage')) {
            'setup' => $this->setup(),
            'status' => $this->status(),
            'candidate-off' => $this->candidateAvailability(false),
            'candidate-on' => $this->candidateAvailability(true),
            'no-teacher' => $this->forceNoTeacher($matching),
            'refund-ready' => $this->refundReady($partialRefunds),
            'complete-refund' => $this->completeRefund($partialRefunds, $wallets),
            'reset' => $this->resetDemo(),
            default => $this->invalidStage(),
        };
    }

    private function setup(): int
    {
        [$student, $oldTeacher, $newTeacher, $admin] = $this->ensureUsersAndCatalog();

        $package = DB::transaction(function () use ($student, $oldTeacher) {
            $this->archivePreviousFixtures($student->id);
            $catalog = CurriculumSubject::query()->where('normalized_name', 'matematika-demo-ganti-guru')->firstOrFail();
            $chapter = CurriculumChapter::query()->where('curriculum_subject_id', $catalog->id)->firstOrFail();
            $plan = PackagePlan::query()->firstOrCreate(
                ['slug' => 'demo-teacher-replacement-three-session'],
                [
                    'name' => 'Demo Ganti Guru 3 Sesi',
                    'description' => 'Plan lokal khusus demo penggantian guru.',
                    'session_count' => 3,
                    'validity_days' => 30,
                    'maximum_subjects' => 1,
                    'sort_order' => 9997,
                    'is_active' => false,
                ],
            );
            $package = LearningPackage::create([
                'student_id' => $student->id,
                'package_plan_id' => $plan->id,
                'package_code' => self::PACKAGE_PREFIX.now()->format('YmdHis').'-'.$student->id,
                'education_level' => 'SMP',
                'grade' => 'Kelas 7',
                'learning_mode' => 'online',
                'duration_hours' => 1,
                'status' => 'active',
                'total_sessions' => 3,
                'used_sessions' => 1,
                'subtotal_amount' => 150000,
                'discount_amount' => 0,
                'total_amount' => 150000,
                'starts_at' => now()->subWeek(),
                'expires_at' => now()->addDays(23),
            ]);
            $subject = PackageSubject::create([
                'learning_package_id' => $package->id,
                'curriculum_subject_id' => $catalog->id,
                'curriculum_chapter_id' => $chapter->id,
                'curriculum_chapter_ids' => [$chapter->id],
                'assigned_teacher_id' => $oldTeacher->id,
                'subject_name' => self::SUBJECT_NAME,
                'chapter' => $chapter->title,
                'learning_goal' => 'Melanjutkan Aljabar bersama guru yang paling sesuai.',
                'allocated_sessions' => 3,
                'unit_price' => 50000,
                'subtotal_amount' => 150000,
                'status' => 'active',
            ]);
            PackageChapter::create([
                'package_subject_id' => $subject->id,
                'curriculum_chapter_id' => $chapter->id,
                'title' => $chapter->title,
                'status' => 'in_progress',
                'needs_review' => false,
                'sort_order' => 1,
                'started_at' => now()->subDays(5),
            ]);
            $order = Order::create([
                'user_id' => $student->id,
                'learning_package_id' => $package->id,
                'order_id' => 'ORD-'.self::PACKAGE_PREFIX.now()->format('YmdHis').'-'.$student->id,
                'subtotal_amount' => 150000,
                'discount_amount' => 0,
                'amount' => 150000,
                'status' => 'paid',
                'external_received_amount' => 150000,
                'verified_at' => now()->subWeek(),
                'class_details_snapshot' => [
                    'kind' => 'teacher_replacement_demo',
                    'package_code' => $package->package_code,
                    'subject' => self::SUBJECT_NAME,
                    'teacher_name' => $oldTeacher->name,
                    'session_count' => 3,
                ],
            ]);

            foreach ([now()->subDays(3), now()->addDays(2), now()->addDays(4)] as $index => $date) {
                $start = $date->copy()->setTime(18, 0, 0);
                $this->createSession($package, $subject, $order, $student, $oldTeacher, $index + 1, $start, $index === 0);
            }

            return $package->fresh(['subjects.sessions.booking', 'orders']);
        }, 3);

        $this->newLine();
        $this->info('Demo ganti guru siap: 1 sesi selesai dan 2 sesi mendatang bersama guru lama.');
        $this->line('Murid       : '.self::STUDENT_EMAIL.' / '.self::PASSWORD);
        $this->line('Guru lama   : '.self::OLD_TEACHER_EMAIL.' / '.self::PASSWORD);
        $this->line('Guru baru   : '.self::NEW_TEACHER_EMAIL.' / '.self::PASSWORD);
        $this->line('Admin       : '.$admin->email.($admin->email === self::ADMIN_EMAIL ? ' / '.self::PASSWORD : ' / gunakan password admin utama'));
        $this->line('Paket       : '.$package->package_code);
        $this->newLine();
        $this->comment('Pastikan FEATURE_TEACHER_REPLACEMENT=true lalu jalankan php artisan config:clear.');
        $this->comment('ALUR GURU DITEMUKAN: Murid ajukan -> Admin approve -> login Guru baru -> terima offer.');
        $this->comment('ALUR TIDAK DITEMUKAN: setelah Admin approve jalankan no-teacher -> Murid dapat Cari lagi/Ubah jadwal/Refund.');
        $this->comment('candidate-off membuat setiap percobaan Cari lagi tetap gagal; candidate-on membuat percobaan berikutnya menemukan guru.');

        return self::SUCCESS;
    }

    private function createSession(
        LearningPackage $package,
        PackageSubject $subject,
        Order $order,
        User $student,
        User $teacher,
        int $sequence,
        Carbon $start,
        bool $completed,
    ): void {
        $status = $completed ? 'completed' : 'confirmed';
        $request = BookingRequest::create([
            'student_id' => $student->id,
            'package_subject_id' => $subject->id,
            'matched_teacher_id' => $teacher->id,
            'curriculum_subject_id' => $subject->curriculum_subject_id,
            'subject_name' => self::SUBJECT_NAME,
            'education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'chapter' => $subject->chapter,
            'learning_goal' => $subject->learning_goal,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'scheduled_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addHour()->format('H:i:s'),
            'duration_hours' => 1,
            'status' => $status,
            'hourly_rate' => 50000,
            'total_amount' => 50000,
        ]);
        $booking = Booking::create([
            'booking_request_id' => $request->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'order_id' => $order->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'duration_hours' => 1,
            'learning_mode' => 'online',
            'class_type' => 'private',
            'hourly_rate' => 50000,
            'total_amount' => 50000,
            'gross_amount' => 50000,
            'teacher_net_amount' => 40000,
            'commission_percent' => 20,
            'status' => $status,
            'session_flow_version' => 'presence_confirmation_v2',
            'completed_at' => $completed ? $start->copy()->addHour() : null,
            'student_approved_at' => $completed ? $start->copy()->addHour() : null,
            'payout_status' => $completed ? 'ready' : 'locked',
        ]);
        $request->update(['booking_id' => $booking->id]);
        BookingParticipant::create([
            'booking_id' => $booking->id,
            'booking_request_id' => $request->id,
            'student_id' => $student->id,
            'order_id' => $order->id,
            'amount' => 50000,
            'status' => $completed ? 'approved' : 'paid',
            'approved_at' => $completed ? $start->copy()->addHour() : null,
        ]);
        PackageSession::create([
            'package_subject_id' => $subject->id,
            'booking_id' => $booking->id,
            'sequence' => $sequence,
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addHour(),
            'status' => $completed ? 'completed' : 'scheduled',
        ]);
        if ($completed) {
            $order->update(['booking_id' => $booking->id]);
        }
    }

    private function candidateAvailability(bool $available): int
    {
        $teacher = User::query()->where('email', self::NEW_TEACHER_EMAIL)->first();
        if (! $teacher?->teacherProfile) {
            $this->error('Demo belum tersedia. Jalankan setup.');

            return self::FAILURE;
        }
        $teacher->update(['status' => $available ? 'active' : 'inactive']);
        $teacher->teacherProfile->update(['is_accepting_requests' => $available]);
        $this->info($available
            ? 'Kandidat guru baru DIAKTIFKAN. Klik Cari lagi agar offer baru dibuat.'
            : 'Kandidat guru baru DINONAKTIFKAN. Pencarian berikutnya tidak akan menemukan guru.');

        return self::SUCCESS;
    }

    private function forceNoTeacher(TeacherMatchingService $matching): int
    {
        $replacement = $this->latestReplacement();
        if (! $replacement) {
            $this->error('Murid belum mengajukan ganti guru lewat UI.');

            return self::FAILURE;
        }
        $this->candidateAvailability(false);
        if ($replacement->status === 'pending_review') {
            $this->warn('Pengajuan masih pending_review. Approve lewat Admin, lalu jalankan no-teacher sekali lagi.');

            return self::SUCCESS;
        }
        if ($replacement->status === 'no_teacher') {
            $this->info('Status memang sudah no_teacher.');

            return $this->status();
        }
        $request = $replacement->matchingRequest;
        if (! $request || ! in_array($replacement->status, ['matching', 'teacher_pending', 'approved'], true)) {
            $this->error('Status '.$replacement->status.' tidak dapat diarahkan ke no_teacher.');

            return self::FAILURE;
        }
        $request->update(['search_expires_at' => now()->subSecond(), 'next_matching_at' => null]);
        $request->offers()->where('status', 'pending')->update(['expires_at' => now()->subSecond()]);
        $matching->dispatchNextOffer($request->fresh());
        $this->info('Batas pencarian demo dipercepat melalui matching production. Status sekarang: '.$replacement->fresh()->status.'.');

        return $this->status();
    }

    private function refundReady(PartialPackageRefundService $partialRefunds): int
    {
        $replacement = $this->latestReplacement();
        if (! $replacement || $replacement->status !== 'no_teacher') {
            $this->error('Replacement harus no_teacher. Jalankan no-teacher atau ulangi pencarian tanpa kandidat.');

            return self::FAILURE;
        }
        $refund = $partialRefunds->queueTeacherReplacement($replacement);
        $this->info('Refund parsial dibuat melalui service production: '.$refund->amount.' (ID '.$refund->id.').');
        $this->comment('Murid dapat memilih tujuan di Riwayat Transaksi, lalu Admin menyelesaikannya di Refund & Saldo.');
        $this->comment('Shortcut lokal sampai selesai: php artisan demo:teacher-replacement complete-refund');

        return self::SUCCESS;
    }

    private function completeRefund(PartialPackageRefundService $partialRefunds, CustomerWalletService $wallets): int
    {
        $replacement = $this->latestReplacement();
        $refund = $replacement ? Refund::query()->where('source_type', 'teacher_replacement')->where('source_id', $replacement->id)->latest('id')->first() : null;
        if (! $refund) {
            $this->error('Refund belum dibuat. Jalankan refund-ready atau ajukan dari UI murid.');

            return self::FAILURE;
        }
        if ($refund->status === 'paid') {
            $this->info('Refund demo memang sudah selesai.');

            return $this->status();
        }
        if ($refund->status !== 'pending') {
            $this->error('Refund berstatus '.$refund->status.' dan tidak dapat diselesaikan.');

            return self::FAILURE;
        }
        $admin = $this->demoAdmin();
        DB::transaction(function () use ($refund, $admin, $wallets, $partialRefunds) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            $locked->forceFill([
                'destination_method' => 'bimbelku_balance',
                'destination_selected_at' => now(),
                'destination_selection_version' => max(1, (int) $locked->destination_selection_version + 1),
            ])->save();
            $wallets->creditRefund($locked, $admin->id);
            $locked->update([
                'status' => 'paid',
                'processed_by' => $admin->id,
                'processed_at' => now(),
                'notes' => 'Shortcut demo lokal melalui service refund production.',
            ]);
            $partialRefunds->completeTeacherReplacementRefund($locked->fresh());
        }, 3);
        $this->info('Refund sesi tersisa selesai ke Saldo BimbelKu. Order asli tetap paid.');

        return $this->status();
    }

    private function status(): int
    {
        $package = $this->latestPackage();
        if (! $package) {
            $this->warn('Belum ada demo ganti guru. Jalankan setup.');

            return self::SUCCESS;
        }
        $package->load(['subjects.sessions.booking.teacher', 'subjects.latestTeacherReplacement.matchingRequest.offers', 'orders']);
        $subject = $package->subjects->first();
        $replacement = $subject?->latestTeacherReplacement;
        $refund = $replacement ? Refund::query()->where('source_type', 'teacher_replacement')->where('source_id', $replacement->id)->latest('id')->first() : null;
        $this->table(['Data', 'Nilai'], [
            ['Paket', $package->package_code],
            ['Status paket', $package->status],
            ['Progress sesi', $package->used_sessions.'/'.$package->total_sessions],
            ['Guru aktif mapel', $subject?->assignedTeacher?->name ?? 'Guru Lama Demo'],
            ['Replacement', $replacement?->replacement_code ?? 'belum diajukan'],
            ['Status replacement', $replacement?->status ?? '-'],
            ['Status matching', $replacement?->matchingRequest?->status ?? '-'],
            ['Offer pending', $replacement?->matchingRequest?->offers?->where('status', 'pending')->count() ?? 0],
            ['Kandidat baru aktif', $this->newTeacherAvailable() ? 'ya' : 'tidak'],
            ['Refund', $refund ? $refund->status.' · Rp '.number_format((float) $refund->amount, 0, ',', '.') : '-'],
            ['Order asli', $package->orders->first()?->status ?? '-'],
        ]);

        return self::SUCCESS;
    }

    private function ensureUsersAndCatalog(): array
    {
        $catalog = CurriculumSubject::query()->updateOrCreate(
            ['normalized_name' => 'matematika-demo-ganti-guru'],
            [
                'name' => self::SUBJECT_NAME,
                'group_name' => 'Demo',
                'education_levels' => ['SMP'],
                'grades' => ['Kelas 7'],
                'is_elective' => false,
                'is_active' => true,
                'curriculum_name' => 'Kurikulum Merdeka',
            ],
        );
        CurriculumChapter::query()->updateOrCreate(
            ['curriculum_subject_id' => $catalog->id, 'grade' => 'Kelas 7', 'normalized_title' => 'aljabar demo ganti guru'],
            ['education_level' => 'SMP', 'title' => 'Aljabar Demo Ganti Guru', 'sort_order' => 1, 'is_active' => true, 'source_reference' => 'demo-teacher-replacement'],
        );
        $student = User::query()->updateOrCreate(['email' => self::STUDENT_EMAIL], [
            'name' => 'Demo Murid Ganti Guru', 'password' => self::PASSWORD, 'role' => 'student', 'status' => 'active',
            'email_verified_at' => now(), 'student_education_level' => 'SMP', 'grade' => 'Kelas 7',
            'terms_accepted_at' => now(), 'privacy_accepted_at' => now(), 'policy_version' => 'demo-local',
        ]);
        $oldTeacher = $this->ensureTeacher(self::OLD_TEACHER_EMAIL, 'Guru Lama Demo', $catalog, false);
        $newTeacher = $this->ensureTeacher(self::NEW_TEACHER_EMAIL, 'Guru Pengganti Demo', $catalog, true);
        foreach (range(8, 22) as $hour) {
            LearningTimeSlot::query()->updateOrCreate(
                ['start_time' => sprintf('%02d:00:00', $hour)],
                ['label' => sprintf('%02d.00 WIB', $hour), 'sort_order' => $hour, 'is_active' => true],
            );
        }

        return [$student, $oldTeacher, $newTeacher, $this->demoAdmin()];
    }

    private function ensureTeacher(string $email, string $name, CurriculumSubject $catalog, bool $candidate): User
    {
        $teacher = User::query()->updateOrCreate(['email' => $email], [
            'name' => $name, 'password' => self::PASSWORD, 'role' => 'teacher', 'status' => 'active',
            'email_verified_at' => now(), 'terms_accepted_at' => now(), 'privacy_accepted_at' => now(), 'policy_version' => 'demo-local',
        ]);
        $profile = TeacherProfile::query()->updateOrCreate(['user_id' => $teacher->id], [
            'expertise' => self::SUBJECT_NAME, 'teaching_method' => 'Online', 'bio' => 'Tutor lokal demo penggantian guru.',
            'verified_at' => now(), 'is_accepting_requests' => true,
        ]);
        TeacherSubject::query()->updateOrCreate(
            ['teacher_profile_id' => $profile->id, 'curriculum_subject_id' => $catalog->id],
            ['name' => self::SUBJECT_NAME, 'levels' => ['SMP'], 'is_active' => true, 'is_online' => true, 'is_offline' => false, 'is_private_active' => true],
        );
        foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'] as $day) {
            TeacherAvailability::query()->updateOrCreate(
                ['user_id' => $teacher->id, 'day' => $day],
                ['start_time' => '08:00:00', 'end_time' => '23:00:00', 'slots' => [['start_time' => '08:00', 'end_time' => '23:00']], 'is_active' => true],
            );
        }
        if (! $candidate) {
            $profile->update(['is_accepting_requests' => true]);
        }

        return $teacher->fresh('teacherProfile');
    }

    private function demoAdmin(): User
    {
        return User::query()->where('role', 'admin')->where('status', 'active')->oldest('id')->first()
            ?? User::query()->create([
                'name' => 'Demo Admin Ganti Guru', 'email' => self::ADMIN_EMAIL, 'password' => self::PASSWORD,
                'role' => 'admin', 'status' => 'active', 'email_verified_at' => now(),
                'terms_accepted_at' => now(), 'privacy_accepted_at' => now(), 'policy_version' => 'demo-local',
            ]);
    }

    private function latestPackage(): ?LearningPackage
    {
        return LearningPackage::query()->where('package_code', 'like', self::PACKAGE_PREFIX.'%')->latest('id')->first();
    }

    private function latestReplacement(): ?TeacherReplacementRequest
    {
        $package = $this->latestPackage();

        return $package ? TeacherReplacementRequest::query()->where('learning_package_id', $package->id)->with('matchingRequest.offers')->latest('id')->first() : null;
    }

    private function newTeacherAvailable(): bool
    {
        $teacher = User::query()->where('email', self::NEW_TEACHER_EMAIL)->with('teacherProfile')->first();

        return $teacher?->status === 'active' && (bool) $teacher->teacherProfile?->is_accepting_requests;
    }

    private function archivePreviousFixtures(int $studentId): void
    {
        LearningPackage::query()->where('student_id', $studentId)->where('package_code', 'like', self::PACKAGE_PREFIX.'%')
            ->whereNotIn('status', ['completed', 'refunded', 'cancelled'])->get()->each(function (LearningPackage $package) {
                $subjectIds = $package->subjects()->pluck('id');
                Booking::query()->whereHas('packageSession', fn ($query) => $query->whereIn('package_subject_id', $subjectIds))
                    ->whereNotIn('status', ['completed', 'refunded', 'cancelled'])->update(['status' => 'cancelled', 'payout_status' => 'cancelled']);
                PackageSession::query()->whereIn('package_subject_id', $subjectIds)->whereNotIn('status', ['completed', 'refunded'])->update(['status' => 'cancelled']);
                TeacherReplacementRequest::query()->where('learning_package_id', $package->id)
                    ->whereNotIn('status', ['completed', 'refunded', 'rejected', 'cancelled'])->update(['status' => 'cancelled', 'completed_at' => now()]);
                $package->subjects()->where('status', '!=', 'completed')->update(['status' => 'cancelled']);
                $package->update(['status' => 'cancelled']);
            });
    }

    private function resetDemo(): int
    {
        $student = User::query()->where('email', self::STUDENT_EMAIL)->first();
        if (! $student) {
            $this->info('Belum ada fixture demo yang perlu direset.');

            return self::SUCCESS;
        }
        DB::transaction(fn () => $this->archivePreviousFixtures($student->id), 3);
        $this->candidateAvailability(true);
        $this->info('Fixture aktif diarsipkan. Riwayat sesi dan finansial sengaja dipertahankan untuk audit.');

        return self::SUCCESS;
    }

    private function invalidStage(): int
    {
        $this->error('Stage tidak dikenal. Gunakan setup, status, candidate-off, candidate-on, no-teacher, refund-ready, complete-refund, atau reset.');

        return self::INVALID;
    }
}
