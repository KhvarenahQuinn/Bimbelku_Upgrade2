<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Services\TeacherMatchingService;
use App\Support\EducationCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TutorAvailabilityController extends Controller
{
    public function check(Request $request, TeacherMatchingService $matchingService)
    {
        $validated = $request->validate([
            'subject_name' => ['required', 'string', 'max:255'],
            'education_level' => ['required', Rule::in(EducationCatalog::LEVELS)],
            'grade' => ['required', 'string', 'max:100'],
            'learning_mode' => ['required', Rule::in(['online', 'offline'])],
            'class_type' => ['required', Rule::in(['private'])],
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_hours' => ['required', 'integer', Rule::in([1])],
            'latitude' => ['nullable', 'required_if:learning_mode,offline', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_if:learning_mode,offline', 'numeric', 'between:-180,180'],
        ]);

        $allowedGrades = EducationCatalog::GRADES_BY_LEVEL[$validated['education_level']] ?? [];
        if (! in_array($validated['grade'], $allowedGrades, true)) {
            return response()->json([
                'message' => 'Kelas tidak sesuai dengan jenjang yang dipilih.',
            ], 422);
        }

        $timezone = config('app.timezone', 'Asia/Jakarta');
        $startAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['scheduled_date'].' '.$validated['start_time'],
            $timezone
        );

        if ((int) $startAt->format('i') !== 0) {
            return response()->json([
                'message' => 'Jam mulai hanya boleh menggunakan menit 00.',
            ], 422);
        }
        if ($startAt->lessThanOrEqualTo(now($timezone))) {
            return response()->json([
                'message' => 'Jadwal harus berada setelah waktu sekarang.',
            ], 422);
        }

        $validated['duration_hours'] = 1;
        $endAt = $startAt->copy()->addHour();
        if ($endAt->toDateString() !== $startAt->toDateString()) {
            return response()->json([
                'message' => 'Sesi harus selesai pada hari yang sama.',
            ], 422);
        }

        $preview = new BookingRequest;
        $preview->forceFill([
            'subject_name' => trim($validated['subject_name']),
            'education_level' => $validated['education_level'],
            'grade' => $validated['grade'],
            'learning_mode' => $validated['learning_mode'],
            'class_type' => $validated['class_type'],
            'scheduled_date' => $validated['scheduled_date'],
            'start_time' => $startAt->format('H:i:s'),
            'end_time' => $endAt->format('H:i:s'),
            'duration_hours' => (int) $validated['duration_hours'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'search_radius_km' => $validated['learning_mode'] === 'offline' ? 3 : null,
        ]);

        $available = $matchingService->hasAvailableCandidate($preview);

        return response()->json([
            'status' => $available ? 'available' : 'not_found',
            'has_candidate' => $available,
            'search_radius_km' => $preview->search_radius_km,
            'message' => $available
                ? 'Tutor yang sesuai berpotensi tersedia pada jadwal ini.'
                : (
                    $validated['learning_mode'] === 'offline'
                        ? 'Tutor belum terlihat pada radius awal 3 km. Permintaan tetap dapat dibuat dan radius dapat diperluas.'
                        : 'Tutor belum terlihat pada jadwal ini. Permintaan tetap dapat dibuat untuk menjalankan pencarian.'
                ),
        ]);
    }
}
