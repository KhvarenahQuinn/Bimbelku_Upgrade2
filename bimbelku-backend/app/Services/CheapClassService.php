<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CheapClass;
use App\Models\CheapClassEnrollment;
use App\Models\CheapClassSession;
use App\Models\CheapClassTemplate;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Refund;
use App\Models\TeacherAvailability;
use App\Models\TeacherAvailabilityException;
use App\Models\User;
use App\Support\PaymentWindow;
use App\Support\PublicMedia;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CheapClassService
{
    public function __construct(
        private readonly CustomerWalletService $wallets,
        private readonly PaymentReconciliationService $paymentReconciliation,
    ) {}

    private const ACTIVE_SEAT_STATUSES = ['seat_held', 'payment_submitted', 'payment_rejected', 'partially_paid', 'confirmed'];

    private const ACTIVE_CLASS_STATUSES = ['waiting_teacher', 'open', 'registration_closed', 'awaiting_verification', 'confirmed'];

    /** @return array{template:CheapClassTemplate,class:CheapClass,teacher:?User} */
    public function createPackage(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $explicitRegistrationOpensAt = $data['registration_opens_at'] ?? null;
            unset($data['registration_opens_at']);
            $startsAt = Carbon::parse(
                $data['first_session_date'].' '.$data['start_time'],
                config('app.timezone', 'Asia/Jakarta')
            );
            $data['recurrence_days'] = $this->normalizeRecurrenceDays(
                $data['recurrence_days'] ?? null,
                $startsAt->dayOfWeekIso
            );
            if ($explicitRegistrationOpensAt) {
                $registrationOpensAt = Carbon::parse(
                    $explicitRegistrationOpensAt,
                    config('app.timezone', 'Asia/Jakarta')
                );
                $deadline = $registrationOpensAt->copy()
                    ->addHours((int) $data['registration_window_hours']);
                abort_if(
                    $startsAt->lt($deadline->copy()->addMinutes((int) $data['registration_closes_before_minutes'])),
                    422,
                    'Sesi pertama harus berada setelah masa pendaftaran dan jeda penutupan selesai.'
                );
            } else {
                // Kompatibilitas request lama: tanggal masih dianggap sebagai
                // sesi pertama dan waktu pembukaan dihitung mundur.
                $deadline = $startsAt->copy()
                    ->subMinutes((int) $data['registration_closes_before_minutes']);
                $registrationOpensAt = $deadline->copy()
                    ->subHours((int) $data['registration_window_hours']);
            }
            $recurrenceEnabled = (bool) ($data['recurrence_enabled'] ?? false);

            $template = CheapClassTemplate::create([
                ...$data,
                'payment_window_minutes' => PaymentWindow::MINUTES,
                'teacher_id' => null,
                'recurrence_enabled' => $recurrenceEnabled,
                'recurrence_anchor_at' => $recurrenceEnabled ? $registrationOpensAt : null,
                'next_publish_at' => $recurrenceEnabled ? $registrationOpensAt->copy()->addWeek() : null,
                'last_published_at' => $recurrenceEnabled ? $registrationOpensAt : null,
                'settings_version' => 1,
                'is_active' => $recurrenceEnabled,
            ]);
            $template = $template->fresh();

            [$class, $teacher] = $this->createOccurrenceFromTemplate(
                $template,
                $startsAt,
                $registrationOpensAt,
                'manual'
            );

            return [
                'template' => $template->fresh(),
                'class' => $class->fresh(['teacher:id,name', 'sessions']),
                'teacher' => $teacher,
            ];
        }, 3);
    }

    /**
     * Membuat satu paket independen dari template. Enrollment, order, tutor,
     * Zoom, kuota, dan refund tidak pernah disalin dari paket sebelumnya.
     *
     * @return array{0:CheapClass,1:?User}
     */
    private function createOccurrenceFromTemplate(
        CheapClassTemplate $template,
        Carbon $startsAt,
        Carbon $registrationOpensAt,
        string $generationSource
    ): array {
        $deadline = $registrationOpensAt->copy()
            ->addHours((int) $template->registration_window_hours);
        abort_if(
            $startsAt->lt($deadline->copy()->addMinutes((int) $template->registration_closes_before_minutes)),
            422,
            'Sesi pertama harus berada setelah masa pendaftaran dan jeda penutupan selesai.'
        );

        $class = CheapClass::create([
            'cheap_class_template_id' => $template->id,
            'occurrence_week_start' => $registrationOpensAt->copy()->startOfWeek()->toDateString(),
            'generation_source' => $generationSource,
            'template_settings_version' => (int) $template->settings_version,
            'template_snapshot' => $this->templateSnapshot($template),
            'curriculum_subject_id' => $template->curriculum_subject_id,
            'curriculum_chapter_id' => $template->curriculum_chapter_id,
            'subjects' => $template->subjects,
            'teacher_id' => null,
            'subject_name' => $template->subject_name,
            'education_level' => $template->education_level,
            'grade' => $template->grade,
            'chapter' => $template->chapter,
            'topic' => $template->topic,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes((int) $template->duration_minutes),
            'session_count' => $template->session_count,
            'registration_opens_at' => $registrationOpensAt,
            'registration_deadline' => $deadline,
            'price_per_student' => $template->price_per_student,
            'price_per_session' => $template->price_per_session,
            'custom_price_per_student' => $template->custom_price_per_student,
            'minimum_participants' => $template->minimum_participants,
            'maximum_participants' => $template->maximum_participants,
            'payment_window_minutes' => PaymentWindow::MINUTES,
            'status' => 'waiting_teacher',
        ]);

        $this->ensureSessions(
            $class,
            (int) $template->session_count,
            (int) $template->duration_minutes,
            $template->recurrence_days ?? []
        );
        $teacher = $this->replaceTeacherIfNeeded($class);

        return [$class, $teacher];
    }

    /**
     * Menentukan apakah paket aman dihapus permanen.
     * Paket cancelled tetap boleh dihapus bila benar-benar kosong dan belum pernah dimulai.
     * Satu sumber aturan ini dipakai oleh endpoint hapus dan payload UI agar tidak berbeda perilaku.
     *
     * @return array{can_delete:bool,reason:?string}
     */
    public function deletionEligibility(CheapClass $cheapClass): array
    {
        $hasEnrollmentHistory = array_key_exists('enrollments_count', $cheapClass->getAttributes())
            ? (int) $cheapClass->getAttribute('enrollments_count') > 0
            : $cheapClass->enrollments()->exists();

        if ($hasEnrollmentHistory) {
            return [
                'can_delete' => false,
                'reason' => 'Paket tidak dapat dihapus karena sudah memiliki riwayat peserta.',
            ];
        }

        if ($cheapClass->starts_at->lte(now())) {
            return [
                'can_delete' => false,
                'reason' => 'Paket sudah dimulai sehingga disimpan sebagai riwayat.',
            ];
        }

        if (! in_array($cheapClass->status, ['waiting_teacher', 'open', 'registration_closed', 'cancelled'], true)) {
            return [
                'can_delete' => false,
                'reason' => 'Status paket ini tidak mengizinkan penghapusan.',
            ];
        }

        return ['can_delete' => true, 'reason' => null];
    }

    /**
     * Menentukan apakah admin masih boleh membatalkan paket.
     * Pembatalan admin hanya berlaku sebelum sesi pertama dimulai. Paket yang sudah
     * dikonfirmasi tetap boleh dibatalkan sebelum mulai; pembayaran valid akan
     * mengikuti alur refund yang sama melalui cancelClass().
     *
     * @return array{can_cancel:bool,reason:?string}
     */
    public function cancellationEligibility(CheapClass $cheapClass): array
    {
        if ($cheapClass->status === 'cancelled') {
            return [
                'can_cancel' => false,
                'reason' => 'Paket sudah dibatalkan.',
            ];
        }

        if ($cheapClass->status === 'completed') {
            return [
                'can_cancel' => false,
                'reason' => 'Paket sudah selesai dan disimpan sebagai riwayat.',
            ];
        }

        if (! in_array($cheapClass->status, self::ACTIVE_CLASS_STATUSES, true)) {
            return [
                'can_cancel' => false,
                'reason' => 'Status paket ini tidak mengizinkan pembatalan.',
            ];
        }

        $firstSessionAt = $cheapClass->sessions()->min('starts_at');
        $startsAt = $firstSessionAt ? Carbon::parse($firstSessionAt) : $cheapClass->starts_at;
        if (! $startsAt || $startsAt->lte(now())) {
            return [
                'can_cancel' => false,
                'reason' => 'Paket sudah dimulai sehingga tidak dapat dibatalkan dari halaman jadwal.',
            ];
        }

        return ['can_cancel' => true, 'reason' => null];
    }

    /** Menghapus satu paket mendatang beserta seluruh sesinya jika belum pernah memiliki peserta. */
    public function deleteEmptyPackage(CheapClass $cheapClass): array
    {
        return DB::transaction(function () use ($cheapClass) {
            $class = CheapClass::query()->lockForUpdate()->findOrFail($cheapClass->id);
            $eligibility = $this->deletionEligibility($class);
            abort_unless($eligibility['can_delete'], 422, (string) $eligibility['reason']);
            $templateId = $class->cheap_class_template_id;
            $sessionCount = $class->sessions()->count();
            $class->delete();

            $templateRemoved = false;
            if ($templateId) {
                $template = CheapClassTemplate::query()->lockForUpdate()->find($templateId);
                if ($template && ! $template->recurrence_enabled && ! $template->classes()->exists()) {
                    $template->delete();
                    $templateRemoved = true;
                }
            }

            return [
                'removed_sessions' => $sessionCount,
                'removed_template' => $templateRemoved,
            ];
        }, 3);
    }

    /**
     * Menjalankan pemeliharaan ringan yang dibutuhkan saat permintaan pengguna.
     * Pembuatan paket tidak dijalankan di sini agar permintaan baca tetap ringan.
     */
    public function refreshLifecycle(bool $searchWaitingTeachers = true): array
    {
        $expired = $this->expireSeats();
        $finalized = 0;
        $teacherMatches = $searchWaitingTeachers
            ? $this->retryWaitingTeachersWhenDue()
            : ['checked' => 0, 'assigned' => 0];

        CheapClass::query()
            ->whereIn('status', ['waiting_teacher', 'open', 'registration_closed', 'awaiting_verification'])
            ->where('registration_deadline', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($classes) use (&$finalized) {
                foreach ($classes as $class) {
                    $before = $class->status;
                    $this->finalizeIfReady($class);
                    if ($class->fresh()?->status !== $before) {
                        $finalized++;
                    }
                }
            });

        $sessionsUpdated = $this->synchronizeSessionStatuses();
        $completed = $this->completeFinishedClasses();

        return [
            'expired' => $expired,
            'finalized' => $finalized,
            'sessionsUpdated' => $sessionsUpdated,
            'completed' => $completed,
            'teachers_checked' => $teacherMatches['checked'],
            'teachers_assigned' => $teacherMatches['assigned'],
        ];
    }

    /** Menjalankan pemeliharaan penuh dari scheduler setiap menit. */
    public function maintain(): array
    {
        $recurring = $this->publishDueRecurringPackages();
        $lifecycle = $this->refreshLifecycle(false);
        $waitingMatches = $this->retryWaitingTeachers();
        $teacherChecks = 0;

        try {
            $shouldCheckTeachers = Cache::add('cheap_classes.teacher_check_lock', true, now()->addMinutes(10));
        } catch (Throwable) {
            // Cache database yang belum siap tidak boleh menghentikan lifecycle.
            // withoutOverlapping pada scheduler tetap menjadi pengaman utama.
            $shouldCheckTeachers = true;
        }

        if ($shouldCheckTeachers) {
            CheapClass::query()
                ->whereNotNull('teacher_id')
                ->whereIn('status', ['open', 'registration_closed', 'awaiting_verification', 'confirmed'])
                ->whereHas('sessions', fn ($sessions) => $sessions->where('ends_at', '>', now()))
                ->orderBy('id')
                ->chunkById(100, function ($classes) use (&$teacherChecks) {
                    foreach ($classes as $class) {
                        $this->replaceTeacherIfNeeded($class);
                        $teacherChecks++;
                    }
                });
        }

        return [
            'recurring_created' => $recurring['created'],
            'recurring_skipped' => $recurring['skipped'],
            'recurring_existing' => $recurring['existing'],
            'recurring_failed' => $recurring['failed'],
            'expired' => $lifecycle['expired'],
            'finalized' => $lifecycle['finalized'],
            'sessions_updated' => $lifecycle['sessionsUpdated'],
            'completed' => $lifecycle['completed'],
            'teacher_checks' => $teacherChecks + $waitingMatches['checked'],
            'teachers_assigned' => $waitingMatches['assigned'],
        ];
    }

    /**
     * Scheduler memproses template jatuh tempo. Penguncian baris dan unique key
     * template+minggu menjaga satu periode hanya memiliki satu paket.
     *
     * @return array{checked:int,created:int,skipped:int,existing:int,failed:int}
     */
    public function publishDueRecurringPackages(int $limit = 100): array
    {
        $ids = CheapClassTemplate::query()
            ->where('recurrence_enabled', true)
            ->where('is_active', true)
            ->whereNotNull('next_publish_at')
            ->where('next_publish_at', '<=', now())
            ->orderBy('next_publish_at')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');
        $stats = ['checked' => 0, 'created' => 0, 'skipped' => 0, 'existing' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $stats['checked']++;
            try {
                $result = $this->publishDueRecurringTemplate((int) $id);
                $stats['created'] += $result['created'];
                $stats['skipped'] += $result['skipped'];
                $stats['existing'] += $result['existing'];
            } catch (Throwable $exception) {
                $stats['failed']++;
                CheapClassTemplate::query()->whereKey($id)->update([
                    'last_generation_failed_at' => now(),
                    'last_generation_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
            }
        }

        return $stats;
    }

    /** @return array{created:int,skipped:int,existing:int} */
    private function publishDueRecurringTemplate(int $templateId): array
    {
        return DB::transaction(function () use ($templateId) {
            $template = CheapClassTemplate::query()->lockForUpdate()->find($templateId);
            if (
                ! $template
                || ! $template->recurrence_enabled
                || ! $template->is_active
                || ! $template->next_publish_at
                || $template->next_publish_at->isFuture()
            ) {
                return ['created' => 0, 'skipped' => 0, 'existing' => 0];
            }

            $scheduledPublishAt = $template->next_publish_at->copy();
            $skipped = 0;
            while (
                $scheduledPublishAt->copy()
                    ->addHours((int) $template->registration_window_hours)
                    ->lte(now())
            ) {
                $template->last_skipped_at = $scheduledPublishAt->copy();
                $scheduledPublishAt->addWeek();
                $skipped++;
            }

            if ($scheduledPublishAt->isFuture()) {
                $template->next_publish_at = $scheduledPublishAt;
                $template->last_generation_error = null;
                $template->save();

                return ['created' => 0, 'skipped' => $skipped, 'existing' => 0];
            }

            $periodStart = $scheduledPublishAt->copy()->startOfWeek()->toDateString();
            $existing = CheapClass::query()
                ->where('cheap_class_template_id', $template->id)
                ->whereDate('occurrence_week_start', $periodStart)
                ->first();
            $created = 0;
            $alreadyExists = 0;

            if ($existing) {
                $alreadyExists = 1;
            } else {
                $initialStartsAt = Carbon::parse(
                    $template->first_session_date->toDateString().' '.$template->start_time,
                    config('app.timezone', 'Asia/Jakarta')
                );
                $anchor = $template->recurrence_anchor_at
                    ? Carbon::parse($template->recurrence_anchor_at)
                    : $scheduledPublishAt->copy();
                $startOffsetSeconds = $initialStartsAt->getTimestamp() - $anchor->getTimestamp();
                abort_if($startOffsetSeconds < 0, 422, 'Patokan sesi pertama template tidak valid.');
                $startsAt = $scheduledPublishAt->copy()->addSeconds($startOffsetSeconds);
                $this->createOccurrenceFromTemplate(
                    $template,
                    $startsAt,
                    $scheduledPublishAt,
                    'recurring'
                );
                $created = 1;
            }

            $template->last_published_at = $scheduledPublishAt;
            $template->next_publish_at = $scheduledPublishAt->copy()->addWeek();
            $template->last_generation_failed_at = null;
            $template->last_generation_error = null;
            $template->save();

            return ['created' => $created, 'skipped' => $skipped, 'existing' => $alreadyExists];
        }, 3);
    }

    /**
     * Menonaktifkan hanya menghentikan paket baru. Mengaktifkan kembali selalu
     * memilih jadwal mingguan terdekat di masa depan dan tidak melakukan backfill.
     */
    public function setRecurrenceActive(CheapClassTemplate $template, bool $active): CheapClassTemplate
    {
        return DB::transaction(function () use ($template, $active) {
            $locked = CheapClassTemplate::query()->lockForUpdate()->findOrFail($template->id);
            abort_unless($locked->recurrence_enabled, 422, 'Template paket sekali dibuat tidak memiliki pengulangan mingguan.');

            if (! $active) {
                $locked->update([
                    'is_active' => false,
                    'next_publish_at' => null,
                ]);

                return $locked->fresh();
            }

            $anchor = $locked->recurrence_anchor_at
                ?? $locked->classes()->orderBy('registration_opens_at')->value('registration_opens_at');
            abort_unless($anchor, 422, 'Patokan jadwal pengulangan belum tersedia.');
            $next = $this->nextAlignedPublishAfter(Carbon::parse($anchor), now());
            $locked->update([
                'is_active' => true,
                'recurrence_anchor_at' => $locked->recurrence_anchor_at ?? Carbon::parse($anchor),
                'next_publish_at' => $next,
                'last_generation_failed_at' => null,
                'last_generation_error' => null,
            ]);

            return $locked->fresh();
        }, 3);
    }

    private function nextAlignedPublishAfter(Carbon $anchor, Carbon $reference): Carbon
    {
        if ($anchor->gt($reference)) {
            return $anchor->copy();
        }

        $seconds = max(0, $reference->getTimestamp() - $anchor->getTimestamp());
        $weeks = intdiv($seconds, 7 * 24 * 60 * 60) + 1;

        return $anchor->copy()->addWeeks($weeks);
    }

    /**
     * Mencari tutor untuk paket yang masih menunggu. Method ini aman dipanggil
     * scheduler, halaman admin, atau sesaat setelah jadwal tutor berubah.
     *
     * @return array{checked:int,assigned:int}
     */
    public function retryWaitingTeachers(int $limit = 100): array
    {
        $ids = CheapClass::query()
            ->where('status', 'waiting_teacher')
            ->whereHas('sessions', fn ($sessions) => $sessions->where('ends_at', '>', now()))
            ->orderBy('starts_at')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');
        $checked = 0;
        $assigned = 0;

        foreach ($ids as $id) {
            $class = CheapClass::query()->find($id);
            if (! $class || $class->status !== 'waiting_teacher') {
                continue;
            }
            $checked++;
            if ($this->replaceTeacherIfNeeded($class)) {
                $assigned++;
            }
        }

        return compact('checked', 'assigned');
    }

    /**
     * Memeriksa ulang kelas milik tutor yang mengubah jadwal, lalu langsung
     * menawarkan paket menunggu kepada seluruh tutor yang kini memenuhi syarat.
     *
     * @return array{checked:int,reassigned:int,waiting_checked:int,waiting_assigned:int}
     */
    public function refreshTeacherAssignmentsForTeacher(int $teacherId): array
    {
        $ids = CheapClass::query()
            ->where('teacher_id', $teacherId)
            ->whereIn('status', self::ACTIVE_CLASS_STATUSES)
            ->whereHas('sessions', fn ($sessions) => $sessions->where('ends_at', '>', now()))
            ->orderBy('starts_at')
            ->pluck('id');
        $checked = 0;
        $reassigned = 0;

        foreach ($ids as $id) {
            $class = CheapClass::query()->find($id);
            if (! $class) {
                continue;
            }
            $beforeTeacherId = $class->teacher_id;
            $checked++;
            $this->replaceTeacherIfNeeded($class);
            if ((int) ($class->fresh()?->teacher_id ?? 0) !== (int) ($beforeTeacherId ?? 0)) {
                $reassigned++;
            }
        }

        $waiting = $this->retryWaitingTeachers();

        return [
            'checked' => $checked,
            'reassigned' => $reassigned,
            'waiting_checked' => $waiting['checked'],
            'waiting_assigned' => $waiting['assigned'],
        ];
    }

    /** @return array{checked:int,assigned:int} */
    private function retryWaitingTeachersWhenDue(): array
    {
        try {
            $shouldRun = Cache::add(
                'cheap_classes.waiting_teacher_search_lock',
                true,
                now()->addSeconds(30)
            );
        } catch (Throwable) {
            $shouldRun = true;
        }

        return $shouldRun
            ? $this->retryWaitingTeachers(50)
            : ['checked' => 0, 'assigned' => 0];
    }

    public function join(CheapClass $cheapClass, User $student): array
    {
        $this->refreshLifecycle();
        $discardedProof = null;

        $result = DB::transaction(function () use ($cheapClass, $student, &$discardedProof) {
            $class = CheapClass::query()->lockForUpdate()->findOrFail($cheapClass->id);
            abort_unless(
                $class->status === 'open'
                    && $class->registration_opens_at->lte(now())
                    && $class->registration_deadline->isFuture(),
                422,
                'Pendaftaran Kelas Kelompok sudah ditutup atau belum dibuka.'
            );
            abort_if(
                ! $this->teacherCanTeach($class->teacher_id, $class),
                422,
                'Tutor kelas sedang tidak tersedia. Admin sedang mencari pengganti.'
            );
            abort_if(
                $this->studentHasScheduleConflict($student->id, $class),
                422,
                'Jadwal Kelas Kelompok bertabrakan dengan kelas aktif Anda.'
            );

            $existing = CheapClassEnrollment::query()
                ->where('cheap_class_id', $class->id)
                ->where('student_id', $student->id)
                ->with('order')
                ->lockForUpdate()
                ->first();
            if ($existing && in_array($existing->status, self::ACTIVE_SEAT_STATUSES, true)) {
                abort(422, 'Anda sudah memiliki kursi pada Kelas Kelompok ini.');
            }
            if (
                $existing
                && (
                    in_array($existing->status, ['cancellation_pending', 'refund_pending', 'refunded'], true)
                    || in_array($existing->order?->status, ['submitted', 'paid', 'refund_pending', 'refunded'], true)
                )
            ) {
                abort(422, 'Keikutsertaan ini memiliki pembayaran yang masih diperiksa, sudah diterima, atau sedang direfund sehingga tidak dapat dibuat ulang.');
            }
            abort_if(
                $class->enrollments()->whereIn('status', self::ACTIVE_SEAT_STATUSES)->count() >= $class->maximum_participants,
                422,
                'Kuota Kelas Kelompok sudah penuh.'
            );

            $seatExpiresAt = now()->addMinutes(PaymentWindow::MINUTES)
                ->min($class->registration_deadline);
            abort_if($seatExpiresAt->lte(now()), 422, 'Waktu pembayaran tidak lagi tersedia untuk kelas ini.');

            $enrollment = $existing ?: new CheapClassEnrollment([
                'cheap_class_id' => $class->id,
                'student_id' => $student->id,
            ]);
            $enrollment->fill([
                'amount' => $class->price_per_student,
                'status' => 'seat_held',
                'seat_expires_at' => $seatExpiresAt,
                'payment_submitted_at' => null,
                'confirmed_at' => null,
                'cancelled_at' => null,
            ]);
            $enrollment->save();

            $order = $enrollment->order ?: new Order;
            $discardedProof = $order->payment_proof;
            $normalPackagePrice = (float) $class->price_per_session * (int) $class->session_count;
            $subtotal = max($normalPackagePrice, (float) $class->price_per_student);
            $order->fill([
                'user_id' => $student->id,
                'cheap_class_enrollment_id' => $enrollment->id,
                'amount' => $class->price_per_student,
                'subtotal_amount' => $subtotal,
                'discount_amount' => max(0, $subtotal - (float) $class->price_per_student),
                'status' => 'pending',
                'payment_proof' => null,
                'sender_name' => null,
                'bank_name' => null,
                'sender_account_number' => null,
                'payment_rejection_reason' => null,
                'payment_submitted_at' => null,
                'verified_at' => null,
                'verified_by' => null,
                'class_details_snapshot' => $this->orderSnapshot($class, $seatExpiresAt),
            ]);
            $order->save();

            if ($class->enrollments()->whereIn('status', self::ACTIVE_SEAT_STATUSES)->count() >= $class->maximum_participants) {
                $class->update(['status' => 'registration_closed']);
            }

            Notification::create([
                'user_id' => $student->id,
                'title' => 'Kursi Kelas Kelompok ditahan',
                'message' => 'Selesaikan pembayaran sebelum '.$seatExpiresAt->translatedFormat('d M Y, H:i').' WIB agar kursi tetap aman.',
                'type' => 'info',
                'target_url' => '/payment',
            ]);

            return [$enrollment->fresh('cheapClass'), $order->fresh()];
        }, 3);

        if ($discardedProof) {
            Storage::disk('local')->delete($discardedProof);
            Storage::disk('public')->delete($discardedProof);
        }

        return $result;
    }

    public function submitPayment(Order $order, array $details, ?string $path, bool $useWallet = false, ?float $walletExpectedAmount = null): void
    {
        $enrollmentId = (int) $order->cheap_class_enrollment_id;
        $classId = (int) CheapClassEnrollment::query()->whereKey($enrollmentId)->value('cheap_class_id');
        abort_if(! $enrollmentId || ! $classId, 422, 'Tagihan Kelas Kelompok tidak memiliki data peserta yang valid.');

        DB::transaction(function () use ($order, $details, $path, $useWallet, $walletExpectedAmount, $enrollmentId, $classId) {
            // Urutan lock seluruh mutasi Kelas Kelompok: class -> enrollment -> order.
            $class = CheapClass::query()->lockForUpdate()->findOrFail($classId);
            $enrollment = CheapClassEnrollment::query()
                ->whereKey($enrollmentId)
                ->where('cheap_class_id', $class->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->where('cheap_class_enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(in_array($lockedOrder->status, ['pending', 'rejected', 'partially_paid'], true), 422, 'Tagihan ini sudah tidak dapat dibayar.');
            abort_unless(in_array($enrollment->status, ['seat_held', 'payment_rejected', 'partially_paid'], true), 422, 'Kursi tidak lagi menerima pembayaran.');
            abort_if(! $enrollment->seat_expires_at?->isFuture(), 422, 'Batas pembayaran sudah berakhir.');
            abort_if(
                ! in_array($class->status, ['waiting_teacher', 'open', 'registration_closed', 'awaiting_verification'], true),
                422,
                'Kelas tidak lagi menerima pembayaran.'
            );
            abort_if(
                $enrollment->status === 'payment_rejected'
                    && $class->enrollments()->where('status', 'confirmed')->count() >= (int) $class->maximum_participants,
                422,
                'Kuota peserta terverifikasi sudah penuh sehingga bukti tidak dapat dikirim ulang.'
            );

            $walletReserved = (float) $lockedOrder->wallet_reserved_amount > 0
                ? (float) $lockedOrder->wallet_reserved_amount
                : ($useWallet
                ? $this->wallets->reserveForPayment(
                    $lockedOrder,
                    (int) $lockedOrder->user_id,
                    $walletExpectedAmount,
                    (int) $lockedOrder->user_id
                )
                : 0.0);
            $externalDue = round(max(0, (float) $lockedOrder->amount - $walletReserved - (float) $lockedOrder->external_received_amount), 2);
            abort_if($externalDue > 0 && ! $path, 422, 'Bukti pembayaran eksternal wajib diunggah untuk sisa tagihan.');
            abort_if($externalDue <= 0 && $path, 422, 'Tagihan ini sudah tertutup penuh oleh Saldo BimbelKu.');

            $snapshot = $lockedOrder->class_details_snapshot ?? [];
            unset($snapshot['payment_rejection_reason']);
            $lockedOrder->update([
                'status' => 'submitted',
                'payment_proof' => $path,
                'sender_name' => $details['sender_name'] ?? null,
                'bank_name' => $details['bank_name'] ?? null,
                'sender_account_number' => $details['sender_account_number'] ?? null,
                'payment_rejection_reason' => null,
                'class_details_snapshot' => $snapshot,
                'payment_submitted_at' => now(),
            ]);
            $enrollment->update([
                'status' => 'payment_submitted',
                'payment_submitted_at' => now(),
            ]);
        }, 3);

        if ($path) {
            User::query()->where('role', 'admin')->pluck('id')->each(fn (int $adminId) => Notification::create([
                'user_id' => $adminId,
                'title' => 'Bukti Kelas Kelompok masuk',
                'message' => "Tagihan {$order->order_id} menunggu verifikasi.",
                'type' => 'info',
                'target_url' => '/admin/finance?tab=payments',
            ]));
        }
    }

    public function verifyPayment(Order $order, string $status, string $reason, ?User $admin): array
    {
        $enrollmentId = (int) $order->cheap_class_enrollment_id;
        $classId = (int) CheapClassEnrollment::query()->whereKey($enrollmentId)->value('cheap_class_id');
        abort_if(! $enrollmentId || ! $classId, 422, 'Tagihan Kelas Kelompok tidak memiliki data peserta yang valid.');

        $result = DB::transaction(function () use ($order, $status, $reason, $admin, $enrollmentId, $classId) {
            // Samakan urutan lock dengan join/cancel agar verifikasi paralel aman.
            $class = CheapClass::query()->lockForUpdate()->findOrFail($classId);
            $enrollment = CheapClassEnrollment::query()
                ->whereKey($enrollmentId)
                ->where('cheap_class_id', $class->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->where('cheap_class_enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedOrder->status === 'submitted', 409, 'Pembayaran ini sudah diproses.');
            $mustRefundIfAccepted = $enrollment->status === 'cancellation_pending' || $class->status === 'cancelled';

            if ($status === 'rejected') {
                abort_if($reason === '', 422, 'Alasan penolakan pembayaran wajib diisi.');
                if ($mustRefundIfAccepted) {
                    $lockedOrder->update([
                        'status' => 'cancelled',
                        'payment_rejection_reason' => $reason,
                        'verified_at' => now(),
                        'verified_by' => $admin?->id,
                    ]);
                    $enrollment->update(['status' => 'cancelled']);
                    Notification::create([
                        'user_id' => $lockedOrder->user_id,
                        'title' => 'Pembatalan Kelas Kelompok selesai',
                        'message' => 'Bukti transfer tidak disetujui admin sehingga pembatalan selesai tanpa proses refund.',
                        'type' => 'info',
                        'target_url' => '/student/history',
                    ]);

                    return ['message' => 'Bukti ditolak dan pembatalan kelas diselesaikan tanpa refund.'];
                }
                $canResubmit = $enrollment->seat_expires_at?->isFuture()
                    && $class->registration_deadline->isFuture()
                    && in_array($class->status, ['open', 'registration_closed', 'awaiting_verification'], true)
                    && $class->enrollments()->where('status', 'confirmed')->count() < (int) $class->maximum_participants;
                $lockedOrder->update([
                    'status' => $canResubmit ? 'rejected' : 'expired',
                    'payment_rejection_reason' => $reason,
                    'verified_at' => now(),
                    'verified_by' => $admin?->id,
                ]);
                $enrollment->update(['status' => $canResubmit ? 'payment_rejected' : 'payment_expired']);
                if (! $canResubmit) {
                    $this->reopenRegistrationIfSeatAvailable($class);
                }
                Notification::create([
                    'user_id' => $lockedOrder->user_id,
                    'title' => $canResubmit ? 'Bukti pembayaran ditolak' : 'Bukti pembayaran tidak dapat dilanjutkan',
                    'message' => $canResubmit
                        ? 'Bukti perlu dikirim ulang sebelum batas kursi berakhir: '.$reason
                        : 'Bukti ditolak setelah masa pembayaran berakhir: '.$reason,
                    'type' => 'warning',
                    'target_url' => '/student/history',
                ]);

                return ['message' => $canResubmit ? 'Pembayaran ditolak. Murid masih dapat mengirim ulang.' : 'Pembayaran ditolak dan kursi berakhir.'];
            }

            $snapshot = $lockedOrder->class_details_snapshot ?? [];
            unset($snapshot['payment_rejection_reason']);
            $lockedOrder->update([
                'status' => 'paid',
                'payment_rejection_reason' => null,
                'class_details_snapshot' => $snapshot,
                'verified_at' => now(),
                'verified_by' => $admin?->id,
            ]);

            $firstSessionAt = $class->sessions()->min('starts_at');
            $startsAt = $firstSessionAt ? Carbon::parse($firstSessionAt) : $class->starts_at;
            $startedBeforeConfirmation = ! $mustRefundIfAccepted
                && $class->status !== 'confirmed'
                && $startsAt
                && $startsAt->lte(now());

            if ($startedBeforeConfirmation) {
                $this->queueRefund(
                    $lockedOrder,
                    $class,
                    'Pembayaran Kelas Kelompok belum selesai diverifikasi sebelum sesi pertama dimulai'
                );
                $enrollment->update(['status' => 'refund_pending']);
                Notification::create([
                    'user_id' => $lockedOrder->user_id,
                    'title' => 'Pembayaran terlambat diverifikasi',
                    'message' => 'Sesi pertama sudah dimulai sebelum kelas dikonfirmasi. Dana masuk antrean refund penuh.',
                    'type' => 'warning',
                    'target_url' => '/student/history',
                ]);

                return [
                    'message' => 'Pembayaran valid, tetapi sesi pertama sudah dimulai. Refund penuh masuk antrean admin.',
                    'class_id' => $class->id,
                    'cancel_after_verification' => true,
                ];
            }

            if ($mustRefundIfAccepted) {
                $this->queueRefund(
                    $lockedOrder,
                    $class,
                    'Kelas Kelompok dibatalkan sistem sebelum bukti pembayaran selesai diperiksa'
                );
                $enrollment->update(['status' => 'refund_pending']);
                Notification::create([
                    'user_id' => $lockedOrder->user_id,
                    'title' => 'Pembayaran masuk antrean refund',
                    'message' => 'Bukti transfer dinyatakan valid setelah kelas dibatalkan. Dana dikembalikan penuh melalui proses refund admin.',
                    'type' => 'warning',
                    'target_url' => '/student/history',
                ]);

                return [
                    'message' => 'Pembayaran valid pada kelas yang sudah dibatalkan. Refund penuh masuk antrean admin.',
                ];
            }

            // Pengaman lapis kedua: walaupun join() sudah mengunci kursi,
            // pembayaran anomali/legacy tidak boleh membuat peserta terkonfirmasi
            // melebihi maximum_participants.
            $confirmedCount = $class->enrollments()
                ->where('status', 'confirmed')
                ->count();
            if ($confirmedCount >= (int) $class->maximum_participants) {
                $this->queueRefund(
                    $lockedOrder,
                    $class,
                    'Pembayaran Kelas Kelompok melebihi kapasitas maksimum kelas'
                );
                $enrollment->update(['status' => 'refund_pending']);
                Notification::create([
                    'user_id' => $lockedOrder->user_id,
                    'title' => 'Pembayaran dikembalikan karena kuota penuh',
                    'message' => 'Transfer dinyatakan valid, tetapi kapasitas kelas sudah penuh. Dana masuk antrean refund penuh.',
                    'type' => 'warning',
                    'target_url' => '/student/history',
                ]);

                return [
                    'message' => 'Pembayaran valid, tetapi kuota maksimum sudah terisi. Refund penuh masuk antrean admin.',
                ];
            }

            $enrollment->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            Notification::create([
                'user_id' => $lockedOrder->user_id,
                'title' => 'Pembayaran Kelas Kelompok diterima',
                'message' => 'Pembayaran diterima. Kelas akan dikonfirmasi setelah pendaftaran ditutup dan kuota minimum terpenuhi.',
                'type' => 'success',
                'target_url' => '/student/kelas-murah',
            ]);

            // Kuota maksimum menutup pendaftaran lebih cepat. Ketika bukti
            // terakhir sudah diverifikasi, kelas tidak perlu menunggu sampai
            // tenggat awal untuk mengambil keputusan.
            $finalizeNow = ($class->status === 'registration_closed' || $class->registration_deadline->isPast())
                && ! $class->enrollments()
                    ->whereIn('status', ['seat_held', 'payment_rejected', 'partially_paid', 'payment_submitted'])
                    ->exists();

            return [
                'message' => 'Pembayaran Kelas Kelompok diverifikasi.',
                'class_id' => $class->id,
                'finalize_now' => $finalizeNow,
            ];
        }, 3);

        if (! empty($result['class_id'])) {
            $candidate = CheapClass::query()->find($result['class_id']);
            if ($candidate) {
                if (! empty($result['cancel_after_verification'])) {
                    $this->cancelClass(
                        $candidate,
                        'Kelas belum dikonfirmasi ketika sesi pertama dimulai.'
                    );
                } else {
                    $this->finalizeIfReady($candidate);
                }
            }
        }

        if (
            $order->fresh()?->status === 'refund_pending'
            && ($result['message'] ?? '') === 'Pembayaran Kelas Kelompok diverifikasi.'
        ) {
            $result['message'] = 'Pembayaran tercatat, tetapi kelas dibatalkan. Refund penuh masuk antrean admin.';
        }

        return $result;
    }

    public function cancelEnrollment(CheapClassEnrollment $enrollment, User $student): void
    {
        $classId = (int) $enrollment->cheap_class_id;

        DB::transaction(function () use ($enrollment, $student, $classId) {
            // Urutan lock konsisten: class -> enrollment -> order.
            $class = CheapClass::query()->lockForUpdate()->findOrFail($classId);
            $lockedEnrollment = CheapClassEnrollment::query()
                ->whereKey($enrollment->id)
                ->where('cheap_class_id', $class->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $lockedEnrollment->student_id === (int) $student->id, 403);
            $order = Order::query()
                ->where('cheap_class_enrollment_id', $lockedEnrollment->id)
                ->lockForUpdate()
                ->first();

            // Retry/double click pembatalan tidak boleh menghasilkan keadaan baru.
            if ($lockedEnrollment->status === 'cancelled' && (! $order || $order->status === 'cancelled')) {
                return;
            }

            abort_if(! $class->registration_deadline->isFuture(), 422, 'Pendaftaran sudah ditutup sehingga pembatalan tidak tersedia.');
            abort_unless(
                in_array($lockedEnrollment->status, ['seat_held', 'payment_rejected'], true)
                    && (! $order || in_array($order->status, ['pending', 'rejected'], true)),
                422,
                'Keikutsertaan tidak dapat dibatalkan setelah bukti pembayaran dikirim atau pembayaran diterima.'
            );

            $order?->update(['status' => 'cancelled']);
            $lockedEnrollment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->reopenRegistrationIfSeatAvailable($class);
        }, 3);
    }

    public function finalizeIfReady(CheapClass $cheapClass): void
    {
        DB::transaction(function () use ($cheapClass) {
            $class = CheapClass::query()->lockForUpdate()->find($cheapClass->id);
            if (! $class || in_array($class->status, ['confirmed', 'cancelled'], true)) {
                return;
            }
            if (
                $class->registration_deadline->isFuture()
                && in_array($class->status, ['waiting_teacher', 'open'], true)
            ) {
                return;
            }
            // Kuota dapat menutup pendaftaran lebih awal. Kursi yang masih berada
            // dalam masa pembayaran harus tetap diberi kesempatan mengirim bukti,
            // sehingga kelas belum boleh diputuskan pada tahap ini.
            if (
                $class->registration_deadline->isFuture()
                && $class->enrollments()
                    ->whereIn('status', ['seat_held', 'payment_rejected', 'partially_paid'])
                    ->where('seat_expires_at', '>', now())
                    ->exists()
            ) {
                return;
            }
            if ($class->registration_deadline->isPast() && $class->status === 'open') {
                $class->update(['status' => 'registration_closed']);
            }

            // Pada akhir pendaftaran, tutor wajib sudah tersedia. Bukti yang
            // sedang diperiksa tetap disimpan sebagai cancellation_pending agar
            // transfer valid dapat direfund tanpa menahan lifecycle paket.
            if (! $class->registration_deadline->isFuture()) {
                $this->replaceTeacherIfNeeded($class);
                $class->refresh();
                if (! $this->teacherCanTeach($class->teacher_id, $class)) {
                    $this->cancelClass($class, 'Tutor tidak tersedia sampai pendaftaran berakhir.');

                    return;
                }
            }

            $submitted = $class->enrollments()
                ->where('status', 'payment_submitted')
                ->exists();
            if ($submitted) {
                $class->update(['status' => 'awaiting_verification']);

                return;
            }

            $paid = $class->enrollments()->where('status', 'confirmed')->get();
            if ($paid->count() >= $class->minimum_participants) {
                if ($class->starts_at->lte(now())) {
                    $this->cancelClass($class, 'Kelas belum sempat dikonfirmasi sebelum sesi pertama dimulai.');

                    return;
                }
                $this->replaceTeacherIfNeeded($class);
                $class->refresh();
                if (! $this->teacherCanTeach($class->teacher_id, $class)) {
                    $this->cancelClass($class, 'Tutor pengganti tidak tersedia pada jadwal kelas.');

                    return;
                }
                $class->update(['status' => 'confirmed', 'confirmed_at' => now()]);
                foreach ($paid as $enrollment) {
                    Notification::create([
                        'user_id' => $enrollment->student_id,
                        'title' => 'Kelas Kelompok dikonfirmasi',
                        'message' => 'Kuota minimum terpenuhi. Tutor dan tautan Zoom kini dapat dibuka pada Kelas Kelompok.',
                        'type' => 'success',
                        'target_url' => '/student/kelas-murah',
                    ]);
                }
                Notification::create([
                    'user_id' => $class->teacher_id,
                    'title' => 'Kelas Kelompok dikonfirmasi',
                    'message' => "Kelas {$this->subjectSummary($class)} sudah dikonfirmasi. Lengkapi tautan Zoom sebelum sesi dimulai.",
                    'type' => 'success',
                    'target_url' => '/guru/kelas-murah',
                ]);

                return;
            }

            $this->cancelClass($class, 'Jumlah peserta terverifikasi belum mencapai kuota minimum.');
        }, 3);
    }

    public function cancelClass(CheapClass $class, string $reason): void
    {
        DB::transaction(function () use ($class, $reason) {
            $lockedClass = CheapClass::query()->lockForUpdate()->find($class->id);
            if (! $lockedClass || $lockedClass->status === 'cancelled') {
                return;
            }

            $enrollmentIds = $lockedClass->enrollments()->orderBy('id')->pluck('id');
            foreach ($enrollmentIds as $enrollmentId) {
                $enrollment = CheapClassEnrollment::query()
                    ->whereKey($enrollmentId)
                    ->where('cheap_class_id', $lockedClass->id)
                    ->lockForUpdate()
                    ->first();
                if (! $enrollment) {
                    continue;
                }
                $order = Order::query()
                    ->where('cheap_class_enrollment_id', $enrollment->id)
                    ->lockForUpdate()
                    ->first();

                if ($order?->status === 'paid') {
                    $this->queueRefund($order, $lockedClass, $reason);
                    $enrollment->update(['status' => 'refund_pending']);
                } elseif ($enrollment->status === 'payment_submitted' && $order?->status === 'submitted') {
                    // Kelas boleh dibatalkan, tetapi bukti transfer yang sudah masuk
                    // harus tetap diperiksa. Jika valid, dana direfund; jika tidak,
                    // pembatalan selesai tanpa membuat refund palsu.
                    $enrollment->update([
                        'status' => 'cancellation_pending',
                        'cancelled_at' => now(),
                    ]);
                } elseif (in_array($enrollment->status, ['seat_held', 'payment_rejected'], true)) {
                    $order?->update(['status' => 'cancelled']);
                    $enrollment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                }
                Notification::create([
                    'user_id' => $enrollment->student_id,
                    'title' => 'Kelas Kelompok dibatalkan',
                    'message' => $reason,
                    'type' => 'warning',
                    'target_url' => '/student/history',
                ]);
            }
            $lockedClass->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
                'meeting_link' => null,
            ]);
            $lockedClass->sessions()->where('status', '!=', 'cancelled')->update(['status' => 'cancelled']);
        }, 3);
    }

    public function replaceTeacherIfNeeded(CheapClass $cheapClass): ?User
    {
        return DB::transaction(function () use ($cheapClass) {
            $class = CheapClass::query()->lockForUpdate()->find($cheapClass->id);
            if (! $class || in_array($class->status, ['cancelled', 'completed'], true)) {
                return null;
            }
            if ($this->teacherCanTeach($class->teacher_id, $class)) {
                return $class->teacher;
            }

            $candidate = $this->pickBestEligibleTeacher($class, $class->teacher_id);
            if ($candidate) {
                $status = $class->status === 'waiting_teacher'
                    ? ($class->registration_deadline->isPast() ? 'registration_closed' : 'open')
                    : $class->status;
                $class->update([
                    'teacher_id' => $candidate->id,
                    'meeting_link' => null,
                    'status' => $status,
                ]);
                Notification::updateOrCreate(
                    ['unique_key' => "cheap-class-teacher-assigned:{$class->id}:{$candidate->id}"],
                    [
                        'user_id' => $candidate->id,
                        'title' => 'Paket Kelas Kelompok ditemukan',
                        'message' => "Jadwal {$this->subjectSummary($class)} cocok dengan seluruh sesi yang tersedia.",
                        'type' => 'success',
                        'target_url' => '/guru/kelas-murah',
                        'is_read' => false,
                    ]
                );

                return $candidate;
            }

            if ($class->status === 'confirmed') {
                $this->cancelClass($class, 'Tutor pengganti tidak tersedia pada seluruh jadwal sesi.');
            } elseif ($class->status === 'awaiting_verification') {
                $class->update(['teacher_id' => null, 'meeting_link' => null]);
            } elseif ($class->status !== 'cancelled') {
                $class->update(['teacher_id' => null, 'meeting_link' => null, 'status' => 'waiting_teacher']);
            }

            return null;
        }, 3);
    }

    public function eligibleTeachers(CheapClass $class, ?int $excludeTeacherId = null): Collection
    {
        $slots = $this->classSlots($class);
        if ($slots === []) {
            return new Collection;
        }

        return User::query()
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->when($excludeTeacherId, fn ($query) => $query->whereKeyNot($excludeTeacherId))
            ->whereHas('teacherProfile', fn ($profiles) => $profiles
                ->whereNotNull('verified_at')
                ->where('is_accepting_requests', true)
                ->where(function ($suspended) {
                    $suspended->whereNull('suspended_until')->orWhere('suspended_until', '<=', now());
                }))
            ->where(function ($teacherQuery) use ($class) {
                // Satu tutor harus mampu mengajar SEMUA mapel dalam paket, bukan
                // hanya mapel pertama yang disimpan pada kolom legacy.
                foreach ($this->classSubjects($class) as $requiredSubject) {
                    $teacherQuery->whereHas('teacherProfile.subjects', fn ($subjects) => $subjects
                        ->where(function ($subjectMatch) use ($requiredSubject) {
                            $subjectMatch->whereRaw('LOWER(name) = ?', [mb_strtolower($requiredSubject['subject_name'])]);
                            if (! empty($requiredSubject['curriculum_subject_id'])) {
                                $subjectMatch->orWhere('curriculum_subject_id', (int) $requiredSubject['curriculum_subject_id']);
                            }
                        })
                        ->where('is_active', true)
                        ->where('is_online', true)
                        ->where('is_group_active', true)
                        ->where(function ($levels) use ($class) {
                            $levels->whereNull('levels')->orWhereJsonContains('levels', $class->education_level);
                        }));
                }
            })
            ->whereHas('availabilities', fn ($availability) => $availability
                ->where('is_active', true))
            ->with(['availabilities' => fn ($query) => $query->where('is_active', true)])
            ->with('teacherProfile')
            ->withAvg('ratings as matching_rating_average', 'rating')
            ->withCount('ratings as matching_rating_count')
            ->withCount([
                'teacherCheapClasses as matching_active_group_class_count' => fn ($classes) => $classes
                    ->whereIn('status', ['open', 'registration_closed', 'awaiting_verification', 'confirmed']),
            ])
            ->orderBy('id')
            ->get()
            ->filter(function (User $teacher) use ($class, $slots) {
                foreach ($slots as $slot) {
                    $day = $this->dayName($slot['starts_at']);
                    $start = $slot['starts_at']->format('H:i');
                    $end = $slot['ends_at']->format('H:i');
                    $available = $teacher->availabilities->contains(
                        fn (TeacherAvailability $item) => $item->day === $day && $item->covers($start, $end)
                    );
                    if (! $available) {
                        return false;
                    }
                    if (TeacherAvailabilityException::query()
                        ->where('user_id', $teacher->id)
                        ->whereDate('start_date', '<=', $slot['starts_at']->toDateString())
                        ->whereDate('end_date', '>=', $slot['starts_at']->toDateString())
                        ->exists()) {
                        return false;
                    }
                    if ($this->teacherHasConflict($teacher->id, $class, $slot['starts_at'], $slot['ends_at'])) {
                        return false;
                    }
                }

                return true;
            })
            ->sort(function (User $a, User $b) {
                $score = static function (User $teacher): int {
                    $count = (int) ($teacher->matching_rating_count ?? 0);
                    $average = (float) ($teacher->matching_rating_average ?? 0);

                    return (int) round((($average * $count) + (4.0 * 5)) / ($count + 5) * 1000);
                };
                $activeA = (int) ($a->matching_active_group_class_count ?? 0);
                $activeB = (int) ($b->matching_active_group_class_count ?? 0);
                if ($activeA !== $activeB) {
                    return $activeA <=> $activeB;
                }

                $assignmentA = (int) ($a->teacherProfile?->assignment_count ?? 0);
                $assignmentB = (int) ($b->teacherProfile?->assignment_count ?? 0);
                if ($assignmentA !== $assignmentB) {
                    return $assignmentA <=> $assignmentB;
                }

                $ratingDifference = $score($b) <=> $score($a);

                return $ratingDifference !== 0
                    ? $ratingDifference
                    : $a->id <=> $b->id;
            })
            ->values();
    }

    private function pickBestEligibleTeacher(CheapClass $class, ?int $excludeTeacherId = null): ?User
    {
        $candidates = $this->eligibleTeachers($class, $excludeTeacherId);

        return $candidates->first();
    }

    public function teacherCanTeach(?int $teacherId, CheapClass $class): bool
    {
        if (! $teacherId) {
            return false;
        }

        return $this->eligibleTeachers($class)->contains(fn (User $teacher) => (int) $teacher->id === (int) $teacherId);
    }

    public function studentPayload(CheapClass $class, ?int $studentId = null): array
    {
        $class->loadMissing([
            'enrollments' => fn ($query) => $query
                ->when($studentId, fn ($items) => $items->where('student_id', $studentId))
                ->with('order'),
            'teacher.teacherProfile',
            'sessions',
        ]);
        $enrollment = $studentId ? $class->enrollments->first() : null;
        $activeCount = isset($class->active_participant_count)
            ? (int) $class->active_participant_count
            : $class->enrollments()->whereIn('status', self::ACTIVE_SEAT_STATUSES)->count();
        $confirmedCount = isset($class->confirmed_participant_count)
            ? (int) $class->confirmed_participant_count
            : $class->enrollments()->where('status', 'confirmed')->count();
        $pendingPaymentCount = isset($class->pending_payment_count)
            ? (int) $class->pending_payment_count
            : $class->enrollments()->whereIn('status', ['seat_held', 'payment_submitted', 'payment_rejected', 'partially_paid'])->count();
        $hasAccess = in_array($class->status, ['confirmed', 'completed'], true)
            && $enrollment?->status === 'confirmed'
            && $enrollment?->order?->status === 'paid';
        $canReuseEnrollment = ! $enrollment || (
            in_array($enrollment->status, ['cancelled', 'payment_expired'], true)
            && ! in_array($enrollment->order?->status, ['submitted', 'paid', 'refund_pending', 'refunded'], true)
        );
        $canCancel = $enrollment
            && $class->registration_deadline->isFuture()
            && in_array($enrollment->status, ['seat_held', 'payment_rejected'], true)
            && in_array($enrollment->order?->status, ['pending', 'rejected'], true);

        $classSubjects = $this->classSubjects($class);
        $studentSubjects = collect($classSubjects)->map(function (array $item) use ($hasAccess) {
            if ($hasAccess) {
                return $item;
            }

            // Materi inti tetap boleh terlihat pada offer/enrollment, tetapi
            // status dan catatan progress hanya untuk peserta confirmed + paid.
            return [
                'curriculum_subject_id' => $item['curriculum_subject_id'],
                'subject_name' => $item['subject_name'],
                'curriculum_chapter_id' => $item['curriculum_chapter_id'],
                'chapter' => $item['chapter'],
            ];
        })->values()->all();

        return [
            'id' => $class->id,
            'package_code' => $class->package_code,
            'subject_name' => $this->subjectSummary($class),
            'subjects' => $studentSubjects,
            'progress_summary' => $hasAccess ? $this->chapterProgressSummary($class) : null,
            'education_level' => $class->education_level,
            'grade' => $class->grade,
            'chapter' => $this->chapterSummary($class),
            'topic' => $class->topic,
            'starts_at' => $class->starts_at,
            'ends_at' => $class->ends_at,
            'session_count' => (int) $class->session_count,
            'sessions' => $class->sessions->map(fn (CheapClassSession $session) => [
                'id' => $session->id,
                'session_number' => $session->session_number,
                'starts_at' => $session->starts_at,
                'ends_at' => $session->ends_at,
                'status' => $session->status,
                'teacher_started_at' => $hasAccess ? $session->teacher_started_at : null,
                // Laporan tutor belum menjadi progress resmi sampai admin memverifikasi sesi.
                'progress_updates' => $hasAccess && $session->status === 'completed' ? ($session->progress_updates ?? []) : [],
                'progress_notes' => $hasAccess && $session->status === 'completed' ? $session->progress_notes : null,
                'progress_recorded_at' => $hasAccess && $session->status === 'completed' ? $session->progress_recorded_at : null,
                'attended_participants_count' => $hasAccess && $session->status === 'completed' ? $session->attended_participants_count : null,
                'report_submitted_at' => $hasAccess ? $session->report_submitted_at : null,
            ])->values(),
            'price_per_student' => (float) $class->price_per_student,
            'price_per_session' => (float) $class->price_per_session,
            'custom_price_per_student' => $class->custom_price_per_student !== null
                ? (float) $class->custom_price_per_student
                : null,
            'minimum_participants' => $class->minimum_participants,
            'maximum_participants' => $class->maximum_participants,
            // participant_count dipertahankan sebagai alias legacy untuk kursi terisi.
            // UI baru memakai field eksplisit supaya kursi yang ditahan tidak
            // disamakan dengan peserta yang pembayarannya sudah diverifikasi.
            'participant_count' => $activeCount,
            'occupied_seat_count' => $activeCount,
            'confirmed_participant_count' => $confirmedCount,
            'pending_payment_count' => $pendingPaymentCount,
            'registration_opens_at' => $class->registration_opens_at,
            'registration_deadline' => $class->registration_deadline,
            'status' => $class->status,
            'cancellation_reason' => $class->cancellation_reason,
            'can_join' => $canReuseEnrollment && $class->teacher_id && $class->status === 'open' && $class->registration_opens_at->lte(now()) && $class->registration_deadline->isFuture() && $activeCount < $class->maximum_participants,
            'enrollment' => $enrollment ? [
                'id' => $enrollment->id,
                'status' => $enrollment->status,
                'seat_expires_at' => $enrollment->seat_expires_at,
                'order_id' => $enrollment->order?->id,
                'order_number' => $enrollment->order?->order_id,
                'order_status' => $enrollment->order?->status,
                'can_cancel' => (bool) $canCancel,
            ] : null,
            'teacher' => $hasAccess ? [
                'name' => $class->teacher?->name,
                'photo' => PublicMedia::url($class->teacher?->teacherProfile?->photo),
            ] : null,
            'meeting_link' => $hasAccess && $class->status === 'confirmed' ? $class->meeting_link : null,
        ];
    }

    private function expireSeats(): int
    {
        $count = 0;
        CheapClassEnrollment::query()
            ->whereIn('status', ['seat_held', 'payment_rejected', 'partially_paid'])
            ->whereNotNull('seat_expires_at')
            ->where('seat_expires_at', '<=', now())
            ->select(['id', 'cheap_class_id'])
            ->chunkById(100, function ($enrollments) use (&$count) {
                foreach ($enrollments as $enrollment) {
                    $enrollmentId = (int) $enrollment->id;
                    $classId = (int) $enrollment->cheap_class_id;
                    DB::transaction(function () use ($enrollmentId, $classId, &$count) {
                        // Urutan lock disamakan dengan join, submit, verify, dan cancel:
                        // class -> enrollment -> order.
                        $class = CheapClass::query()->lockForUpdate()->find($classId);
                        $locked = CheapClassEnrollment::query()
                            ->whereKey($enrollmentId)
                            ->where('cheap_class_id', $classId)
                            ->lockForUpdate()
                            ->first();
                        if (
                            ! $class
                            || ! $locked
                            || ! in_array($locked->status, ['seat_held', 'payment_rejected'], true)
                            || $locked->seat_expires_at?->isFuture()
                        ) {
                            return;
                        }
                        $order = Order::query()
                            ->where('cheap_class_enrollment_id', $locked->id)
                            ->lockForUpdate()
                            ->first();
                        if ($order && ! in_array($order->status, ['pending', 'rejected', 'partially_paid'], true)) {
                            return;
                        }
                        if ($order?->status === 'partially_paid') {
                            $this->paymentReconciliation->returnExpiredPartialPayment($order);
                        }
                        $order?->update(['status' => 'expired']);
                        $locked->update(['status' => 'payment_expired']);
                        if (
                            $class->status === 'registration_closed'
                            && $class->registration_deadline->isFuture()
                            && $class->enrollments()
                                ->whereIn('status', self::ACTIVE_SEAT_STATUSES)
                                ->count() < $class->maximum_participants
                        ) {
                            $class->update(['status' => 'open']);
                        }
                        Notification::create([
                            'user_id' => $locked->student_id,
                            'title' => 'Kursi Kelas Kelompok dilepas',
                            'message' => 'Bukti pembayaran belum masuk sebelum batas satu jam berakhir.',
                            'type' => 'warning',
                            'target_url' => '/student/kelas-murah',
                        ]);
                        $count++;
                    }, 3);
                }
            });

        return $count;
    }

    private function queueRefund(Order $order, CheapClass $class, string $reason): void
    {
        Refund::firstOrCreate(
            ['order_id' => $order->id],
            [
                'user_id' => $order->user_id,
                'amount' => $order->amount,
                'reason' => $reason,
                'status' => 'pending',
            ]
        );
        $order->update(['status' => 'refund_pending']);
    }

    /** Snapshot menjamin paket terbit tidak ikut berubah ketika template diedit. */
    private function templateSnapshot(CheapClassTemplate $template): array
    {
        return [
            'template_code' => $template->template_code,
            'settings_version' => (int) $template->settings_version,
            'subjects' => $template->subjects,
            'subject_name' => $template->subject_name,
            'education_level' => $template->education_level,
            'grade' => $template->grade,
            'chapter' => $template->chapter,
            'topic' => $template->topic,
            'start_time' => $template->start_time,
            'duration_minutes' => (int) $template->duration_minutes,
            'session_count' => (int) $template->session_count,
            'recurrence_days' => $template->recurrence_days,
            'recurrence_enabled' => (bool) $template->recurrence_enabled,
            'price_per_student' => (float) $template->price_per_student,
            'price_per_session' => (float) $template->price_per_session,
            'custom_price_per_student' => $template->custom_price_per_student !== null
                ? (float) $template->custom_price_per_student
                : null,
            'minimum_participants' => (int) $template->minimum_participants,
            'maximum_participants' => (int) $template->maximum_participants,
            'registration_window_hours' => (int) $template->registration_window_hours,
            'registration_closes_before_minutes' => (int) $template->registration_closes_before_minutes,
            'payment_window_minutes' => (int) $template->payment_window_minutes,
        ];
    }

    private function reopenRegistrationIfSeatAvailable(CheapClass $class): void
    {
        $class->refresh();
        if (
            ! $class->registration_deadline->isFuture()
            || ! in_array($class->status, ['registration_closed', 'awaiting_verification'], true)
        ) {
            return;
        }

        $activeSeats = $class->enrollments()
            ->whereIn('status', self::ACTIVE_SEAT_STATUSES)
            ->count();
        if ($activeSeats < $class->maximum_participants) {
            $class->update(['status' => 'open']);
        }
    }

    private function teacherHasConflict(int $teacherId, CheapClass $class, Carbon $startsAt, Carbon $endsAt): bool
    {
        $bookingConflict = Booking::query()
            ->where('teacher_id', $teacherId)
            ->whereIn('status', ['teacher_selected', 'awaiting_payment', 'payment_collecting', 'payment_submitted', 'confirmed', 'in_progress', 'awaiting_student_approval', 'disputed', 'absence_review'])
            ->where('start_at', '<', $endsAt)
            ->where('end_at', '>', $startsAt)
            ->exists();

        return $bookingConflict || CheapClassSession::query()
            ->whereHas('cheapClass', fn ($classes) => $classes
                ->when($class->id, fn ($query) => $query->whereKeyNot($class->id))
                ->where('teacher_id', $teacherId)
                ->whereIn('status', self::ACTIVE_CLASS_STATUSES))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    private function studentHasScheduleConflict(int $studentId, CheapClass $class): bool
    {
        foreach ($this->classSlots($class) as $slot) {
            $bookingConflict = Booking::query()
                ->whereHas('participants', fn ($participants) => $participants->where('student_id', $studentId)->whereIn('status', ['awaiting_payment', 'payment_submitted', 'paid', 'confirmed']))
                ->whereIn('status', ['awaiting_payment', 'payment_collecting', 'payment_submitted', 'confirmed', 'in_progress', 'awaiting_student_approval', 'disputed'])
                ->where('start_at', '<', $slot['ends_at'])
                ->where('end_at', '>', $slot['starts_at'])
                ->exists();
            if ($bookingConflict) {
                return true;
            }

            $cheapClassConflict = CheapClassEnrollment::query()
                ->where('student_id', $studentId)
                ->whereIn('status', self::ACTIVE_SEAT_STATUSES)
                ->whereHas('cheapClass', fn ($classes) => $classes
                    ->whereKeyNot($class->id)
                    ->whereIn('status', self::ACTIVE_CLASS_STATUSES)
                    ->whereHas('sessions', fn ($sessions) => $sessions
                        ->where('starts_at', '<', $slot['ends_at'])
                        ->where('ends_at', '>', $slot['starts_at'])))
                ->exists();
            if ($cheapClassConflict) {
                return true;
            }
        }

        return false;
    }

    private function ensureSessions(
        CheapClass $class,
        int $sessionCount,
        int $durationMinutes,
        array $recurrenceDays = []
    ): void {
        $sessionCount = max(1, $sessionCount);
        $selectedDays = $this->normalizeRecurrenceDays(
            $recurrenceDays,
            $class->starts_at->dayOfWeekIso
        );
        $cursor = $class->starts_at->copy();
        foreach (range(1, $sessionCount) as $number) {
            if ($number > 1) {
                do {
                    $cursor->addDay();
                } while (! in_array($cursor->dayOfWeekIso, $selectedDays, true));
            }
            $startsAt = $cursor->copy();
            CheapClassSession::firstOrCreate(
                ['cheap_class_id' => $class->id, 'session_number' => $number],
                [
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addMinutes($durationMinutes),
                    'status' => 'scheduled',
                ]
            );
        }
        $class->unsetRelation('sessions');
    }

    /** @return array<int, int> */
    private function normalizeRecurrenceDays(mixed $days, int $firstDay): array
    {
        $normalized = collect(is_array($days) ? $days : [])
            ->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($normalized === []) {
            $normalized = [$firstDay];
        }
        abort_if(count($normalized) > 4, 422, 'Maksimal empat hari belajar dapat dipilih dalam satu minggu.');
        abort_unless(
            in_array($firstDay, $normalized, true),
            422,
            'Tanggal sesi pertama harus sesuai dengan salah satu hari belajar yang dipilih.'
        );

        return $normalized;
    }

    private function synchronizeSessionStatuses(): int
    {
        // Waktu selesai hanya menutup kegiatan belajar, bukan memfinalkan sesi.
        // Tutor masih wajib mengirim laporan dan admin harus memverifikasinya.
        // Update dibuat kondisional per-sesi supaya notifikasi hanya lahir pada
        // transisi pertama dan tidak kembali unread setiap refresh lifecycle.
        $reportRequired = 0;
        CheapClassSession::query()
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->where('ends_at', '<=', now())
            ->whereHas('cheapClass', fn ($classes) => $classes->where('status', 'confirmed'))
            ->with('cheapClass')
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use (&$reportRequired) {
                foreach ($sessions as $session) {
                    $updated = CheapClassSession::query()
                        ->whereKey($session->id)
                        ->whereIn('status', ['scheduled', 'in_progress'])
                        ->update(['status' => 'report_required']);
                    if ($updated !== 1) {
                        continue;
                    }

                    $reportRequired++;
                    $class = $session->cheapClass;
                    if (! $class?->teacher_id) {
                        continue;
                    }

                    Notification::updateOrCreate(
                        ['unique_key' => "cheap-class-session-report-required:{$session->id}:teacher:{$class->teacher_id}"],
                        [
                            'user_id' => $class->teacher_id,
                            'title' => 'Kelas selesai · laporan sesi belum diisi',
                            'message' => "Sesi {$session->session_number} {$this->subjectSummary($class)} sudah berakhir. Isi kehadiran, progress bab, dan catatan sesi agar admin dapat memverifikasi.",
                            'type' => 'warning',
                            'target_url' => '/guru/kelas-murah',
                            'is_read' => false,
                        ]
                    );
                }
            });

        $cancelled = CheapClassSession::query()
            ->where('status', '!=', 'cancelled')
            ->whereHas('cheapClass', fn ($classes) => $classes->where('status', 'cancelled'))
            ->update(['status' => 'cancelled']);

        return $reportRequired + $cancelled;
    }

    private function completeFinishedClasses(): int
    {
        $completed = 0;
        CheapClass::query()
            ->where('status', 'confirmed')
            ->whereHas('sessions')
            ->whereDoesntHave('sessions', fn ($sessions) => $sessions->where('status', '!=', 'completed'))
            ->orderBy('id')
            ->chunkById(100, function ($classes) use (&$completed) {
                foreach ($classes as $class) {
                    $class->update(['status' => 'completed']);
                    $completed++;
                }
            });

        return $completed;
    }

    /** @return array<int, array{starts_at:Carbon,ends_at:Carbon}> */
    private function classSlots(CheapClass $class): array
    {
        if ($class->exists) {
            $sessions = $class->sessions()
                ->where('ends_at', '>', now())
                ->orderBy('session_number')
                ->get();
            if ($sessions->isNotEmpty()) {
                return $sessions->map(fn (CheapClassSession $session) => [
                    'starts_at' => $session->starts_at->copy(),
                    'ends_at' => $session->ends_at->copy(),
                ])->all();
            }
        }

        return $class->ends_at->isFuture()
            ? [[
                'starts_at' => $class->starts_at->copy(),
                'ends_at' => $class->ends_at->copy(),
            ]]
            : [];
    }

    private function dayName(Carbon $date): string
    {
        return [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'][$date->dayOfWeekIso];
    }

    /**
     * Kelas Kelompok memakai progres bersama per bab. Field progres disimpan di
     * snapshot subjects milik occurrence agar tidak menambah tabel baru dan
     * tidak mengubah katalog/template yang dipakai occurrence lain.
     *
     * @return array<int, array{curriculum_subject_id:?int,subject_name:string,curriculum_chapter_id:?int,chapter:string,progress_status:string,needs_review:bool,progress_notes:?string,progress_updated_at:?string}>
     */
    public function classSubjects(CheapClass $class): array
    {
        $subjects = is_array($class->subjects) ? $class->subjects : [];
        $normalize = fn (array $item) => [
            'curriculum_subject_id' => isset($item['curriculum_subject_id']) ? (int) $item['curriculum_subject_id'] : null,
            'subject_name' => trim((string) ($item['subject_name'] ?? '')),
            'curriculum_chapter_id' => isset($item['curriculum_chapter_id']) ? (int) $item['curriculum_chapter_id'] : null,
            'chapter' => trim((string) ($item['chapter'] ?? '')),
            'progress_status' => in_array(($item['progress_status'] ?? null), ['not_started', 'in_progress', 'completed'], true)
                ? (string) $item['progress_status']
                : 'not_started',
            'needs_review' => (bool) ($item['needs_review'] ?? false),
            'progress_notes' => filled($item['progress_notes'] ?? null) ? trim((string) $item['progress_notes']) : null,
            'progress_updated_at' => filled($item['progress_updated_at'] ?? null) ? (string) $item['progress_updated_at'] : null,
        ];

        $normalized = collect($subjects)
            ->filter(fn ($item) => is_array($item) && ! empty($item['subject_name']))
            ->take(4)
            ->map(fn ($item) => $normalize($item))
            ->values()
            ->all();

        if ($normalized !== []) {
            return $normalized;
        }

        return [$normalize([
            'curriculum_subject_id' => $class->curriculum_subject_id,
            'subject_name' => $class->subject_name,
            'curriculum_chapter_id' => $class->curriculum_chapter_id,
            'chapter' => $class->chapter,
        ])];
    }

    /** @return array{total_chapters:int,completed_chapters:int,in_progress_chapters:int,progress_percent:int} */
    public function chapterProgressSummary(CheapClass $class): array
    {
        $subjects = collect($this->classSubjects($class));
        $total = $subjects->count();
        $completed = $subjects->where('progress_status', 'completed')->count();
        $inProgress = $subjects->where('progress_status', 'in_progress')->count();

        return [
            'total_chapters' => $total,
            'completed_chapters' => $completed,
            'in_progress_chapters' => $inProgress,
            'progress_percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
        ];
    }

    public function subjectSummary(CheapClass $class): string
    {
        return collect($this->classSubjects($class))->pluck('subject_name')->filter()->join(' + ');
    }

    public function chapterSummary(CheapClass $class): string
    {
        return collect($this->classSubjects($class))
            ->map(fn ($item) => $item['chapter'] !== '' ? $item['subject_name'].': '.$item['chapter'] : $item['subject_name'])
            ->join(' · ');
    }

    private function orderSnapshot(CheapClass $class, Carbon $paymentDueAt): array
    {
        return [
            'flow_version' => 4,
            'kind' => 'cheap_class',
            'cheap_class_id' => (int) $class->id,
            'package_code' => $class->package_code,
            'template_code' => $class->template?->template_code,
            'occurrence_week_start' => $class->occurrence_week_start?->toDateString(),
            'generation_source' => $class->generation_source,
            'subject' => $this->subjectSummary($class),
            'subjects' => collect($this->classSubjects($class))->map(fn ($item) => [
                'curriculum_subject_id' => $item['curriculum_subject_id'],
                'subject_name' => $item['subject_name'],
                'curriculum_chapter_id' => $item['curriculum_chapter_id'],
                'chapter' => $item['chapter'],
            ])->values()->all(),
            'chapter' => $this->chapterSummary($class),
            'type' => 'Kelas Kelompok',
            'method' => 'online',
            'teacher_name' => 'Tutor diumumkan setelah kelas dikonfirmasi',
            'start_at' => $class->starts_at->toIso8601String(),
            'duration_hours' => (int) max(1, $class->starts_at->diffInMinutes($class->ends_at) / 60),
            'session_count' => (int) $class->session_count,
            'total_learning_hours' => (int) $class->session_count,
            'payment_scope' => 'package',
            'payment_frequency' => 'once',
            'price_per_session' => (float) $class->price_per_session,
            'custom_price_per_student' => $class->custom_price_per_student !== null
                ? (float) $class->custom_price_per_student
                : null,
            'payment_due_at' => $paymentDueAt->toIso8601String(),
        ];
    }
}
