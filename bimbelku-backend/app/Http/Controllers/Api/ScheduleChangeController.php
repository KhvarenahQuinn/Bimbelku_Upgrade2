<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\LearningTimeSlot;
use App\Models\Notification;
use App\Models\PackageSession;
use App\Models\ScheduleChangeRequest;
use App\Models\ScheduleChangeResponse;
use App\Models\Setting;
use App\Services\TeacherMatchingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduleChangeController extends Controller
{
    private const ACTIVE_STATUSES = [
        'confirmed', 'in_progress', 'awaiting_student_approval', 'disputed',
        'absence_review', 'admin_review_required',
    ];

    public function options(Request $request, Booking $booking, TeacherMatchingService $matchingService)
    {
        $this->authorizeMember($request, $booking);
        $validated = $request->validate([
            'scope' => ['nullable', Rule::in(['single', 'future'])],
            'date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $scope = $validated['scope'] ?? 'single';
        $requestedDate = filled($validated['date'] ?? null)
            ? Carbon::parse($validated['date'], config('app.timezone', 'Asia/Jakarta'))->startOfDay()
            : null;
        if ($requestedDate?->gt(now()->addMonths(6)->endOfDay())) {
            throw ValidationException::withMessages(['date' => 'Tanggal pengganti tidak boleh lebih dari enam bulan.']);
        }

        $noticeHours = $this->noticeHours();
        $startDate = $requestedDate ?? now()->addHours($noticeHours)->startOfDay();
        $maximumOffset = $requestedDate ? 0 : 45;
        $originalMinutes = ((int) $booking->start_at->format('H')) * 60;
        $slotTimes = LearningTimeSlot::query()->where('is_active', true)->orderBy('start_time')->pluck('start_time')
            ->map(fn ($time) => substr((string) $time, 0, 5))
            ->filter(fn (string $time) => str_ends_with($time, ':00') && $time < '23:00')
            ->sortBy(fn (string $time) => abs((((int) substr($time, 0, 2)) * 60) - $originalMinutes))
            ->values();

        $options = collect();
        for ($offset = 0; $offset <= $maximumOffset && $options->count() < 24; $offset++) {
            $date = $startDate->copy()->addDays($offset);
            $optionsOnDate = 0;
            foreach ($slotTimes as $time) {
                $candidate = Carbon::parse($date->toDateString().' '.$time, config('app.timezone', 'Asia/Jakarta'));
                if ($candidate->lt(now()->addHours($noticeHours))) {
                    continue;
                }
                try {
                    $affected = $this->buildAffectedSchedules($booking, $candidate, $scope);
                    $this->validateAffectedSchedules($affected, $matchingService);
                    $options->push([
                        'start_at' => $candidate->toIso8601String(),
                        'end_at' => $candidate->copy()->addMinutes($booking->start_at->diffInMinutes($booking->end_at))->toIso8601String(),
                        'label' => $candidate->translatedFormat('l, d F Y · H.i').' WIB',
                        'scope' => $scope,
                        'affected_count' => $affected->count(),
                    ]);
                    $optionsOnDate++;
                } catch (ValidationException) {
                }
                if ($options->count() >= 24 || (! $requestedDate && $optionsOnDate >= 3)) {
                    break;
                }
            }
        }

        return response()->json([
            'data' => $options->values(),
            'message' => $options->isEmpty()
                ? ($requestedDate ? 'Tutor belum memiliki slot kosong pada tanggal tersebut.' : 'Belum ada slot tutor yang tersedia dalam 45 hari ke depan.')
                : 'Pilihan telah diperiksa terhadap jadwal tutor dan peserta.',
        ]);
    }

    public function store(Request $request, Booking $booking, TeacherMatchingService $matchingService)
    {
        $role = $this->authorizeMember($request, $booking);
        $validated = $request->validate([
            'proposed_start_at' => ['required', 'date'],
            'scope' => ['nullable', Rule::in(['single', 'future'])],
            'reason' => ['required', 'string', 'min:20', 'max:1500'],
        ]);
        $scope = $validated['scope'] ?? 'single';
        $proposedStart = Carbon::parse($validated['proposed_start_at']);
        $affected = $this->buildAffectedSchedules($booking, $proposedStart, $scope);
        $this->validateAffectedSchedules($affected, $matchingService);

        $change = DB::transaction(function () use ($request, $booking, $role, $validated, $scope, $proposedStart, $matchingService) {
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $this->authorizeMember($request, $lockedBooking);
            $affected = $this->buildAffectedSchedules($lockedBooking, $proposedStart, $scope);
            $affectedIds = $affected->pluck('booking.id')->map(fn ($id) => (int) $id)->all();
            Booking::query()->whereKey($affectedIds)->lockForUpdate()->get();
            $this->validateAffectedSchedules($affected, $matchingService);
            if ($this->hasPendingChangeFor($affectedIds)) {
                throw ValidationException::withMessages(['schedule' => 'Masih ada permintaan perubahan jadwal yang menunggu jawaban pada salah satu sesi.']);
            }

            $first = $affected->first();
            $responseHours = max(1, min(48, (int) (Setting::where('key', 'schedule_change_response_hours')->value('value') ?? 24)));
            $record = ScheduleChangeRequest::create([
                'booking_id' => $lockedBooking->id,
                'requested_by' => $request->user()->id,
                'requester_role' => $role,
                'scope' => $scope,
                'original_start_at' => $lockedBooking->start_at,
                'original_end_at' => $lockedBooking->end_at,
                'proposed_start_at' => $first['start'],
                'proposed_end_at' => $first['end'],
                'affected_schedules' => $affected->map(fn (array $item) => [
                    'booking_id' => $item['booking']->id,
                    'original_start_at' => $item['booking']->start_at->toIso8601String(),
                    'original_end_at' => $item['booking']->end_at->toIso8601String(),
                    'proposed_start_at' => $item['start']->toIso8601String(),
                    'proposed_end_at' => $item['end']->toIso8601String(),
                ])->values()->all(),
                'reason' => trim($validated['reason']),
                'status' => 'pending',
                'expires_at' => now()->addHours($responseHours)->min($lockedBooking->start_at),
            ]);

            foreach ($this->requiredResponders($affected, (int) $request->user()->id) as $userId) {
                ScheduleChangeResponse::create([
                    'schedule_change_request_id' => $record->id,
                    'user_id' => $userId,
                    'decision' => 'pending',
                ]);
                Notification::updateOrCreate(
                    ['unique_key' => "schedule-change:{$record->id}:{$userId}"],
                    [
                        'user_id' => $userId,
                        'title' => 'Perubahan jadwal menunggu jawaban',
                        'message' => $scope === 'future'
                            ? "Usulan berlaku untuk {$affected->count()} sesi mulai sesi ini. Jadwal lama tetap aktif sampai disetujui."
                            : 'Usulan hanya berlaku untuk sesi ini. Jadwal lama tetap aktif sampai disetujui.',
                        'type' => 'warning',
                        'target_url' => $this->targetUrlFor($userId, $lockedBooking),
                        'is_read' => false,
                    ]
                );
            }

            return $record->load(['requester:id,name,role', 'responses.user:id,name,role']);
        }, 3);

        return response()->json([
            'message' => $scope === 'future'
                ? 'Perubahan sesi ini dan sesi berikutnya diajukan. Jadwal lama tetap berlaku sampai disetujui.'
                : 'Perubahan sesi ini diajukan. Jadwal lama tetap berlaku sampai disetujui.',
            'data' => $change,
        ], 201);
    }

    public function respond(Request $request, Booking $booking, ScheduleChangeRequest $scheduleChangeRequest, TeacherMatchingService $matchingService)
    {
        $this->authorizeMember($request, $booking);
        abort_unless($scheduleChangeRequest->booking_id === $booking->id, 404);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($validated['decision'] === 'rejected' && mb_strlen(trim((string) ($validated['notes'] ?? ''))) < 10) {
            return response()->json(['message' => 'Jelaskan alasan penolakan sedikitnya 10 karakter.'], 422);
        }

        $result = DB::transaction(function () use ($request, $booking, $scheduleChangeRequest, $validated, $matchingService) {
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $change = ScheduleChangeRequest::query()->where('booking_id', $lockedBooking->id)->lockForUpdate()->findOrFail($scheduleChangeRequest->id);
            abort_unless($change->status === 'pending', 422, 'Permintaan perubahan jadwal sudah diproses.');
            if ($change->expires_at?->isPast()) {
                $change->update(['status' => 'expired', 'decided_at' => now()]);
                abort(422, 'Batas waktu jawaban perubahan jadwal telah berakhir.');
            }
            $response = ScheduleChangeResponse::query()
                ->where('schedule_change_request_id', $change->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($response->decision === 'pending', 422, 'Jawaban Anda sudah tersimpan.');
            $response->update([
                'decision' => $validated['decision'],
                'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
                'responded_at' => now(),
            ]);
            if ($validated['decision'] === 'rejected') {
                $change->update(['status' => 'rejected', 'decided_at' => now()]);
                $this->notifyScheduleDecision($lockedBooking, $change, 'rejected');

                return ['status' => 'rejected', 'change' => $change->fresh('responses.user')];
            }
            if (ScheduleChangeResponse::query()->where('schedule_change_request_id', $change->id)->where('decision', 'pending')->lockForUpdate()->exists()) {
                return ['status' => 'pending', 'change' => $change->fresh('responses.user')];
            }

            $affected = $this->affectedFromRecord($change);
            Booking::query()->whereKey($affected->pluck('booking.id')->all())->lockForUpdate()->get();
            $this->validateAffectedSchedules($affected, $matchingService);
            foreach ($affected as $item) {
                $this->applySchedule($item['booking'], $item['start'], $item['end']);
            }
            $change->update(['status' => 'approved', 'decided_at' => now()]);
            $this->notifyScheduleDecision($lockedBooking, $change, 'approved');

            return ['status' => 'approved', 'change' => $change->fresh('responses.user')];
        }, 3);

        $message = match ($result['status']) {
            'approved' => 'Jadwal baru disetujui dan diterapkan tanpa mengubah sesi yang telah selesai.',
            'rejected' => 'Perubahan jadwal ditolak. Jadwal lama tetap berlaku.',
            default => 'Jawaban tersimpan. Sistem masih menunggu pihak lain.',
        };

        return response()->json(['message' => $message, 'data' => $result['change']]);
    }

    private function buildAffectedSchedules(Booking $booking, Carbon $proposedStart, string $scope): Collection
    {
        $durationMinutes = max(1, $booking->start_at->diffInMinutes($booking->end_at));
        if ($scope !== 'future') {
            return collect([['booking' => $booking, 'start' => $proposedStart->copy(), 'end' => $proposedStart->copy()->addMinutes($durationMinutes)]]);
        }
        $currentSession = PackageSession::query()->where('booking_id', $booking->id)->first();
        if (! $currentSession) {
            throw ValidationException::withMessages(['scope' => 'Perubahan seluruh sesi berikutnya hanya tersedia untuk paket belajar.']);
        }

        $deltaSeconds = $booking->start_at->diffInSeconds($proposedStart, false);
        $items = PackageSession::query()->where('package_subject_id', $currentSession->package_subject_id)
            ->where('sequence', '>=', $currentSession->sequence)->whereNotNull('booking_id')
            ->with('booking')->orderBy('sequence')->get()
            ->filter(fn (PackageSession $session) => $session->booking?->status === 'confirmed')
            ->map(fn (PackageSession $session) => [
                'booking' => $session->booking,
                'start' => $session->booking->start_at->copy()->addSeconds($deltaSeconds),
                'end' => $session->booking->end_at->copy()->addSeconds($deltaSeconds),
            ])->values();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['scope' => 'Tidak ada sesi berikutnya yang dapat diubah.']);
        }

        return $items;
    }

    private function affectedFromRecord(ScheduleChangeRequest $change): Collection
    {
        $rows = collect($change->affected_schedules ?: [[
            'booking_id' => $change->booking_id,
            'proposed_start_at' => $change->proposed_start_at,
            'proposed_end_at' => $change->proposed_end_at,
        ]]);
        $bookings = Booking::query()->whereKey($rows->pluck('booking_id'))->get()->keyBy('id');

        return $rows->map(function (array $row) use ($bookings) {
            $booking = $bookings->get((int) $row['booking_id']);
            abort_unless($booking, 422, 'Salah satu sesi perubahan sudah tidak tersedia.');

            return ['booking' => $booking, 'start' => Carbon::parse($row['proposed_start_at']), 'end' => Carbon::parse($row['proposed_end_at'])];
        });
    }

    private function validateAffectedSchedules(Collection $affected, TeacherMatchingService $matchingService): void
    {
        if ($affected->every(fn (array $item) => $item['booking']->start_at->equalTo($item['start']))) {
            throw ValidationException::withMessages(['proposed_start_at' => 'Pilih tanggal atau jam yang berbeda dari jadwal lama.']);
        }
        $ignoreIds = $affected->pluck('booking.id')->map(fn ($id) => (int) $id)->all();
        $sorted = $affected->sortBy(fn (array $item) => $item['start']->timestamp)->values();
        for ($i = 0; $i < $sorted->count() - 1; $i++) {
            if ($sorted[$i]['end']->gt($sorted[$i + 1]['start'])) {
                throw ValidationException::withMessages(['proposed_start_at' => 'Jadwal baru saling bertabrakan.']);
            }
        }
        foreach ($affected as $item) {
            $booking = $item['booking'];
            $startAt = $item['start'];
            $endAt = $item['end'];
            abort_unless($booking->status === 'confirmed', 422, 'Hanya sesi yang belum dimulai yang dapat diubah.');
            if ($startAt->lt(now()->addHours($this->noticeHours()))) {
                throw ValidationException::withMessages(['proposed_start_at' => "Jadwal baru harus diajukan sedikitnya {$this->noticeHours()} jam sebelumnya."]);
            }
            if ($startAt->gt(now()->addMonths(6))) {
                throw ValidationException::withMessages(['proposed_start_at' => 'Jadwal baru tidak boleh lebih dari enam bulan.']);
            }
            if ((int) $startAt->format('i') !== 0 || (int) $endAt->format('i') !== 0) {
                throw ValidationException::withMessages(['proposed_start_at' => 'Jam hanya boleh menggunakan menit 00.']);
            }
            $packageSession = PackageSession::query()->where('booking_id', $booking->id)->with('subject.package')->first();
            if ($packageSession?->subject?->package?->expires_at && $endAt->gt($packageSession->subject->package->expires_at)) {
                throw ValidationException::withMessages(['proposed_start_at' => 'Jadwal baru melewati masa aktif paket.']);
            }
            $teacher = $booking->teacher;
            if (! $teacher || ! $matchingService->teacherAvailableAt($teacher, $startAt, $endAt)) {
                throw ValidationException::withMessages(['proposed_start_at' => 'Jadwal baru berada di luar jam kosong atau tanggal aktif tutor.']);
            }
            if ($this->teacherHasConflict((int) $booking->teacher_id, $startAt, $endAt, $ignoreIds)) {
                throw ValidationException::withMessages(['proposed_start_at' => 'Jadwal tutor bertabrakan dengan kelas lain.']);
            }
            $studentIds = $booking->participants()->whereHas('order', fn ($q) => $q->where('status', 'paid'))->pluck('student_id');
            if ($this->studentsHaveConflict($studentIds, $startAt, $endAt, $ignoreIds)) {
                throw ValidationException::withMessages(['proposed_start_at' => 'Jadwal baru bertabrakan dengan kelas peserta.']);
            }
        }
    }

    private function teacherHasConflict(int $teacherId, Carbon $startAt, Carbon $endAt, array $ignoreIds = []): bool
    {
        return Booking::query()
            ->where('teacher_id', $teacherId)
            ->when($ignoreIds, fn ($query) => $query->whereNotIn('id', $ignoreIds))
            ->whereIn('status', self::ACTIVE_STATUSES)
            // Refunded/cancelled rows may still look active; release the slot
            // only when the order is fully closed or this request is released.
            ->whereDoesntHave('order', fn ($order) => $order
                ->whereIn('status', ['refunded', 'cancelled', 'expired']))
            ->whereDoesntHave('bookingRequest', fn ($bookingRequest) => $bookingRequest
                ->whereIn('status', ['refund_pending', 'refunded', 'cancelled', 'payment_expired', 'expired']))
            ->whereDoesntHave('bookingRequest.packageSubject.package', fn ($package) => $package
                ->whereIn('status', ['refund_pending', 'refunded', 'cancelled', 'payment_expired']))
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();
    }

    private function studentsHaveConflict(Collection $studentIds, Carbon $startAt, Carbon $endAt, array $ignoreIds = []): bool
    {
        if ($studentIds->isEmpty()) {
            return false;
        }

        return Booking::query()
            ->when($ignoreIds, fn ($query) => $query->whereNotIn('id', $ignoreIds))
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereDoesntHave('order', fn ($order) => $order
                ->whereIn('status', ['refunded', 'cancelled', 'expired']))
            ->whereDoesntHave('bookingRequest', fn ($bookingRequest) => $bookingRequest
                ->whereIn('status', ['refund_pending', 'refunded', 'cancelled', 'payment_expired', 'expired']))
            ->whereDoesntHave('bookingRequest.packageSubject.package', fn ($package) => $package
                ->whereIn('status', ['refund_pending', 'refunded', 'cancelled', 'payment_expired']))
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->whereHas('participants', fn ($query) => $query->whereIn('student_id', $studentIds))
            ->exists();
    }

    private function applySchedule(Booking $booking, Carbon $startAt, Carbon $endAt): void
    {
        $booking->update(['start_at' => $startAt, 'end_at' => $endAt]);
        $booking->bookingRequest?->update(['scheduled_date' => $startAt->toDateString(), 'start_time' => $startAt->format('H:i:s'), 'end_time' => $endAt->format('H:i:s')]);
        PackageSession::query()->where('booking_id', $booking->id)->update(['scheduled_start_at' => $startAt, 'scheduled_end_at' => $endAt]);
    }

    private function noticeHours(): int
    {
        return max(1, min(48, (int) (Setting::where('key', 'schedule_change_min_notice_hours')->value('value') ?? 6)));
    }

    private function authorizeMember(Request $request, Booking $booking): string
    {
        if ($request->user()->role === 'teacher' && (int) $booking->teacher_id === (int) $request->user()->id) {
            abort_unless($booking->participants()->whereHas('order', fn ($q) => $q->where('status', 'paid'))->exists(), 403);

            return 'teacher';
        }
        if ($request->user()->role === 'student' && $booking->participants()->where('student_id', $request->user()->id)->whereHas('order', fn ($q) => $q->where('status', 'paid'))->exists()) {
            return 'student';
        }
        abort(403, 'Anda tidak terdaftar pada sesi ini.');
    }

    private function requiredResponders(Collection $affected, int $requesterId): array
    {
        return $affected->flatMap(fn (array $item) => collect([$item['booking']->teacher_id])->merge($item['booking']->participants()->whereHas('order', fn ($q) => $q->where('status', 'paid'))->pluck('student_id')))
            ->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === $requesterId)->unique()->values()->all();
    }

    private function hasPendingChangeFor(array $bookingIds): bool
    {
        return ScheduleChangeRequest::query()->where('status', 'pending')->lockForUpdate()->get(['id', 'booking_id', 'affected_schedules'])
            ->contains(fn (ScheduleChangeRequest $change) => in_array((int) $change->booking_id, $bookingIds, true)
                || collect($change->affected_schedules ?? [])->pluck('booking_id')->map(fn ($id) => (int) $id)->intersect($bookingIds)->isNotEmpty());
    }

    private function notifyScheduleDecision(Booking $booking, ScheduleChangeRequest $change, string $status): void
    {
        collect([$booking->teacher_id])->merge($booking->participants()->pluck('student_id'))->unique()->each(function ($userId) use ($booking, $change, $status) {
            Notification::updateOrCreate(['unique_key' => "schedule-change-result:{$change->id}:{$userId}"], [
                'user_id' => $userId,
                'title' => $status === 'approved' ? 'Jadwal baru disetujui' : 'Perubahan jadwal ditolak',
                'message' => $status === 'approved' ? (($change->scope === 'future' ? 'Sesi ini dan sesi berikutnya' : 'Sesi ini').' telah diperbarui.') : 'Salah satu pihak menolak perubahan. Jadwal lama tetap berlaku.',
                'type' => $status === 'approved' ? 'success' : 'warning',
                'target_url' => $this->targetUrlFor((int) $userId, $booking),
                'is_read' => false,
            ]);
        });
    }

    private function targetUrlFor(int $userId, Booking $booking): string
    {
        return $userId === (int) $booking->teacher_id ? '/guru/kelas' : '/student/my-classes';
    }
}
