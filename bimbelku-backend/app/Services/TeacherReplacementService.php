<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRequest;
use App\Models\LearningPackage;
use App\Models\LearningTimeSlot;
use App\Models\MatchingOperationLog;
use App\Models\Notification;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\TeacherOffer;
use App\Models\TeacherProfile;
use App\Models\TeacherReplacementRequest;
use App\Models\TeacherReplacementSession;
use App\Models\User;
use App\Support\TeacherReplacementState;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherReplacementService
{
    public const OPEN_STATUSES = TeacherReplacementRequest::OPEN_STATUSES;

    public function __construct(
        private readonly TeacherMatchingService $matchingService,
    ) {}

    public function submit(User $student, LearningPackage $package, PackageSubject $subject, array $data): TeacherReplacementRequest
    {
        return DB::transaction(function () use ($student, $package, $subject, $data) {
            $lockedPackage = LearningPackage::query()->lockForUpdate()->findOrFail($package->id);
            $lockedSubject = PackageSubject::query()
                ->with(['sessions.booking', 'assignedTeacher'])
                ->lockForUpdate()->findOrFail($subject->id);
            abort_unless((int) $lockedPackage->student_id === (int) $student->id, 403);
            abort_unless((int) $lockedSubject->learning_package_id === (int) $lockedPackage->id, 404);
            abort_unless($lockedPackage->status === 'active' && $lockedSubject->status === 'active', 422, 'Penggantian hanya tersedia untuk mapel paket yang aktif.');
            abort_unless($lockedSubject->assigned_teacher_id, 422, 'Mapel ini belum memiliki tutor aktif.');
            abort_if(TeacherReplacementRequest::query()
                ->where('package_subject_id', $lockedSubject->id)
                ->whereIn('status', TeacherReplacementRequest::OPEN_STATUSES)->exists(), 422, 'Pengajuan penggantian tutor pada mapel ini masih diproses.');

            $blocked = ['in_progress', 'awaiting_student_approval', 'disputed', 'absence_review', 'admin_review_required'];
            abort_if($lockedSubject->sessions->contains(fn ($session) => in_array($session->booking?->status, $blocked, true)), 422, 'Selesaikan sesi atau kasus yang sedang berjalan terlebih dahulu.');
            $eligible = $lockedSubject->sessions
                ->filter(fn ($session) => $session->booking && $session->booking->status === 'confirmed' && $session->scheduled_end_at->isFuture())
                ->values();
            abort_if($eligible->isEmpty(), 422, 'Tidak ada sesi mendatang yang dapat dialihkan.');
            abort_if($eligible->contains(fn ($session) => $session->booking->class_type !== 'private'), 422, 'Penggantian guru hanya tersedia untuk Paket Belajar privat.');

            $replacement = TeacherReplacementRequest::create([
                'replacement_code' => 'GGR-'.Str::upper((string) Str::ulid()),
                'learning_package_id' => $lockedPackage->id,
                'package_subject_id' => $lockedSubject->id,
                'student_id' => $student->id,
                'old_teacher_id' => $lockedSubject->assigned_teacher_id,
                'reason_code' => $data['reason_code'],
                'reason_detail' => trim($data['reason_detail']),
                'evidence_path' => $data['evidence_path'] ?? null,
                'status' => 'pending_review',
            ]);
            foreach ($eligible as $session) {
                TeacherReplacementSession::create([
                    'teacher_replacement_request_id' => $replacement->id,
                    'package_session_id' => $session->id,
                    'old_booking_id' => $session->booking_id,
                    'status' => 'selected',
                ]);
            }
            User::query()->where('role', 'admin')->where('status', 'active')->pluck('id')->each(
                fn (int $adminId) => Notification::updateOrCreate(
                    ['unique_key' => "replacement-request:{$replacement->id}:{$adminId}"],
                    [
                        'user_id' => $adminId,
                        'title' => 'Pengajuan ganti guru',
                        'message' => "{$student->name} mengajukan penggantian tutor {$lockedSubject->subject_name}.",
                        'type' => 'warning',
                        'target_url' => '/admin/cases',
                        'is_read' => false,
                    ]
                )
            );

            return $replacement->fresh(['sessions', 'oldTeacher']);
        }, 3);
    }

    public function approve(TeacherReplacementRequest $replacement, User $admin, string $notes): array
    {
        return DB::transaction(function () use ($replacement, $admin, $notes) {
            $locked = TeacherReplacementRequest::query()->with(['package.student', 'subject'])
                ->lockForUpdate()->findOrFail($replacement->id);
            abort_unless($locked->status === 'pending_review', 422, 'Pengajuan ini sudah diproses.');
            $rows = TeacherReplacementSession::query()
                ->where('teacher_replacement_request_id', $locked->id)
                ->lockForUpdate()
                ->get();
            abort_if($rows->isEmpty(), 422, 'Snapshot sesi penggantian tidak tersedia.');
            PackageSession::query()->whereIn('id', $rows->pluck('package_session_id'))->lockForUpdate()->get();
            Booking::query()->whereIn('id', $rows->pluck('old_booking_id'))->lockForUpdate()->get();
            $rows->load(['packageSession', 'oldBooking.bookingRequest']);

            foreach ($rows as $row) {
                $booking = Booking::query()->lockForUpdate()->findOrFail($row->old_booking_id);
                abort_unless($booking->status === 'confirmed', 422, 'Status salah satu sesi berubah. Muat ulang kasus.');
                $booking->update(['status' => 'teacher_replaced', 'payout_status' => 'cancelled']);
                $row->packageSession->update(['booking_id' => null, 'status' => 'replacement_pending']);
                $row->update(['status' => 'matching']);
            }

            $first = $rows->sortBy(fn ($row) => $row->packageSession->scheduled_start_at)->first();
            $oldRequest = $first->oldBooking->bookingRequest;
            $firstSession = $first->packageSession;
            $needsReschedule = $rows->contains(fn ($row) => $row->packageSession->scheduled_start_at->lt(now()->addHours(24)));
            $request = BookingRequest::create([
                'student_id' => $locked->student_id,
                'package_subject_id' => $locked->package_subject_id,
                'teacher_replacement_request_id' => $locked->id,
                'subject_name' => $oldRequest->subject_name,
                'curriculum_subject_id' => $oldRequest->curriculum_subject_id,
                'education_level' => $oldRequest->education_level,
                'grade' => $oldRequest->grade,
                'learning_mode' => $oldRequest->learning_mode,
                'class_type' => 'private',
                'scheduled_date' => $firstSession->scheduled_start_at->toDateString(),
                'start_time' => $firstSession->scheduled_start_at->format('H:i:s'),
                'end_time' => $firstSession->scheduled_end_at->format('H:i:s'),
                'duration_hours' => $oldRequest->duration_hours,
                'address' => $oldRequest->learning_mode === 'offline' ? $oldRequest->address : null,
                'maps_link' => $oldRequest->learning_mode === 'offline' ? $oldRequest->maps_link : null,
                'latitude' => $oldRequest->learning_mode === 'offline' ? $oldRequest->latitude : null,
                'longitude' => $oldRequest->learning_mode === 'offline' ? $oldRequest->longitude : null,
                'status' => $needsReschedule ? 'no_teacher' : 'matching',
                'hourly_rate' => $oldRequest->hourly_rate,
                'total_amount' => $oldRequest->total_amount,
                'search_radius_km' => $oldRequest->learning_mode === 'offline' ? 3 : null,
                'search_started_at' => now(),
                'search_expires_at' => now()->addHours($this->matchingService->maximumSearchHours()),
            ]);
            $locked->subject->update(['assigned_teacher_id' => null]);
            $locked->transitionTo($needsReschedule ? TeacherReplacementState::NO_TEACHER : TeacherReplacementState::MATCHING, [
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'search_started_at' => now(),
            ]);
            Notification::updateOrCreate(
                ['unique_key' => "replacement-approved:{$locked->id}:{$locked->student_id}"],
                [
                    'user_id' => $locked->student_id,
                    'title' => 'Penggantian guru disetujui',
                    'message' => $needsReschedule
                        ? 'Sesi tersisa dibekukan. Ubah jadwal karena sesi terdekat kurang dari 24 jam.'
                        : 'Sesi tersisa dibekukan dan pencarian guru pengganti dimulai.',
                    'type' => 'info',
                    'target_url' => '/student/packages',
                    'is_read' => false,
                ]
            );

            return ['replacement' => $locked->fresh(), 'booking_request' => $request, 'needs_reschedule' => $needsReschedule];
        }, 3);
    }

    public function reject(TeacherReplacementRequest $replacement, User $admin, string $notes): TeacherReplacementRequest
    {
        return DB::transaction(function () use ($replacement, $admin, $notes) {
            $locked = TeacherReplacementRequest::query()->lockForUpdate()->findOrFail($replacement->id);
            abort_unless($locked->status === 'pending_review', 422, 'Pengajuan ini sudah diproses.');
            $locked->transitionTo(TeacherReplacementState::REJECTED, [
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);
            $locked->sessions()->update(['status' => 'cancelled']);
            Notification::updateOrCreate(
                ['unique_key' => "replacement-rejected:{$locked->id}:{$locked->student_id}"],
                [
                    'user_id' => $locked->student_id,
                    'title' => 'Pengajuan ganti guru diperiksa',
                    'message' => 'Pengajuan tidak disetujui. Lihat catatan admin di detail paket.',
                    'type' => 'warning',
                    'target_url' => '/student/my-classes',
                    'is_read' => false,
                ]
            );

            return $locked->fresh();
        }, 3);
    }

    public function cancelPending(TeacherReplacementRequest $replacement, User $student): TeacherReplacementRequest
    {
        return DB::transaction(function () use ($replacement, $student) {
            $locked = TeacherReplacementRequest::query()->lockForUpdate()->findOrFail($replacement->id);
            abort_unless((int) $locked->student_id === (int) $student->id, 403);
            abort_unless($locked->status === TeacherReplacementState::PENDING_REVIEW, 422, 'Pengajuan hanya dapat dibatalkan sebelum diperiksa admin.');
            $locked->sessions()->lockForUpdate()->get();
            $locked->sessions()->update(['status' => 'cancelled']);
            $locked->transitionTo(TeacherReplacementState::CANCELLED);

            return $locked->fresh(['sessions']);
        }, 3);
    }

    public function retry(TeacherReplacementRequest $replacement, User $student): array
    {
        return DB::transaction(function () use ($replacement, $student) {
            $locked = TeacherReplacementRequest::query()->lockForUpdate()->findOrFail($replacement->id);
            abort_unless((int) $locked->student_id === (int) $student->id, 403);
            abort_unless($locked->status === TeacherReplacementState::NO_TEACHER, 422, 'Pencarian hanya dapat diulang ketika guru belum tersedia.');
            $request = $locked->matchingRequest()->lockForUpdate()->firstOrFail();
            $request->offers()->where('status', 'pending')->lockForUpdate()->get()
                ->each->update(['status' => 'cancelled', 'responded_at' => now()]);
            $request->update([
                'status' => 'matching',
                'matched_teacher_id' => null,
                'teacher_response_deadline' => null,
                'next_matching_at' => null,
                'search_started_at' => now(),
                'search_expires_at' => now()->addHours($this->matchingService->maximumSearchHours()),
            ]);
            $locked->sessions()->lockForUpdate()->get()->each->update(['status' => 'matching']);
            $locked->transitionTo(TeacherReplacementState::MATCHING, ['search_started_at' => now()]);
            MatchingOperationLog::create([
                'booking_request_id' => $request->id,
                'actor_id' => $student->id,
                'action' => 'search_restarted',
                'reason' => 'Murid mencoba kembali pencarian guru pengganti.',
                'before_state' => ['status' => 'no_teacher'],
                'after_state' => ['status' => 'matching'],
                'metadata' => ['source' => 'teacher_replacement', 'replacement_id' => $locked->id],
            ]);

            return ['replacement' => $locked->fresh(), 'booking_request' => $request];
        }, 3);
    }

    public function reschedule(TeacherReplacementRequest $replacement, User $student, array $scheduleValues): array
    {
        return DB::transaction(function () use ($replacement, $student, $scheduleValues) {
            $locked = TeacherReplacementRequest::query()->lockForUpdate()->findOrFail($replacement->id);
            abort_unless((int) $locked->student_id === (int) $student->id, 403);
            abort_unless($locked->status === TeacherReplacementState::NO_TEACHER, 422, 'Jadwal hanya dapat diubah ketika guru belum tersedia.');
            $request = $locked->matchingRequest()->lockForUpdate()->firstOrFail();
            $rows = $locked->sessions()->lockForUpdate()->orderBy('id')->get();
            abort_unless(count($scheduleValues) === $rows->count(), 422, 'Jumlah jadwal harus sama dengan jumlah sesi replacement.');
            $duration = max(1, (int) $request->duration_hours);
            $activeTimes = LearningTimeSlot::query()->where('is_active', true)->pluck('start_time')
                ->map(fn ($time) => substr((string) $time, 0, 5))->all();
            $starts = collect($scheduleValues)->map(function (string $value) use ($duration, $activeTimes) {
                $start = Carbon::parse($value, config('app.timezone'))->seconds(0);
                abort_if($start->lt(now()->addHours(24)), 422, 'Jadwal baru paling cepat 24 jam dari sekarang.');
                abort_unless($start->format('i') === '00' && in_array($start->format('H:i'), $activeTimes, true), 422, 'Pilih jam belajar aktif dengan menit 00.');
                abort_if(((int) $start->format('H')) + $duration > 23, 422, 'Jam mulai terlalu malam untuk durasi sesi.');

                return $start;
            })->sortBy->timestamp->values();
            abort_unless($starts->unique(fn (Carbon $date) => $date->timestamp)->count() === $starts->count(), 422, 'Jadwal replacement tidak boleh duplikat.');
            for ($index = 0; $index < $starts->count() - 1; $index++) {
                abort_if($starts[$index]->copy()->addHours($duration)->gt($starts[$index + 1]), 422, 'Jadwal replacement tidak boleh saling bertabrakan.');
            }
            $sessionIds = $rows->pluck('package_session_id');
            foreach ($starts as $start) {
                $end = $start->copy()->addHours($duration);
                abort_if(PackageSession::query()
                    ->whereNotIn('id', $sessionIds)
                    ->whereHas('subject.package', fn ($query) => $query
                        ->where('student_id', $student->id)
                        ->whereNotIn('status', ['cancelled', 'payment_expired', 'completed', 'refund_pending', 'refunded']))
                    ->whereNotIn('status', ['cancelled', 'payment_expired', 'completed', 'refund_pending', 'refunded'])
                    ->where('scheduled_start_at', '<', $end)
                    ->where('scheduled_end_at', '>', $start)
                    ->exists(), 422, 'Jadwal baru bertabrakan dengan sesi belajar lain.');
            }
            PackageSession::query()->whereIn('id', $sessionIds)->lockForUpdate()->get();
            foreach ($rows->values() as $index => $row) {
                $start = $starts[$index];
                $row->packageSession()->update([
                    'scheduled_start_at' => $start,
                    'scheduled_end_at' => $start->copy()->addHours($duration),
                    'status' => 'replacement_pending',
                ]);
                $row->update(['status' => 'matching']);
            }
            $first = $starts->first();
            $request->offers()->where('status', 'pending')->update(['status' => 'cancelled', 'responded_at' => now()]);
            $request->update([
                'scheduled_date' => $first->toDateString(),
                'start_time' => $first->format('H:i:s'),
                'end_time' => $first->copy()->addHours($duration)->format('H:i:s'),
                'status' => 'matching',
                'matched_teacher_id' => null,
                'teacher_response_deadline' => null,
                'next_matching_at' => null,
                'search_started_at' => now(),
                'search_expires_at' => now()->addHours($this->matchingService->maximumSearchHours()),
            ]);
            $locked->transitionTo(TeacherReplacementState::MATCHING, ['search_started_at' => now()]);
            MatchingOperationLog::create([
                'booking_request_id' => $request->id,
                'actor_id' => $student->id,
                'action' => 'schedule_changed',
                'reason' => 'Murid mengubah jadwal sesi penggantian guru.',
                'before_state' => ['replacement_id' => $locked->id],
                'after_state' => ['schedules' => $starts->map->toIso8601String()->all()],
                'metadata' => ['source' => 'teacher_replacement'],
            ]);

            return ['replacement' => $locked->fresh(), 'booking_request' => $request];
        }, 3);
    }

    public function acceptOffer(TeacherOffer $teacherOffer, User $teacher): array
    {
        $replacementId = BookingRequest::query()
            ->whereKey($teacherOffer->booking_request_id)
            ->value('teacher_replacement_request_id');
        abort_unless($replacementId, 422, 'Penawaran ini bukan proses penggantian guru.');

        return DB::transaction(function () use ($teacherOffer, $teacher, $replacementId) {
            $replacement = TeacherReplacementRequest::query()->with(['subject', 'package'])
                ->lockForUpdate()->findOrFail($replacementId);
            $request = BookingRequest::query()
                ->lockForUpdate()
                ->findOrFail($teacherOffer->booking_request_id);
            abort_unless((int) $request->teacher_replacement_request_id === (int) $replacement->id, 422, 'Penawaran tidak lagi terhubung ke penggantian guru.');
            $offer = TeacherOffer::query()->lockForUpdate()->findOrFail($teacherOffer->id);
            abort_unless($offer->teacher_id === $teacher->id, 403);
            abort_unless($offer->status === 'pending' && $offer->expires_at->isFuture(), 422, 'Penawaran ini sudah diproses atau kedaluwarsa.');
            abort_unless(in_array($replacement->status, ['matching', 'teacher_pending', 'no_teacher'], true), 422, 'Penggantian guru sudah ditutup.');
            abort_if((int) $replacement->old_teacher_id === (int) $teacher->id, 422, 'Tutor lama tidak dapat menerima penggantian ini.');
            $replacementRows = TeacherReplacementSession::query()
                ->where('teacher_replacement_request_id', $replacement->id)
                ->lockForUpdate()
                ->get();
            abort_if($replacementRows->isEmpty(), 422, 'Snapshot sesi penggantian tidak tersedia.');
            PackageSession::query()->whereIn('id', $replacementRows->pluck('package_session_id'))->lockForUpdate()->get();
            Booking::query()->whereIn('id', $replacementRows->pluck('old_booking_id'))->lockForUpdate()->get();
            $replacementRows->load(['oldBooking', 'packageSession']);
            $replacement->setRelation('sessions', $replacementRows);
            $profile = TeacherProfile::query()->where('user_id', $teacher->id)->lockForUpdate()->first();
            abort_unless($teacher->status === 'active' && $profile?->verified_at && $profile?->is_accepting_requests, 422, 'Profil tutor sedang tidak dapat menerima permintaan.');
            $profile->setRelation('user', $teacher);
            abort_if($error = $this->matchingService->compatibilityError($profile, $request), 422, $error);

            $offer->update(['status' => 'accepted', 'responded_at' => now()]);
            $request->offers()->whereKeyNot($offer->id)->where('status', 'pending')->update(['status' => 'cancelled', 'responded_at' => now()]);
            $order = $replacement->package->orders()->where('status', 'paid')->latest()->lockForUpdate()->firstOrFail();
            foreach ($replacement->sessions->sortBy(fn ($row) => $row->packageSession->sequence)->values() as $index => $row) {
                $old = $row->oldBooking;
                $session = $row->packageSession;
                $sessionRequest = $index === 0 ? $request : BookingRequest::create([
                    ...$request->only([
                        'student_id', 'package_subject_id', 'teacher_replacement_request_id', 'subject_name',
                        'curriculum_subject_id', 'education_level', 'grade', 'learning_mode', 'class_type',
                        'duration_hours', 'address', 'maps_link', 'latitude', 'longitude', 'hourly_rate', 'total_amount',
                    ]),
                    'scheduled_date' => $session->scheduled_start_at->toDateString(),
                    'start_time' => $session->scheduled_start_at->format('H:i:s'),
                    'end_time' => $session->scheduled_end_at->format('H:i:s'),
                    'status' => 'confirmed',
                    'matched_teacher_id' => $teacher->id,
                ]);
                $sessionRequest->update(['status' => 'confirmed', 'matched_teacher_id' => $teacher->id, 'teacher_response_deadline' => null, 'next_matching_at' => null]);
                $booking = Booking::create([
                    'booking_request_id' => $sessionRequest->id,
                    'replacement_of_booking_id' => $old->id,
                    'student_id' => $replacement->student_id,
                    'teacher_id' => $teacher->id,
                    'order_id' => $order->id,
                    'start_at' => $session->scheduled_start_at,
                    'end_at' => $session->scheduled_end_at,
                    'duration_hours' => $old->duration_hours,
                    'learning_mode' => $old->learning_mode,
                    'class_type' => 'private',
                    'hourly_rate' => $old->hourly_rate,
                    'total_amount' => $old->total_amount,
                    'commission_percent' => $old->commission_percent,
                    'gross_amount' => $old->gross_amount,
                    'teacher_net_amount' => $old->teacher_net_amount,
                    'status' => 'confirmed',
                    'session_flow_version' => $old->session_flow_version,
                    'address' => $old->learning_mode === 'offline' ? $old->address : null,
                    'maps_link' => $old->learning_mode === 'offline' ? $old->maps_link : null,
                    'payout_status' => 'locked',
                ]);
                $sessionRequest->update(['booking_id' => $booking->id]);
                BookingParticipant::create([
                    'booking_id' => $booking->id,
                    'student_id' => $replacement->student_id,
                    'booking_request_id' => $sessionRequest->id,
                    'order_id' => $order->id,
                    'amount' => $old->total_amount,
                    'status' => 'paid',
                ]);
                $session->update(['booking_id' => $booking->id, 'status' => 'scheduled']);
                $row->update(['new_booking_id' => $booking->id, 'status' => 'rematched']);
            }
            $replacement->subject->update(['assigned_teacher_id' => $teacher->id, 'status' => 'active']);
            $pauseSeconds = max(0, $replacement->reviewed_at?->diffInSeconds(now()) ?? 0);
            if ($replacement->package->expires_at && $pauseSeconds > 0) {
                $replacement->package->update(['expires_at' => $replacement->package->expires_at->copy()->addSeconds($pauseSeconds)]);
            }
            $replacement->transitionTo(TeacherReplacementState::COMPLETED, [
                'new_teacher_id' => $teacher->id,
                'completed_at' => now(),
            ]);
            Notification::updateOrCreate(
                ['unique_key' => "replacement-completed:{$replacement->id}:{$replacement->student_id}"],
                [
                    'user_id' => $replacement->student_id,
                    'title' => 'Guru pengganti ditemukan',
                    'message' => "{$teacher->name} akan melanjutkan sesi {$replacement->subject->subject_name} yang tersisa.",
                    'type' => 'success',
                    'target_url' => '/student/my-classes',
                    'is_read' => false,
                ]
            );

            return ['replacement' => $replacement->fresh(), 'package_activated' => false, 'replacement_completed' => true];
        }, 3);
    }
}
