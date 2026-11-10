<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\HourlyRate;
use App\Models\LearningPackage;
use App\Models\LearningTimeSlot;
use App\Models\Order;
use App\Models\PackageChapter;
use App\Models\PackagePlan;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\PaymentSetting;
use App\Models\TeacherAvailability;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\HourlyRateService;
use App\Services\PackageCheckoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DemoPackageRenewal extends Command
{
    protected $signature = 'demo:package-renewal
        {stage=setup : setup|status|payment-paid|final-session-ready|checkout-ready|reset}';

    protected $description = 'Menyiapkan dan mempercepat demo Paket Belajar selesai -> perpanjang -> tutor menerima -> selesai lagi';

    private const STUDENT_EMAIL = 'demo.student@bimbelku.local';

    private const TEACHER_EMAIL = 'demo.tutor@bimbelku.local';

    private const PASSWORD = 'password';

    private const SOURCE_PREFIX = 'DEMO-RENEW-SOURCE-';

    private const SUBJECT_NAME = 'Matematika';

    public function handle(PackageCheckoutService $checkoutService): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Command demo hanya boleh dijalankan pada environment local/testing.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('stage')) {
            'setup' => $this->setup(),
            'status' => $this->status(),
            'payment-paid' => $this->paymentPaid($checkoutService),
            'final-session-ready' => $this->finalSessionReady(),
            'checkout-ready' => $this->checkoutReady(),
            'reset' => $this->resetDemo(),
            default => $this->invalidStage(),
        };
    }

    private function setup(): int
    {
        [$student, $teacher] = $this->ensureDemoUsersAndMatching();

        $package = DB::transaction(function () use ($student, $teacher) {
            $this->archiveActiveRenewals($student->id);

            $plan = PackagePlan::query()->where('is_active', true)->where('session_count', 4)->first();
            if (! $plan) {
                $plan = PackagePlan::create([
                    'name' => 'Demo Perpanjangan 4 Sesi',
                    'slug' => 'demo-renewal-four-session',
                    'description' => 'Plan lokal untuk pengujian perpanjangan.',
                    'session_count' => 4,
                    'validity_days' => 30,
                    'maximum_subjects' => 1,
                    'sort_order' => 9998,
                    'is_active' => true,
                ]);
            }

            $catalogSubject = CurriculumSubject::query()->updateOrCreate(
                ['normalized_name' => 'matematika-demo-renewal'],
                [
                    'name' => self::SUBJECT_NAME,
                    'group_name' => 'Demo',
                    'education_levels' => ['SMP'],
                    'grades' => ['Kelas 7'],
                    'is_elective' => false,
                    'is_active' => true,
                    'curriculum_name' => 'Kurikulum Merdeka',
                ]
            );

            $oldChapter = $this->ensureChapter($catalogSubject, 'Persamaan Linear', 1);
            $this->ensureChapter($catalogSubject, 'Fungsi dan Persamaan Kuadrat', 2);

            HourlyRate::query()->updateOrCreate(
                [
                    'subject_name' => self::SUBJECT_NAME,
                    'education_level' => 'SMP',
                    'class_type' => 'private',
                    'learning_mode' => 'online',
                ],
                [
                    'curriculum_subject_id' => $catalogSubject->id,
                    'amount' => 50000,
                    'is_active' => true,
                ]
            );

            $completedAt = now()->subDay();
            $package = LearningPackage::create([
                'student_id' => $student->id,
                'package_plan_id' => $plan->id,
                'package_code' => self::SOURCE_PREFIX.now()->format('YmdHis').'-'.$student->id,
                'education_level' => 'SMP',
                'grade' => 'Kelas 7',
                'learning_mode' => 'online',
                'duration_hours' => 1,
                'status' => 'completed',
                'total_sessions' => 4,
                'used_sessions' => 4,
                'subtotal_amount' => 200000,
                'discount_amount' => 0,
                'total_amount' => 200000,
                'starts_at' => now()->subDays(14),
                'expires_at' => now()->addDays(16),
                'completed_at' => $completedAt,
            ]);

            $subject = PackageSubject::create([
                'learning_package_id' => $package->id,
                'curriculum_subject_id' => $catalogSubject->id,
                'curriculum_chapter_id' => $oldChapter->id,
                'curriculum_chapter_ids' => [$oldChapter->id],
                'assigned_teacher_id' => $teacher->id,
                'preferred_teacher_id' => $teacher->id,
                'subject_name' => self::SUBJECT_NAME,
                'chapter' => 'Persamaan Linear',
                'learning_goal' => 'Menguasai Persamaan Linear.',
                'allocated_sessions' => 4,
                'unit_price' => 50000,
                'subtotal_amount' => 200000,
                'status' => 'completed',
            ]);

            PackageChapter::create([
                'package_subject_id' => $subject->id,
                'curriculum_chapter_id' => $oldChapter->id,
                'title' => $oldChapter->title,
                'status' => 'completed',
                'needs_review' => false,
                'sort_order' => 1,
                'started_at' => now()->subDays(12),
                'completed_at' => $completedAt,
            ]);

            $order = Order::create([
                'user_id' => $student->id,
                'learning_package_id' => $package->id,
                'order_id' => 'DEMO-RENEW-OLD-'.now()->format('YmdHis').'-'.$package->id,
                'subtotal_amount' => 200000,
                'discount_amount' => 0,
                'amount' => 200000,
                'status' => 'paid',
                'verified_at' => now()->subDays(14),
                'class_details_snapshot' => [
                    'is_demo_seed' => true,
                    'flow_version' => 6,
                    'learning_package_id' => $package->id,
                    'package_code' => $package->package_code,
                    'package_name' => $plan->name,
                    'subject' => self::SUBJECT_NAME,
                    'teacher_name' => $teacher->name,
                    'type' => 'Paket Privat',
                    'method' => 'online',
                    'session_count' => 4,
                    'duration_hours' => 1,
                ],
            ]);

            for ($sequence = 1; $sequence <= 4; $sequence++) {
                $start = now()->subDays(14 - ($sequence * 2))->setTime(18, 0, 0);
                $end = $start->copy()->addHour();
                $request = BookingRequest::create([
                    'student_id' => $student->id,
                    'matched_teacher_id' => $teacher->id,
                    'package_subject_id' => $subject->id,
                    'curriculum_subject_id' => $catalogSubject->id,
                    'subject_name' => self::SUBJECT_NAME,
                    'education_level' => 'SMP',
                    'grade' => 'Kelas 7',
                    'chapter' => 'Persamaan Linear',
                    'learning_goal' => 'Menguasai Persamaan Linear.',
                    'learning_mode' => 'online',
                    'class_type' => 'private',
                    'scheduled_date' => $start->toDateString(),
                    'start_time' => $start->format('H:i:s'),
                    'end_time' => $end->format('H:i:s'),
                    'duration_hours' => 1,
                    'status' => 'completed',
                    'hourly_rate' => 50000,
                    'total_amount' => 50000,
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
                    'hourly_rate' => 50000,
                    'total_amount' => 50000,
                    'gross_amount' => 50000,
                    'teacher_net_amount' => 40000,
                    'commission_percent' => 20,
                    'status' => 'completed',
                    'completed_at' => $end,
                    'student_approved_at' => $end,
                    'payout_status' => 'cancelled',
                ]);
                BookingParticipant::create([
                    'booking_id' => $booking->id,
                    'booking_request_id' => $request->id,
                    'student_id' => $student->id,
                    'order_id' => $order->id,
                    'amount' => 50000,
                    'status' => 'approved',
                    'approved_at' => $end,
                ]);
                PackageSession::create([
                    'package_subject_id' => $subject->id,
                    'booking_id' => $booking->id,
                    'sequence' => $sequence,
                    'scheduled_start_at' => $start,
                    'scheduled_end_at' => $end,
                    'status' => 'completed',
                ]);
                $request->update(['booking_id' => $booking->id]);
                if ($sequence === 1) {
                    $order->update(['booking_id' => $booking->id]);
                }
            }

            return $package->fresh(['subjects.assignedTeacher', 'subjects.chapters']);
        });

        Cache::forget('learning_catalog.payload');
        app(HourlyRateService::class)->clearCache();

        $this->newLine();
        $this->info('Demo sumber perpanjangan siap. Paket lama sudah COMPLETED dan materi lama 100%.');
        $this->line('Murid : '.self::STUDENT_EMAIL.' / '.self::PASSWORD);
        $this->line('Tutor : '.self::TEACHER_EMAIL.' / '.self::PASSWORD);
        $this->line('Paket lama : '.$package->package_code.' (ID '.$package->id.')');
        $this->newLine();
        $this->comment('1) Login murid -> Kelas Saya -> Riwayat.');
        $this->comment('2) Klik "Perpanjang dengan Tutor Ini". Paket selesai boleh langsung diperpanjang.');
        $this->comment('3) Karena materi lama 100%, pilih materi lanjutan "Fungsi dan Persamaan Kuadrat".');
        $this->comment('4) Susun 4 jadwal minimal 12 jam dari sekarang. Pesanan baru memakai lead time 24 jam.');
        $this->comment('5) Jalankan: php artisan demo:package-renewal payment-paid');
        $this->comment('6) Login tutor -> Permintaan Bimbel -> terima permintaan perpanjangan.');
        $this->comment('7) Jalankan final-session-ready untuk mempercepat ke sesi terakhir Paket 2.');

        return self::SUCCESS;
    }

    private function paymentPaid(PackageCheckoutService $checkoutService): int
    {
        $renewal = $this->latestRenewalPackage();
        if (! $renewal) {
            $this->error('Belum ada Paket 2. Buat perpanjangan lewat UI murid terlebih dahulu.');

            return self::FAILURE;
        }

        $order = $renewal->orders()->latest()->first();
        if (! $order) {
            $this->error('Tagihan Paket 2 belum ditemukan.');

            return self::FAILURE;
        }

        if ($order->status === 'pending') {
            $order->update([
                'status' => 'submitted',
                'payment_submitted_at' => now(),
            ]);
        }

        if ($order->fresh()->status === 'submitted') {
            $result = $checkoutService->activatePaidPackage($order->fresh(), null);
            $this->info('Pembayaran demo diproses melalui lifecycle Paket Belajar: '.$result.'.');
        } elseif ($order->status === 'paid') {
            $this->info('Pembayaran Paket 2 memang sudah paid.');
        } else {
            $this->error('Order berada pada status '.$order->status.' dan tidak dapat dipercepat oleh demo ini.');

            return self::FAILURE;
        }

        $renewal = $renewal->fresh(['subjects.bookingRequest.offers', 'subjects.assignedTeacher']);
        $offer = $renewal->subjects->flatMap(fn ($subject) => $subject->bookingRequest?->offers ?? collect())->where('status', 'pending')->first();
        $this->line('Status Paket 2 : '.$renewal->status);
        $this->line('Penawaran tutor lama : '.($offer ? 'tersedia (Offer ID '.$offer->id.')' : 'belum tersedia'));
        $this->comment('Sekarang login tutor -> Permintaan Bimbel dan TERIMA permintaan. Jangan jalankan final-session-ready sebelum tutor menerima.');

        return self::SUCCESS;
    }

    private function finalSessionReady(): int
    {
        $package = $this->latestRenewalPackage();
        if (! $package) {
            $this->error('Belum ada Paket 2.');

            return self::FAILURE;
        }
        if ($package->status !== 'active') {
            $this->error('Paket 2 belum aktif. Tutor lama harus menerima permintaan perpanjangan terlebih dahulu. Status sekarang: '.$package->status);

            return self::FAILURE;
        }

        $sessions = $package->subjects()->with('sessions.booking.participants')->get()->flatMap->sessions->sortBy('sequence')->values();
        if ($sessions->count() < 1 || $sessions->contains(fn ($session) => ! $session->booking)) {
            $this->error('Booking Paket 2 belum lengkap.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($package, $sessions) {
            $lastIndex = $sessions->count() - 1;
            foreach ($sessions as $index => $session) {
                $booking = Booking::query()->with(['participants', 'bookingRequest'])->lockForUpdate()->findOrFail($session->booking_id);
                if ($index < $lastIndex) {
                    $finishedAt = now()->subDays(max(1, $lastIndex - $index))->setTime(19, 0, 0);
                    $booking->participants()->update(['status' => 'approved', 'approved_at' => $finishedAt]);
                    $booking->bookingRequest?->update(['status' => 'completed']);
                    $booking->update([
                        'status' => 'completed',
                        'completed_at' => $finishedAt,
                        'student_approved_at' => $finishedAt,
                        'payout_status' => 'cancelled',
                    ]);

                    continue;
                }

                $start = now()->subMinutes(2)->startOfMinute();
                $end = $start->copy()->addHour();
                $booking->participants()->update(['status' => 'paid', 'approved_at' => null]);
                $booking->update([
                    'start_at' => $start,
                    'end_at' => $end,
                    'status' => 'confirmed',
                    'completed_at' => null,
                    'student_approved_at' => null,
                    'payout_status' => 'locked',
                    'objection_deadline' => null,
                ]);
                $booking->bookingRequest?->update([
                    'status' => 'confirmed',
                    'scheduled_date' => $start->toDateString(),
                    'start_time' => $start->format('H:i:s'),
                    'end_time' => $end->format('H:i:s'),
                ]);
                $session->update([
                    'scheduled_start_at' => $start,
                    'scheduled_end_at' => $end,
                    'status' => 'scheduled',
                ]);
            }

            $package->subjects()->update(['status' => 'active']);
            $package->update(['status' => 'active', 'completed_at' => null, 'used_sessions' => max(0, $sessions->count() - 1)]);
        });

        $last = $this->latestRenewalBooking();
        // Fixture demo menyediakan URL agar Session Flow V2 dapat diuji sampai tombol Zoom.
        $last?->update(['meeting_link' => 'https://zoom.us/j/12345678901']);
        $this->info('Paket 2 dipercepat ke PERTEMUAN TERAKHIR. Pertemuan sebelumnya dianggap selesai hanya untuk demo lokal.');
        $this->line('Booking final ID : '.$last?->id);
        $this->line('Jadwal final : '.$last?->start_at?->format('d-m-Y H:i').' - '.$last?->end_at?->format('H:i'));
        $this->comment('Paket 2 memakai Session Flow V2: Tutor -> Saya Siap Mengajar, lalu Murid -> Saya Sudah Hadir.');
        $this->comment('Setelah murid hadir, jalankan: php artisan demo:package-renewal checkout-ready');

        return self::SUCCESS;
    }

    private function checkoutReady(): int
    {
        $booking = $this->latestRenewalBooking();
        if (! $booking || ! in_array($booking->status, ['confirmed', 'in_progress'], true)) {
            $this->error('Sesi final Paket 2 belum siap. Jalankan final-session-ready setelah tutor menerima Paket 2.');

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

        $this->info('Sesi final Paket 2 sekarang siap diakhiri. Refresh tutor.');
        $this->comment('Tutor: Akhiri Sesi -> Isi Hasil Belajar -> Murid memilih Sesi Sesuai / Ada masalah.');
        $this->comment('Karena ini sesi terakhir, setelah murid menyetujui Paket 2 harus pindah ke Riwayat.');

        return self::SUCCESS;
    }

    private function status(): int
    {
        $source = $this->latestSourcePackage();
        if (! $source) {
            $this->warn('Belum ada demo perpanjangan. Jalankan: php artisan demo:package-renewal setup');

            return self::SUCCESS;
        }

        $source->load('subjects.chapters');
        $renewal = LearningPackage::query()
            ->where('renewal_of_id', $source->id)
            ->with(['subjects.chapters', 'subjects.assignedTeacher', 'subjects.bookingRequest.offers', 'subjects.sessions.booking', 'orders'])
            ->latest('id')
            ->first();

        $rows = [
            ['Paket 1', $source->package_code],
            ['Status Paket 1', $source->status],
            ['Progress Paket 1', $source->subjects->flatMap->chapters->every(fn ($topic) => $topic->status === 'completed') ? '100% selesai' : 'belum 100%'],
            ['Bisa diperpanjang sekarang', $source->status === 'completed' ? 'ya' : 'tidak'],
            ['Paket 2', $renewal?->package_code ?: 'belum dibuat lewat UI'],
            ['Status Paket 2', $renewal?->status ?: '-'],
            ['Order Paket 2', $renewal?->orders?->first()?->status ?: '-'],
            ['Tutor Paket 2', $renewal?->subjects?->first()?->assignedTeacher?->name ?: 'belum menerima'],
            ['Offer tutor lama', $renewal?->subjects?->first()?->bookingRequest?->offers?->where('status', 'pending')->isNotEmpty() ? 'menunggu tutor' : '-'],
            ['Sesi Paket 2', $renewal?->subjects?->flatMap->sessions?->count() ?: 0],
            ['Sesi selesai Paket 2', $renewal?->subjects?->flatMap->sessions?->filter(fn ($session) => $session->booking?->status === 'completed')->count() ?: 0],
        ];
        $this->table(['Data', 'Nilai'], $rows);

        if ($renewal) {
            $topics = $renewal->subjects->flatMap->chapters;
            if ($topics->isNotEmpty()) {
                $this->newLine();
                $this->line('Materi Paket 2:');
                foreach ($topics as $topic) {
                    $this->line('- '.$topic->title.' = '.$topic->status.($topic->needs_review ? ' (penguatan materi lama)' : ''));
                }
            }
        }

        return self::SUCCESS;
    }

    private function resetDemo(): int
    {
        $studentId = User::query()->where('email', self::STUDENT_EMAIL)->value('id');
        if (! $studentId) {
            $this->info('Belum ada akun demo. Tidak ada yang perlu direset.');

            return self::SUCCESS;
        }
        $this->archiveActiveRenewals((int) $studentId);
        $this->info('Paket renewal aktif dinonaktifkan. Riwayat paket demo yang sudah selesai tetap dipertahankan.');

        return self::SUCCESS;
    }

    private function ensureDemoUsersAndMatching(): array
    {
        $catalogSubject = CurriculumSubject::query()->updateOrCreate(
            ['normalized_name' => 'matematika-demo-renewal'],
            [
                'name' => self::SUBJECT_NAME,
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
        $profile = TeacherProfile::query()->updateOrCreate(
            ['user_id' => $teacher->id],
            [
                'expertise' => self::SUBJECT_NAME,
                'teaching_method' => 'Online',
                'bio' => 'Tutor demo lokal untuk pengujian perpanjangan Paket Belajar.',
                'verified_at' => now(),
                'is_accepting_requests' => true,
            ]
        );
        TeacherSubject::query()->where('teacher_profile_id', $profile->id)->delete();
        TeacherSubject::query()->create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $catalogSubject->id,
            'name' => self::SUBJECT_NAME,
            'levels' => ['SMP'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => false,
            'is_private_active' => true,
        ]);
        foreach ([1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'] as $day) {
            TeacherAvailability::query()->updateOrCreate(
                ['user_id' => $teacher->id, 'day' => $day],
                ['slots' => [['start_time' => '08:00', 'end_time' => '23:00']], 'is_active' => true]
            );
        }
        foreach (range(8, 22) as $hour) {
            LearningTimeSlot::query()->updateOrCreate(
                ['start_time' => sprintf('%02d:00:00', $hour)],
                ['label' => sprintf('%02d.00 WIB', $hour), 'sort_order' => $hour, 'is_active' => true]
            );
        }
        PaymentSetting::query()->updateOrCreate(
            ['singleton_key' => 1],
            [
                'merchant_name' => 'BimbelKu Demo',
                'bank_name' => 'Bank Demo',
                'account_number' => '0000000000',
                'account_name' => 'BimbelKu Demo',
            ]
        );

        return [$student->fresh(), $teacher->fresh('teacherProfile')];
    }

    private function ensureChapter(CurriculumSubject $subject, string $title, int $sortOrder): CurriculumChapter
    {
        return CurriculumChapter::query()->updateOrCreate(
            [
                'curriculum_subject_id' => $subject->id,
                'grade' => 'Kelas 7',
                'normalized_title' => mb_strtolower($title),
            ],
            [
                'education_level' => 'SMP',
                'title' => $title,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'source_reference' => 'demo-renewal-local',
            ]
        );
    }

    private function latestSourcePackage(): ?LearningPackage
    {
        $studentId = User::query()->where('email', self::STUDENT_EMAIL)->value('id');
        if (! $studentId) {
            return null;
        }

        return LearningPackage::query()
            ->where('student_id', $studentId)
            ->where('package_code', 'like', self::SOURCE_PREFIX.'%')
            ->latest('id')
            ->first();
    }

    private function latestRenewalPackage(): ?LearningPackage
    {
        $source = $this->latestSourcePackage();
        if (! $source) {
            return null;
        }

        return LearningPackage::query()
            ->where('renewal_of_id', $source->id)
            ->with(['orders', 'subjects.sessions.booking', 'subjects.bookingRequest.offers'])
            ->latest('id')
            ->first();
    }

    private function latestRenewalBooking(): ?Booking
    {
        $package = $this->latestRenewalPackage();
        if (! $package) {
            return null;
        }

        return PackageSession::query()
            ->whereHas('subject', fn ($query) => $query->where('learning_package_id', $package->id))
            ->whereNotNull('booking_id')
            ->with('booking')
            ->orderByDesc('sequence')
            ->first()
            ?->booking;
    }

    private function archiveActiveRenewals(int $studentId): void
    {
        $sourceIds = LearningPackage::query()
            ->where('student_id', $studentId)
            ->where('package_code', 'like', self::SOURCE_PREFIX.'%')
            ->pluck('id');
        if ($sourceIds->isEmpty()) {
            return;
        }

        LearningPackage::query()
            ->whereIn('renewal_of_id', $sourceIds)
            ->whereNotIn('status', ['completed', 'cancelled', 'refunded'])
            ->get()
            ->each(function (LearningPackage $package) {
                Booking::query()
                    ->whereHas('packageSession.subject', fn ($query) => $query->where('learning_package_id', $package->id))
                    ->whereNotIn('status', ['completed', 'cancelled', 'refunded'])
                    ->update(['status' => 'cancelled', 'payout_status' => 'cancelled']);
                $package->update(['status' => 'cancelled']);
            });
    }

    private function invalidStage(): int
    {
        $this->error('Stage tidak dikenal. Gunakan: setup, status, payment-paid, final-session-ready, checkout-ready, atau reset.');

        return self::INVALID;
    }
}
