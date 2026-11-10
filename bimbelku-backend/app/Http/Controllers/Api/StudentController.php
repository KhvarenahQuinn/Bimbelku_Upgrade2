<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Rating;
use App\Support\PackageChapterProgress;
use App\Support\PublicMedia;
use App\Support\RefundStatusPresenter;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function checkOrderStatus(Request $request, string $id)
    {
        $order = Order::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($query) use ($id) {
                // Numeric return IDs refer only to the primary key, never another invoice number.
                if (ctype_digit($id)) {
                    $query->whereKey($id);
                } else {
                    $query->where('order_id', $id);
                }
            })
            ->with(['refund', 'learningPackage.plan', 'cheapClassEnrollment.cheapClass'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Tagihan tidak ditemukan.'], 404);
        }

        $cheapEnrollment = $order->cheapClassEnrollment;
        $cheapClass = $cheapEnrollment?->cheapClass;
        $isCheapClass = $order->cheap_class_enrollment_id !== null;
        $canCancel = $isCheapClass
            && $cheapEnrollment
            && $cheapClass?->registration_deadline?->isFuture()
            && in_array($cheapEnrollment->status, ['seat_held', 'payment_rejected'], true)
            && in_array($order->status, ['pending', 'rejected'], true);
        $canResubmit = $isCheapClass
            && $cheapEnrollment?->status === 'payment_rejected'
            && $order->status === 'rejected'
            && $cheapEnrollment?->seat_expires_at?->isFuture()
            && $cheapClass?->registration_deadline?->isFuture()
            && in_array($cheapClass?->status, ['open', 'registration_closed', 'awaiting_verification'], true)
            && $cheapClass->enrollments()->where('status', 'confirmed')->count() < (int) $cheapClass->maximum_participants;
        $cheapClassCancelled = $isCheapClass
            && ($cheapEnrollment?->status === 'cancellation_pending' || $cheapClass?->status === 'cancelled');
        $capacityAlreadyFull = $isCheapClass
            && ! $cheapClassCancelled
            && $cheapClass
            && $cheapClass->enrollments()->where('status', 'confirmed')->count() >= (int) $cheapClass->maximum_participants;
        $refundReasonIfAccepted = $cheapClassCancelled
            ? 'class_cancelled'
            : ($capacityAlreadyFull ? 'capacity_full' : null);
        $willRefundIfAccepted = $isCheapClass
            && $order->status === 'submitted'
            && $refundReasonIfAccepted !== null;

        $details = is_array($order->class_details_snapshot)
            ? $order->class_details_snapshot
            : (json_decode((string) $order->class_details_snapshot, true) ?: []);

        return response()->json([
            'status' => $order->status,
            'id' => $order->id,
            'order_id' => $order->order_id,
            'order_number' => $order->order_id,
            'amount' => $order->amount,
            'subtotal_amount' => $order->subtotal_amount,
            'discount_amount' => $order->discount_amount,
            'payment_due_at' => $order->top_up_due_at ?? $order->learningPackage?->payment_due_at ?? $cheapEnrollment?->seat_expires_at,
            'subject' => $details['subject'] ?? 'Kelas',
            'tutor_name' => $details['teacher_name'] ?? 'Tutor dipilih setelah pembayaran',
            'type' => ucfirst((string) ($details['method'] ?? 'online')).' · '.($isCheapClass ? 'Kelas Bersama' : ($order->learning_package_id ? 'Paket Belajar' : 'Privat 1-on-1')),
            'scheduled_at' => $details['start_at'] ?? null,
            'package_name' => $details['package_name'] ?? $order->learningPackage?->plan?->name,
            'duration_hours' => (int) ($details['duration_hours'] ?? $order->learningPackage?->duration_hours ?? 1),
            'total_learning_hours' => (int) ($details['total_learning_hours'] ?? 0),
            'has_proof' => ! empty($order->payment_proof),
            'rejection_reason' => $order->payment_rejection_reason,
            'order_kind' => $isCheapClass ? 'cheap_class' : ($order->learning_package_id ? 'package' : 'booking'),
            'enrollment_status' => $cheapEnrollment?->status,
            'cheap_class_status' => $cheapClass?->status,
            'cheap_class_cancellation_reason' => $cheapClass?->cancellation_reason,
            'can_cancel' => (bool) $canCancel,
            'can_resubmit' => (bool) $canResubmit,
            'will_refund_if_accepted' => (bool) $willRefundIfAccepted,
            'refund_reason_if_accepted' => $order->status === 'submitted' ? $refundReasonIfAccepted : null,
            'wallet_reserved_amount' => (float) $order->wallet_reserved_amount,
            'wallet_applied_amount' => (float) $order->wallet_applied_amount,
            'external_payment_amount' => round(max(0, (float) $order->amount - (float) ($order->status === 'submitted' ? $order->wallet_reserved_amount : $order->wallet_applied_amount)), 2),
            'external_received_amount' => (float) $order->external_received_amount,
            'payment_outstanding_amount' => (float) $order->payment_outstanding_amount,
            'payment_surplus_amount' => (float) $order->payment_surplus_amount,
            'payment_reconciliation_status' => $order->payment_reconciliation_status,
            'top_up_due_at' => $order->top_up_due_at,
            'payment_provider' => $order->payment_provider,
            'gateway_status' => $order->gateway_status,
            'gateway_session_id' => $order->gateway_session_id,
            'gateway_expires_at' => $order->gateway_expires_at,
            'refund' => $order->refund ? array_merge($order->refund->toArray(), RefundStatusPresenter::present($order->refund)) : null,
        ]);
    }

    public function getMyClasses(Request $request)
    {
        $studentId = $request->user()->id;
        $bookings = Booking::query()
            ->where(function ($query) use ($studentId) {
                $query->whereHas('participants', fn ($participants) => $participants
                    ->where('student_id', $studentId)
                    ->whereNotIn('status', ['cancelled', 'teacher_rejected', 'payment_expired']))
                    ->orWhere(fn ($guest) => $guest
                        ->whereHas('order', fn ($order) => $order->where('status', 'paid'))
                        ->whereHas('packageSession.subject.package', fn ($package) => $package
                            ->whereIn('status', ['active', 'completed'])
                            ->whereHas('classJoins', fn ($joins) => $joins
                                ->where('student_id', $studentId)
                                ->where('status', 'approved'))));
            })
            ->with([
                'teacher.teacherProfile',
                'bookingRequest',
                'participants' => fn ($query) => $query->where('student_id', $studentId),
                'participants.bookingRequest',
                'participants.order.refund',
                'disputes' => fn ($query) => $query->where('student_id', $studentId)->latest(),
                'latestClassroomMessage.sender:id,name',
                // Relasi latestOfMany memilih laporan terbaru untuk seluruh kelas.
                // Pada kelas kelompok, laporan peserta lain dapat terpilih terlebih
                // dahulu ketika waktu publikasi sama. Data murid harus difilter
                // sebelum urutan terbaru ditentukan.
                'learningProgressReports' => fn ($query) => $query
                    ->where('student_id', $studentId)
                    ->with(['chapterLogs.chapter'])
                    ->orderByDesc('published_at')
                    ->orderByDesc('id'),
            ])
            ->withCount([
                'learningProgressReports as student_learning_progress_reports_count' => fn ($query) => $query->where('student_id', $studentId),
            ])
            ->latest('start_at')
            ->limit(200)
            ->get();
        $ratedBookingIds = Rating::query()
            ->where('student_id', $studentId)
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->pluck('booking_id')
            ->flip();

        $data = $bookings->map(function (Booking $booking) use ($ratedBookingIds) {
            $participant = $booking->participants->first();
            $isJoinedGuest = ! $participant;
            $learningRequest = $participant?->bookingRequest ?? $booking->bookingRequest;
            $profile = $booking->teacher?->teacherProfile;
            $hasSessionAccess = $participant?->order?->status === 'paid';
            $isRated = $ratedBookingIds->has($booking->id);
            $latestReport = $booking->learningProgressReports->first();
            $canApprove = $booking->status === 'awaiting_student_approval'
                && $participant?->status === 'awaiting_student_approval'
                && ! $participant?->approved_at
                && $booking->objection_deadline?->isFuture();
            $attention = $canApprove
                ? [
                    'kind' => 'review',
                    'title' => 'Sesi menunggu keputusanmu',
                    'message' => 'Tutor sudah menyimpan hasil belajar. Pilih Sesi Sesuai atau Ada masalah.',
                    'button_label' => 'Cek Sesi',
                    'target_url' => "/student/my-classes?session={$booking->id}&session_action=review",
                ]
                : ($participant && $booking->tutor_ready_at && ! $booking->student_confirmed_at && $booking->status === 'confirmed' ? [
                    'kind' => 'presence',
                    'title' => 'Tutor sudah siap',
                    'message' => 'Konfirmasi kehadiranmu dengan satu tap agar sesi dapat dimulai.',
                    'button_label' => 'Saya Sudah Hadir',
                    'target_url' => "/student/my-classes?session={$booking->id}&session_action=presence",
                ] : null);

            return [
                'id' => $booking->id,
                'viewer_only' => $isJoinedGuest,
                'request_id' => $learningRequest?->id,
                'package_subject_id' => $learningRequest?->package_subject_id,
                'title' => ($learningRequest?->subject_name ?? 'Bimbingan')
                    .' • '.($learningRequest?->chapter ?: 'Sesi belajar'),
                'subject' => $learningRequest?->subject_name ?? 'Bimbingan',
                'education_level' => $learningRequest?->education_level,
                'grade' => $learningRequest?->grade,
                'chapter' => $learningRequest?->chapter,
                'mentor' => $booking->teacher?->name ?? 'Tutor',
                'mentor_avatar' => PublicMedia::url($profile?->photo),
                'type' => 'Privat',
                'method' => $booking->learning_mode,
                'status' => $booking->status,
                'session_flow_version' => 'presence_confirmation_v2',
                'tutor_ready_at' => $booking->tutor_ready_at,
                'student_confirmed_at' => $booking->student_confirmed_at,
                'session_started_at' => $booking->session_started_at,
                'session_ended_at' => $booking->session_ended_at,
                'actual_duration_minutes' => $latestReport?->actual_duration_minutes,
                'scheduled_duration_minutes' => max(1, (int) $booking->duration_hours * 60),
                'session_focus_note' => $booking->session_focus_note,
                'participant_status' => $participant?->status ?? ($isJoinedGuest ? 'joined' : null),
                'start_at' => $booking->start_at,
                'end_at' => $booking->end_at,
                'payment_due_at' => $booking->payment_due_at,
                'address' => ($hasSessionAccess || $isJoinedGuest) && $booking->learning_mode === 'offline'
                    ? $booking->address
                    : null,
                'maps_link' => ($hasSessionAccess || $isJoinedGuest) && $booking->learning_mode === 'offline'
                    ? $booking->maps_link
                    : null,
                'meeting_link' => ($hasSessionAccess || $isJoinedGuest) && $booking->learning_mode === 'online'
                    ? $booking->meeting_link
                    : null,
                'amount' => $isJoinedGuest ? 0 : (float) ($participant?->amount ?? $booking->total_amount),
                'completion_notes' => $booking->completion_notes,
                'completion_submitted_at' => $booking->completion_submitted_at,
                'objection_deadline' => $booking->objection_deadline,
                'approved_at' => $participant?->approved_at,
                'order' => $participant?->order,
                'refund' => $participant?->order?->refund
                    ? array_merge($participant->order->refund->toArray(), RefundStatusPresenter::present($participant->order->refund))
                    : null,
                'dispute' => $booking->disputes->first(),
                'can_approve' => $canApprove,
                'can_dispute' => $canApprove,
                'attention' => $attention,
                'can_rate' => $booking->status === 'completed'
                    && $participant?->status === 'approved'
                    && ! $isRated,
                'can_report_teacher_absence' => in_array($booking->status, ['confirmed', 'in_progress'], true)
                    && $hasSessionAccess
                    && ! $booking->student_confirmed_at
                    && now()->gte($booking->start_at->copy()->addMinutes(15)),
                'workspace' => [
                    'can_open' => $hasSessionAccess,
                    'latest_message' => ! $isJoinedGuest && $booking->latestClassroomMessage ? [
                        'body' => $booking->latestClassroomMessage->body,
                        'sender_name' => $booking->latestClassroomMessage->sender?->name ?? 'Pengguna BimbelKu',
                        'created_at' => $booking->latestClassroomMessage->created_at,
                    ] : null,
                    'progress_percent' => (int) ($latestReport?->progress_percent ?? 0),
                    'progress_status' => $latestReport ? ((int) $latestReport->progress_percent >= 100 ? 'completed' : 'active') : 'not_started',
                    'report_count' => (int) $booking->student_learning_progress_reports_count,
                    'latest_report' => $latestReport ? [
                        'id' => $latestReport->id,
                        'session_number' => $latestReport->session_number,
                        'material_covered' => $latestReport->material_covered,
                        'mastered_skills' => $latestReport->mastered_skills,
                        'difficulties' => $latestReport->difficulties,
                        'next_exercise' => $latestReport->next_exercise,
                        'notes' => $latestReport->notes,
                        'progress_percent' => $latestReport->progress_percent,
                        'actual_duration_minutes' => $latestReport->actual_duration_minutes,
                        'no_material_change' => (bool) $latestReport->no_material_change,
                        'no_change_reason' => $latestReport->no_change_reason,
                        'published_at' => $latestReport->published_at,
                        'chapters' => PackageChapterProgress::chapterLogs($latestReport->chapterLogs),
                    ] : null,
                ],
            ];
        });

        return response()->json($data);
    }
}
