<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\TeacherAvailability;
use App\Models\TeacherAvailabilityException;
use App\Models\User;
use App\Services\CheapClassService;
use App\Services\TeacherMatchingService;
use App\Services\TeacherOfferReleaseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TeacherScheduleController extends Controller
{
    private const DAYS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

    public function index(Request $request)
    {
        $schedules = TeacherAvailability::query()
            ->where('user_id', Auth::id())
            ->get()
            ->keyBy('day');

        return response()->json(collect(self::DAYS)->map(function (string $day) use ($schedules) {
            $item = $schedules->get($day);
            $ranges = $item?->normalizedRanges() ?? [];

            return [
                'day' => $day,
                'is_active' => (bool) ($item?->is_active && count($ranges) > 0),
                'ranges' => $ranges,
            ];
        }));
    }

    public function exceptions(Request $request)
    {
        return response()->json(
            TeacherAvailabilityException::query()
                ->where('user_id', $request->user()->id)
                ->orderBy('start_date')
                ->get()
                ->map(fn (TeacherAvailabilityException $item) => [
                    'id' => $item->id,
                    'start_date' => $item->start_date->toDateString(),
                    'end_date' => $item->end_date->toDateString(),
                    'reason' => $item->reason,
                ])
                ->values()
        );
    }

    public function storeException(
        Request $request,
        TeacherOfferReleaseService $offerReleaseService,
        TeacherMatchingService $matchingService,
        CheapClassService $cheapClassService
    ) {
        $validated = $request->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'])->endOfDay();
        if ($startDate->diffInDays($endDate) > 180) {
            return response()->json(['message' => 'Rentang tanggal tidak tersedia maksimal 180 hari.'], 422);
        }
        if (TeacherAvailabilityException::query()->where('user_id', $request->user()->id)
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())->exists()) {
            return response()->json(['message' => 'Rentang tanggal tidak tersedia bertabrakan dengan data sebelumnya.'], 422);
        }
        if (Booking::query()->where('teacher_id', $request->user()->id)
            ->whereIn('status', ['confirmed', 'in_progress', 'awaiting_student_approval', 'disputed', 'absence_review', 'admin_review_required'])
            ->whereDate('start_at', '>=', $startDate->toDateString())
            ->whereDate('start_at', '<=', $endDate->toDateString())->exists()) {
            return response()->json(['message' => 'Masih ada kelas aktif pada rentang tersebut. Ubah jadwal kelas terlebih dahulu.'], 422);
        }
        $exception = TeacherAvailabilityException::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'reason' => trim((string) ($validated['reason'] ?? '')) ?: null,
        ]);
        $offerReleaseService->releaseForTeacherDateRange($request->user()->id, $startDate, $endDate, 'Tutor menambahkan tanggal tidak tersedia')
            ->each(fn ($bookingRequest) => $matchingService->dispatchNextOffer($bookingRequest));
        $cheapClassService->refreshTeacherAssignmentsForTeacher($request->user()->id);

        return response()->json(['message' => 'Tanggal tidak tersedia berhasil ditambahkan.', 'data' => $exception], 201);
    }

    public function destroyException(
        Request $request,
        TeacherAvailabilityException $teacherAvailabilityException,
        CheapClassService $cheapClassService
    ) {
        abort_unless((int) $teacherAvailabilityException->user_id === (int) $request->user()->id, 403);
        $teacherAvailabilityException->delete();
        $cheapClassService->refreshTeacherAssignmentsForTeacher($request->user()->id);

        return response()->json(['message' => 'Tanggal tidak tersedia berhasil dihapus.']);
    }

    public function update(
        Request $request,
        TeacherOfferReleaseService $offerReleaseService,
        TeacherMatchingService $matchingService,
        CheapClassService $cheapClassService
    ) {
        $validated = $request->validate([
            'schedules' => ['required', 'array', 'size:7'],
            'schedules.*.day' => ['required', 'string', 'distinct', 'in:'.implode(',', self::DAYS)],
            'schedules.*.is_active' => ['required', 'boolean'],
            'schedules.*.ranges' => ['present', 'array'],
            'schedules.*.ranges.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.ranges.*.end_time' => ['required', 'date_format:H:i'],
        ]);

        foreach ($validated['schedules'] as &$item) {
            $item['ranges'] = collect($item['ranges'])
                ->map(fn (array $range) => [
                    'start_time' => substr($range['start_time'], 0, 5),
                    'end_time' => substr($range['end_time'], 0, 5),
                ])
                ->sortBy('start_time')
                ->values()
                ->all();

            if (! $item['is_active']) {
                $item['ranges'] = [];

                continue;
            }

            if (empty($item['ranges'])) {
                return response()->json([
                    'message' => "Tambahkan sedikitnya satu rentang untuk {$item['day']}.",
                ], 422);
            }

            foreach ($item['ranges'] as $index => $range) {
                if (! TeacherAvailability::isFullHour($range['start_time']) || ! TeacherAvailability::isFullHour($range['end_time'])) {
                    return response()->json([
                        'message' => "Jam {$item['day']} hanya boleh memakai menit 00.",
                    ], 422);
                }
                if ($range['end_time'] <= $range['start_time']) {
                    return response()->json([
                        'message' => "Jam selesai {$item['day']} harus setelah jam mulai.",
                    ], 422);
                }
                if ($index > 0 && $item['ranges'][$index - 1]['end_time'] > $range['start_time']) {
                    return response()->json([
                        'message' => "Rentang jadwal {$item['day']} tidak boleh bertabrakan.",
                    ], 422);
                }
            }
        }
        unset($item);

        $scheduleChanged = false;
        DB::transaction(function () use ($validated, &$scheduleChanged) {
            User::query()->lockForUpdate()->findOrFail(Auth::id());
            $current = TeacherAvailability::query()
                ->where('user_id', Auth::id())
                ->lockForUpdate()
                ->get()
                ->keyBy('day');

            foreach ($validated['schedules'] as $item) {
                $existing = $current->get($item['day']);
                $ranges = $item['is_active'] ? $item['ranges'] : [];
                $existingRanges = $existing?->normalizedRanges() ?? [];
                $scheduleChanged = $scheduleChanged
                    || ! $existing
                    || (bool) $existing->is_active !== (bool) $item['is_active']
                    || $existingRanges !== $ranges;

                TeacherAvailability::updateOrCreate(
                    ['user_id' => Auth::id(), 'day' => $item['day']],
                    [
                        'is_active' => $item['is_active'],
                        'start_time' => $ranges[0]['start_time'] ?? null,
                        'end_time' => $ranges[count($ranges) - 1]['end_time'] ?? null,
                        'slots' => $ranges,
                    ]
                );
            }
        }, 3);

        if ($scheduleChanged) {
            $offerReleaseService
                ->releaseForTeacher(Auth::id(), 'Jadwal ketersediaan tutor berubah')
                ->each(fn ($bookingRequest) => $matchingService->dispatchNextOffer($bookingRequest));
            $cheapClassService->refreshTeacherAssignmentsForTeacher((int) Auth::id());
        }

        return response()->json(['message' => 'Rentang jadwal mengajar berhasil disimpan.']);
    }
}
