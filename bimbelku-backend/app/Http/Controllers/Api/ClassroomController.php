<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PackageSubject;
use App\Support\PackageChapterProgress;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    public function index(Request $request)
    {
        $teacherId = (int) $request->user()->id;

        $bookings = Booking::query()
            ->where('teacher_id', $teacherId)
            ->whereNotIn('status', ['cancelled', 'student_rejected', 'payment_expired', 'expired'])
            ->with([
                'bookingRequest',
                'participants.student',
                'participants.bookingRequest',
                'participants.order.refund',
                'reports' => fn ($query) => $query->latest(),
                'disputes' => fn ($query) => $query->latest(),
                'sessionAttendances',
                'learningProgressReports.chapterLogs.chapter',
                'packageSession.subject.package.plan',
                'packageSession.subject.package.student:id,name',
                'packageSession.subject.package.subjects.sessions.booking:id,status',
                'packageSession.subject.sessions.booking:id,status',
                'packageSession.subject.chapters',
                'participantAttendances',
                'latestClassroomMessage.sender:id,name',
                'scheduleChangeRequests' => fn ($query) => $query
                    ->where('status', 'pending')
                    ->with('responses')
                    ->latest(),
            ])
            ->withCount([
                'classroomMessages as unread_message_count' => fn ($query) => $query
                    ->where('sender_id', '!=', $teacherId)
                    ->whereDoesntHave('reads', fn ($readQuery) => $readQuery->where('user_id', $teacherId)),
            ])
            ->latest('start_at')
            ->limit(200)
            ->get();

        return response()->json($bookings->map(function (Booking $booking) use ($teacherId) {
            $bookingRequest = $booking->bookingRequest;
            $canSeeFullAddress = $booking->learning_mode === 'offline'
                && in_array($booking->status, [
                    'confirmed',
                    'in_progress',
                    'awaiting_student_approval',
                    'disputed',
                    'absence_review',
                    'admin_review_required',
                    'completed',
                ], true);

            $startAt = $booking->start_at;
            $endAt = $booking->end_at;
            $packageSession = $booking->packageSession;
            $packageSubject = $packageSession?->subject;
            $packageChapters = $packageSubject?->chapters ?? collect();
            $chapterProgress = PackageChapterProgress::summary($packageChapters);
            $package = $packageSubject?->package;
            $subjectSessions = $packageSubject?->sessions ?? collect();
            $subjectTotalSessions = (int) ($packageSubject?->allocated_sessions ?: $subjectSessions->count());
            $subjectCompletedSessions = $subjectSessions
                ->filter(fn ($session) => $session->booking?->status === 'completed')
                ->count();
            $packageTotalSessions = (int) ($package?->total_sessions ?? 0);
            $packageCompletedSessions = ($package?->subjects ?? collect())
                ->flatMap->sessions
                ->filter(fn ($session) => $session->booking?->status === 'completed')
                ->count();
            $teacherAttendance = $booking->sessionAttendances->firstWhere('user_id', $booking->teacher_id);
            $paidParticipants = $booking->participants->filter(fn ($participant) => $participant->order?->status === 'paid');
            $attendanceComplete = $paidParticipants->every(fn ($participant) => $booking->participantAttendances
                ->contains('booking_participant_id', $participant->id));
            $presentParticipants = $paidParticipants->filter(function ($participant) use ($booking) {
                $status = $booking->participantAttendances
                    ->firstWhere('booking_participant_id', $participant->id)?->status;

                return in_array($status, ['present', 'late', 'partial'], true);
            });
            $progressComplete = $attendanceComplete && $presentParticipants->every(fn ($participant) => $booking->learningProgressReports
                ->contains('student_id', $participant->student_id));
            $latestProgressReport = $booking->learningProgressReports
                ->where('teacher_id', $teacherId)
                ->sortByDesc(fn ($report) => $report->published_at?->getTimestamp() ?? 0)
                ->first();

            return [
                'id' => $booking->id,
                'package_session_id' => $packageSession?->id,
                'package_subject_id' => $packageSubject?->id,
                'learning_package_id' => $packageSubject?->learning_package_id,
                'package_code' => $packageSubject?->package?->package_code,
                'package_name' => $packageSubject?->package?->plan?->name,
                'subject' => $bookingRequest?->subject_name ?: 'Mata pelajaran',
                'education_level' => $bookingRequest?->education_level,
                'grade' => $bookingRequest?->grade,
                'chapter' => $bookingRequest?->chapter,
                'learning_goal' => $bookingRequest?->learning_goal,
                'method' => $booking->learning_mode ?: 'online',
                'type' => 'private',
                'status' => $booking->status ?: 'confirmed',
                'session_flow_version' => 'presence_confirmation_v2',
                'tutor_ready_at' => $booking->tutor_ready_at,
                'student_confirmed_at' => $booking->student_confirmed_at,
                'session_focus_note' => $booking->session_focus_note,
                'start_at' => $booking->start_at,
                'end_at' => $booking->end_at,
                'duration_hours' => (int) ($booking->duration_hours ?: 1),
                'meeting_link' => $booking->meeting_link,
                'address' => $canSeeFullAddress ? $booking->address : null,
                'maps_link' => $canSeeFullAddress ? $booking->maps_link : null,
                'gross_amount' => $booking->gross_amount,
                'teacher_net_amount' => $booking->teacher_net_amount,
                'commission_percent' => (float) ($booking->commission_percent ?: 0),
                'payout_status' => $booking->payout_status ?: 'pending',
                'completion_notes' => $booking->completion_notes,
                'objection_deadline' => $booking->objection_deadline,
                'participants' => $booking->participants->map(function ($participant) use ($booking) {
                    $hasSessionAccess = $participant->order?->status === 'paid';

                    return [
                        'id' => $participant->id,
                        'student_id' => $participant->student_id,
                        'name' => $hasSessionAccess
                            ? $participant->student?->name
                            : 'Murid BimbelKu',
                        'status' => $participant->status,
                        'amount' => $participant->amount,
                        'order_status' => $participant->order?->status,
                        'refund_status' => $participant->order?->refund?->status,
                        'attendance' => optional($booking->participantAttendances
                            ->firstWhere('booking_participant_id', $participant->id))->only([
                                'status', 'notes', 'marked_at',
                            ]),
                    ];
                })->values(),
                'latest_message' => $booking->latestClassroomMessage ? [
                    'body' => $booking->latestClassroomMessage->body,
                    'sender_name' => $booking->latestClassroomMessage->sender?->name,
                    'created_at' => $booking->latestClassroomMessage->created_at,
                    'has_attachment' => (bool) $booking->latestClassroomMessage->attachment_path,
                ] : null,
                'unread_message_count' => (int) $booking->unread_message_count,
                'pending_schedule_change' => $booking->scheduleChangeRequests->first(),
                'latest_report' => $booking->reports->first(),
                'latest_dispute' => $booking->disputes->first(),
                'package_progress' => $packageSubject && $packageChapters->isNotEmpty() ? $chapterProgress : null,
                'package_session_progress' => $packageSubject ? [
                    'total_sessions' => $packageTotalSessions,
                    'used_sessions' => $packageCompletedSessions,
                    'remaining_sessions' => max(0, $packageTotalSessions - $packageCompletedSessions),
                    'subject_total_sessions' => $subjectTotalSessions,
                    'subject_completed_sessions' => $subjectCompletedSessions,
                    'subject_remaining_sessions' => max(0, $subjectTotalSessions - $subjectCompletedSessions),
                ] : null,
                'completion_steps' => [
                    'check_in' => (bool) $booking->student_confirmed_at,
                    'attendance' => $attendanceComplete,
                    'check_out' => (bool) $teacherAttendance?->check_out_at,
                    'progress' => $progressComplete,
                    'progress_reported_count' => $presentParticipants->filter(fn ($participant) => $booking->learningProgressReports
                        ->contains('student_id', $participant->student_id))->count(),
                    'progress_required_count' => $presentParticipants->count(),
                ],
                'latest_progress_report' => $latestProgressReport ? [
                    'id' => $latestProgressReport->id,
                    'student_id' => $latestProgressReport->student_id,
                    'session_number' => $latestProgressReport->session_number,
                    'material_covered' => $latestProgressReport->material_covered,
                    'progress_percent' => $latestProgressReport->progress_percent,
                    'published_at' => $latestProgressReport->published_at,
                    'chapters' => PackageChapterProgress::chapterLogs($latestProgressReport->chapterLogs),
                ] : null,
                'can_complete' => false,
                'can_report_absence' => $startAt !== null
                    && in_array($booking->status, ['confirmed', 'in_progress'], true)
                    && ! $booking->student_confirmed_at
                    && now()->gte($startAt->copy()->addMinutes(15)),
                'can_report_emergency' => ! in_array($booking->status, [
                    'completed', 'cancelled', 'refunded', 'emergency_refund_pending',
                ], true),
            ];
        }));
    }

    public function packageSubjectProgress(Request $request, PackageSubject $packageSubject)
    {
        $teacherId = (int) $request->user()->id;
        abort_unless((int) $packageSubject->assigned_teacher_id === $teacherId, 403);

        $packageSubject->load([
            'package.plan',
            'package.student:id,name',
            'chapters' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'sessions' => fn ($query) => $query->orderBy('sequence'),
            'sessions.booking.learningProgressReports' => fn ($query) => $query
                ->where('teacher_id', $teacherId)
                ->with(['chapterLogs.chapter'])
                ->orderByDesc('published_at')
                ->orderByDesc('id'),
        ]);

        $packageChapters = $packageSubject->chapters;
        $chapterSummary = PackageChapterProgress::summary($packageChapters);
        $chapters = PackageChapterProgress::chapters($packageChapters);

        return response()->json([
            'id' => $packageSubject->id,
            'learning_package_id' => $packageSubject->learning_package_id,
            'package_code' => $packageSubject->package?->package_code,
            'package_name' => $packageSubject->package?->plan?->name ?? 'Paket Belajar',
            'package_status' => $packageSubject->package?->status,
            'subject_name' => $packageSubject->subject_name,
            'learning_goal' => $packageSubject->learning_goal,
            'education_level' => $packageSubject->package?->education_level,
            'grade' => $packageSubject->package?->grade,
            'student' => [
                'id' => $packageSubject->package?->student?->id,
                'name' => $packageSubject->package?->student?->name ?? 'Murid BimbelKu',
            ],
            'allocated_sessions' => (int) $packageSubject->allocated_sessions,
            'progress_summary' => $chapterSummary,
            'learning_chapters' => $chapters,
            'sessions' => $packageSubject->sessions->map(function ($session) use ($teacherId) {
                $booking = $session->booking;
                $report = $booking?->learningProgressReports
                    ->where('teacher_id', $teacherId)
                    ->sortByDesc(fn ($item) => $item->published_at?->getTimestamp() ?? 0)
                    ->first();

                return [
                    'id' => $session->id,
                    'sequence' => (int) $session->sequence,
                    'booking_id' => $session->booking_id,
                    'status' => $booking?->status ?? $session->status,
                    'start_at' => $session->scheduled_start_at,
                    'end_at' => $session->scheduled_end_at,
                    'progress_report' => $report ? [
                        'id' => $report->id,
                        'session_number' => $report->session_number,
                        'material_covered' => $report->material_covered,
                        'mastered_skills' => $report->mastered_skills,
                        'difficulties' => $report->difficulties,
                        'next_exercise' => $report->next_exercise,
                        'notes' => $report->notes,
                        'progress_percent' => $report->progress_percent,
                        'no_material_change' => (bool) $report->no_material_change,
                        'no_change_reason' => $report->no_change_reason,
                        'published_at' => $report->published_at,
                        'chapters' => PackageChapterProgress::chapterLogs($report->chapterLogs),
                    ] : null,
                ];
            })->values(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $booking = Booking::query()
            ->where('teacher_id', $request->user()->id)
            ->findOrFail($id);
        $validated = $request->validate([
            'meeting_link' => ['nullable', 'url:http,https', 'max:500'],
        ]);

        if ($booking->learning_mode !== 'online') {
            return response()->json(['message' => 'Tautan pertemuan hanya tersedia untuk kelas online.'], 422);
        }
        if (! in_array($booking->status, ['confirmed', 'in_progress'], true)) {
            return response()->json([
                'message' => 'Tautan pertemuan hanya dapat diubah setelah pembayaran dikonfirmasi dan sebelum sesi ditutup.',
            ], 422);
        }
        if (! $booking->participants()->whereHas('order', fn ($query) => $query->where('status', 'paid'))->exists()) {
            return response()->json(['message' => 'Belum ada pembayaran yang dikonfirmasi untuk sesi ini.'], 422);
        }
        if (now()->greaterThanOrEqualTo($booking->end_at)) {
            return response()->json(['message' => 'Tautan tidak dapat diubah setelah sesi berakhir.'], 422);
        }

        $booking->update($validated);

        return response()->json([
            'message' => 'Tautan pertemuan berhasil disimpan.',
            'data' => $booking->fresh(),
        ]);
    }
}
