<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\TeacherAvailability;
use App\Models\TeacherAvailabilityException;
use App\Models\User;
use Illuminate\Http\Request;

class AdminTeacherScheduleController extends Controller
{
    private const DAYS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

    public function show(Request $request, User $teacher)
    {
        abort_unless($teacher->role === 'teacher', 404, 'Tutor tidak ditemukan.');

        $schedules = TeacherAvailability::query()->where('user_id', $teacher->id)->get()->keyBy('day');
        $weekly = collect(self::DAYS)->map(function (string $day) use ($schedules) {
            $item = $schedules->get($day);
            $ranges = $item?->normalizedRanges() ?? [];

            return [
                'day' => $day,
                'is_active' => (bool) ($item?->is_active && count($ranges) > 0),
                'ranges' => $ranges,
            ];
        })->values();

        $exceptions = TeacherAvailabilityException::query()
            ->where('user_id', $teacher->id)
            ->whereDate('end_date', '>=', today())
            ->orderBy('start_date')
            ->limit(50)
            ->get()
            ->map(fn (TeacherAvailabilityException $item) => [
                'id' => $item->id,
                'start_date' => $item->start_date->toDateString(),
                'end_date' => $item->end_date->toDateString(),
                'reason' => $item->reason,
            ])->values();

        $bookings = Booking::query()
            ->where('teacher_id', $teacher->id)
            ->where('start_at', '>=', now()->startOfDay())
            ->whereIn('status', ['confirmed', 'in_progress', 'awaiting_student_approval', 'disputed', 'absence_review', 'admin_review_required'])
            ->with(['student:id,name', 'bookingRequest:id,subject_name'])
            ->orderBy('start_at')
            ->limit(100)
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'subject_name' => $booking->bookingRequest?->subject_name ?? 'Kelas privat',
                'student_name' => $booking->student?->name ?? 'Murid',
                'learning_mode' => $booking->learning_mode,
                'status' => $booking->status,
                'start_at' => $booking->start_at?->toIso8601String(),
                'end_at' => $booking->end_at?->toIso8601String(),
            ])->values();

        return response()->json([
            'teacher' => ['id' => $teacher->id, 'name' => $teacher->name],
            'weekly' => $weekly,
            'exceptions' => $exceptions,
            'upcoming_bookings' => $bookings,
            'limits' => ['exceptions' => 50, 'upcoming_bookings' => 100],
        ]);
    }
}
