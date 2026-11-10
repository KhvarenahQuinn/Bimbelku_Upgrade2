<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RatingController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:1500'],
        ]);

        $student = $request->user();
        $booking = Booking::with('order')->findOrFail($validated['booking_id']);
        $participant = BookingParticipant::where('booking_id', $booking->id)
            ->where('student_id', $student->id)
            ->first();

        if (! $participant && $booking->student_id !== $student->id) {
            return response()->json(['message' => 'Anda bukan peserta sesi ini.'], 403);
        }

        if ($booking->status !== 'completed') {
            return response()->json(['message' => 'Ulasan hanya dapat dikirim setelah sesi selesai.'], 422);
        }
        if (! $participant || $participant->status !== 'approved') {
            return response()->json([
                'message' => 'Ulasan hanya tersedia bagi murid yang mengikuti dan menyetujui penyelesaian sesi.',
            ], 422);
        }

        $rating = DB::transaction(function () use ($booking, $student, $validated) {
            $lockedBooking = Booking::query()
                ->with('order')
                ->lockForUpdate()
                ->findOrFail($booking->id);
            $lockedParticipant = BookingParticipant::query()
                ->with('order')
                ->where('booking_id', $lockedBooking->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->status !== 'completed' || $lockedParticipant->status !== 'approved') {
                abort(422, 'Status sesi telah berubah dan belum dapat diulas.');
            }
            if (Rating::query()
                ->where('booking_id', $lockedBooking->id)
                ->where('student_id', $student->id)
                ->exists()) {
                abort(422, 'Anda sudah mengulas sesi ini.');
            }

            $classroomId = $lockedParticipant->order?->classroom_id
                ?? $lockedBooking->order?->classroom_id;
            // Paket Belajar memakai booking sebagai identitas sesi, tanpa Classroom lama.

            $record = Rating::create([
                'classroom_id' => $classroomId,
                'booking_id' => $lockedBooking->id,
                'student_id' => $student->id,
                'teacher_id' => $lockedBooking->teacher_id,
                'rating' => $validated['rating'],
                'review' => $validated['review'] ?? null,
            ]);

            return $record;
        });

        return response()->json([
            'message' => 'Ulasan berhasil dikirim.',
            'data' => $rating,
        ], 201);
    }
}
