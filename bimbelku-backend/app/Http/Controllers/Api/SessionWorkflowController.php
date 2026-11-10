<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingDispute;
use App\Models\BookingParticipant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PackageChapter;
use App\Models\PackageSession;
use App\Models\PackageSessionChapterLog;
use App\Models\PromotionClaim;
use App\Models\Refund;
use App\Models\SessionReport;
use App\Models\TeacherReplacementRequest;
use App\Services\CustomerWalletService;
use App\Services\PartialPackageRefundService;
use App\Services\RefundSettlementService;
use App\Services\XenditMoneyMovementService;
use App\Support\RefundStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SessionWorkflowController extends Controller
{
    public function reportStudentAbsence(Request $request, Booking $booking)
    {
        $this->authorizeTeacher($request, $booking);
        $validated = $request->validate([
            'student_id' => ['nullable', 'integer', 'exists:users,id'],
            'chronology' => ['required', 'string', 'min:30', 'max:2500'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        if (! in_array($booking->status, ['confirmed', 'in_progress'], true)) {
            return response()->json(['message' => 'Ketidakhadiran tidak dapat dilaporkan pada status ini.'], 422);
        }
        if (now()->lt($booking->start_at->copy()->addMinutes(15))) {
            return response()->json([
                'message' => 'Laporan dapat dibuat setelah murid terlambat lebih dari 15 menit.',
            ], 422);
        }
        if ($this->isPresenceFlow($booking) && $booking->student_confirmed_at) {
            return response()->json(['message' => 'Kehadiran murid sudah terkonfirmasi. Gunakan alur masalah sesi bila ada kendala setelah kelas dimulai.'], 422);
        }

        $participant = $this->reportedParticipant($booking, $validated['student_id'] ?? null);
        $path = $request->file('evidence')->store('absence_evidence', 'local');

        try {
            $report = DB::transaction(function () use ($request, $booking, $participant, $validated, $path) {
                $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
                if (! in_array($lockedBooking->status, ['confirmed', 'in_progress'], true)) {
                    abort(422, 'Ketidakhadiran tidak dapat dilaporkan pada status ini.');
                }
                if (now()->lt($lockedBooking->start_at->copy()->addMinutes(15))) {
                    abort(422, 'Laporan dapat dibuat setelah murid terlambat lebih dari 15 menit.');
                }
                if ($this->isPresenceFlow($lockedBooking) && $lockedBooking->student_confirmed_at) {
                    abort(422, 'Kehadiran murid sudah terkonfirmasi. Gunakan alur masalah sesi bila ada kendala setelah kelas dimulai.');
                }

                $lockedParticipant = $lockedBooking->participants()
                    ->where('id', $participant->id)
                    ->where('status', 'paid')
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($lockedBooking->reports()
                    ->where('type', 'student_absence')
                    ->where('reported_student_id', $lockedParticipant->student_id)
                    ->where('status', 'pending')
                    ->exists()) {
                    abort(422, 'Laporan murid ini sedang diperiksa admin.');
                }

                $report = SessionReport::create([
                    'booking_id' => $lockedBooking->id,
                    'teacher_id' => $request->user()->id,
                    'reported_by' => $request->user()->id,
                    'reported_student_id' => $lockedParticipant->student_id,
                    'type' => 'student_absence',
                    'chronology' => $validated['chronology'],
                    'incident_at' => now(),
                    'evidence' => $path,
                    'status' => 'pending',
                ]);

                $lockedParticipant->update(['status' => 'absence_review']);
                $lockedParticipant->bookingRequest?->update(['status' => 'absence_review']);
                if ($lockedBooking->class_type === 'private') {
                    $lockedBooking->update(['status' => 'absence_review']);
                }

                Notification::updateOrCreate(
                    ['unique_key' => "session-report-student-absence:{$report->id}:{$lockedParticipant->student_id}"],
                    [
                        'user_id' => $lockedParticipant->student_id,
                        'title' => 'Ketidakhadiran dilaporkan',
                        'message' => 'Tutor melaporkan ketidakhadiran Anda. Admin akan memeriksa kronologi dan bukti.',
                        'type' => 'warning',
                        'target_url' => '/student/my-classes',
                        'is_read' => false,
                    ]
                );

                return $report;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'message' => 'Laporan ketidakhadiran berhasil dikirim untuk pemeriksaan admin.',
            'data' => $report,
        ], 201);
    }

    public function reportEmergency(Request $request, Booking $booking)
    {
        $this->authorizeTeacher($request, $booking);
        $validated = $request->validate([
            'incident_type' => ['required', 'string', 'max:120'],
            'chronology' => ['required', 'string', 'min:50', 'max:3000'],
            'incident_at' => ['required', 'date', 'before_or_equal:now'],
            'incident_location' => ['required', 'string', 'max:500'],
            'impact' => ['required', 'string', 'min:20', 'max:1500'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        if (in_array($booking->status, [
            'completed', 'refunded', 'cancelled', 'emergency_refund_pending',
            'refund_pending', 'partially_refunded', 'payment_expired',
        ], true)) {
            return response()->json(['message' => 'Sesi ini sudah ditutup.'], 422);
        }
        if ($booking->reports()->where('type', 'teacher_emergency')->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'Laporan keadaan darurat sesi ini sedang diperiksa.'], 422);
        }

        $path = $request->file('evidence')->store('emergency_evidence', 'local');

        try {
            $report = DB::transaction(function () use ($request, $booking, $validated, $path) {
                $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
                if (in_array($lockedBooking->status, [
                    'completed', 'refunded', 'cancelled', 'emergency_refund_pending',
                    'refund_pending', 'partially_refunded', 'payment_expired',
                ], true)) {
                    abort(422, 'Sesi ini sudah ditutup.');
                }
                if ($lockedBooking->reports()
                    ->where('type', 'teacher_emergency')
                    ->where('status', 'pending')
                    ->exists()) {
                    abort(422, 'Laporan keadaan darurat sesi ini sedang diperiksa.');
                }

                $report = SessionReport::create([
                    'booking_id' => $lockedBooking->id,
                    'teacher_id' => $request->user()->id,
                    'reported_by' => $request->user()->id,
                    'type' => 'teacher_emergency',
                    'incident_type' => $validated['incident_type'],
                    'chronology' => $validated['chronology'],
                    'incident_at' => $validated['incident_at'],
                    'incident_location' => $validated['incident_location'],
                    'impact' => $validated['impact'],
                    'evidence' => $path,
                    'status' => 'pending',
                ]);

                $lockedBooking->update([
                    'status' => 'emergency_refund_pending',
                    'payout_status' => 'cancelled',
                ]);

                $lockedBooking->participants()
                    ->with(['order', 'bookingRequest'])
                    ->lockForUpdate()
                    ->get()
                    ->each(function (BookingParticipant $participant) use ($lockedBooking) {
                        if ($participant->order?->status === 'paid') {
                            $this->queueRefund(
                                $participant,
                                $lockedBooking,
                                'Keadaan darurat tutor'
                            );
                        } else {
                            $participant->order?->update(['status' => 'cancelled']);
                            $participant->update(['status' => 'cancelled']);
                            $participant->bookingRequest?->update(['status' => 'cancelled']);
                        }

                        Notification::updateOrCreate(
                            ['unique_key' => "session-emergency-cancelled:{$lockedBooking->id}:{$participant->student_id}"],
                            [
                                'user_id' => $participant->student_id,
                                'title' => 'Sesi dibatalkan karena keadaan darurat',
                                'message' => $participant->order?->status === 'refund_pending'
                                    ? 'Refund penuh sedang diproses admin.'
                                    : 'Tagihan dibatalkan dan tidak perlu dibayar.',
                                'type' => 'warning',
                                'target_url' => '/student/history',
                                'is_read' => false,
                            ]
                        );
                    });

                return $report;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'message' => 'Keadaan darurat tercatat. Refund penuh murid langsung masuk antrean admin.',
            'data' => $report,
        ], 201);
    }

    public function studentApprove(Request $request, Booking $booking)
    {
        $participant = $this->studentParticipant($request, $booking);

        if ($participant->approved_at || $participant->status === 'approved') {
            return response()->json(['message' => 'Penyelesaian sesi sudah disetujui.'], 422);
        }
        if ($participant->status !== 'awaiting_student_approval') {
            return response()->json(['message' => 'Peserta ini tidak sedang menunggu keputusan penyelesaian.'], 422);
        }
        if ($booking->status !== 'awaiting_student_approval') {
            return response()->json(['message' => 'Sesi belum menunggu persetujuan Anda.'], 422);
        }
        if ($booking->objection_deadline?->isPast()) {
            return response()->json([
                'message' => 'Masa keputusan sudah berakhir dan sesi akan diperiksa admin.',
            ], 422);
        }

        DB::transaction(function () use ($request, $booking) {
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $lockedParticipant = $lockedBooking->participants()
                ->where('student_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedBooking->status !== 'awaiting_student_approval'
                || $lockedParticipant->status !== 'awaiting_student_approval'
                || $lockedParticipant->approved_at
            ) {
                abort(422, 'Penyelesaian sesi ini sudah diproses atau statusnya telah berubah.');
            }
            if ($lockedBooking->objection_deadline?->isPast()) {
                abort(422, 'Masa keputusan sudah berakhir dan sesi akan diperiksa admin.');
            }

            if ($this->isPresenceFlow($lockedBooking)) {
                $this->applyPendingPackageProgress($lockedBooking);
            }

            $lockedParticipant->update(['status' => 'approved', 'approved_at' => now()]);
            $lockedParticipant->bookingRequest?->update(['status' => 'completed']);
            $this->finalizeBookingIfSettled($lockedBooking);
        });

        return response()->json([
            'message' => 'Sesi disetujui dan tidak dapat diajukan keberatan kembali.',
        ]);
    }

    public function studentDispute(Request $request, Booking $booking)
    {
        $participant = $this->studentParticipant($request, $booking);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:30', 'max:3000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        if ($participant->approved_at) {
            return response()->json([
                'message' => 'Keberatan tidak dapat diajukan setelah penyelesaian disetujui.',
            ], 422);
        }
        if ($participant->status !== 'awaiting_student_approval') {
            return response()->json(['message' => 'Peserta ini tidak sedang menunggu keputusan penyelesaian.'], 422);
        }
        if ($booking->status !== 'awaiting_student_approval') {
            return response()->json(['message' => 'Sesi belum dapat diajukan keberatan.'], 422);
        }
        if ($booking->objection_deadline?->isPast()) {
            return response()->json(['message' => 'Masa pengajuan keberatan sudah berakhir.'], 422);
        }

        $path = $request->hasFile('evidence')
            ? $request->file('evidence')->store('dispute_evidence', 'local')
            : null;

        try {
            $dispute = DB::transaction(function () use ($request, $booking, $validated, $path) {
                $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
                $lockedParticipant = $lockedBooking->participants()
                    ->where('student_id', $request->user()->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedBooking->status !== 'awaiting_student_approval'
                    || $lockedParticipant->status !== 'awaiting_student_approval'
                    || $lockedParticipant->approved_at
                ) {
                    abort(422, 'Keberatan tidak dapat dibuat karena status sesi telah berubah.');
                }
                if ($lockedBooking->objection_deadline?->isPast()) {
                    abort(422, 'Masa pengajuan keberatan sudah berakhir.');
                }
                if (BookingDispute::query()
                    ->where('booking_id', $lockedBooking->id)
                    ->where('student_id', $request->user()->id)
                    ->exists()) {
                    abort(422, 'Keberatan untuk sesi ini sudah pernah dikirim.');
                }

                $dispute = BookingDispute::create([
                    'booking_id' => $lockedBooking->id,
                    'student_id' => $request->user()->id,
                    'reason' => $validated['reason'],
                    'evidence' => $path,
                    'status' => 'pending',
                ]);
                $lockedParticipant->update(['status' => 'disputed']);
                $lockedParticipant->bookingRequest?->update(['status' => 'disputed']);
                $lockedBooking->update(['status' => 'disputed', 'payout_status' => 'locked']);

                Notification::updateOrCreate(
                    ['unique_key' => "session-dispute:{$dispute->id}:{$lockedBooking->teacher_id}"],
                    [
                        'user_id' => $lockedBooking->teacher_id,
                        'title' => 'Murid mengajukan keberatan',
                        'message' => 'Pencairan ditahan sampai admin menyelesaikan pemeriksaan.',
                        'type' => 'warning',
                        'target_url' => '/guru/kelas',
                        'is_read' => false,
                    ]
                );

                return $dispute;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return response()->json([
            'message' => 'Keberatan berhasil dikirim. Dana tutor ditahan selama pemeriksaan.',
            'data' => $dispute,
        ], 201);
    }

    public function reportTeacherAbsence(Request $request, Booking $booking)
    {
        $participant = $this->studentParticipant($request, $booking);
        $validated = $request->validate([
            'chronology' => ['required', 'string', 'min:30', 'max:2500'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        if (! in_array($booking->status, ['confirmed', 'in_progress'], true)) {
            return response()->json(['message' => 'Ketidakhadiran tutor tidak dapat dilaporkan pada status ini.'], 422);
        }
        if (now()->lt($booking->start_at->copy()->addMinutes(15))) {
            return response()->json([
                'message' => 'Laporan dapat dibuat setelah tutor terlambat lebih dari 15 menit.',
            ], 422);
        }
        if ($this->isPresenceFlow($booking) && $booking->student_confirmed_at) {
            return response()->json(['message' => 'Sesi sudah dimulai dan kehadiran telah dikonfirmasi. Gunakan Ada masalah setelah sesi bila pelaksanaannya tidak sesuai.'], 422);
        }
        if ($booking->reports()->where('type', 'teacher_absence')->where('reported_by', $request->user()->id)->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'Laporan Anda sedang diperiksa admin.'], 422);
        }

        $path = $request->file('evidence')->store('teacher_absence_evidence', 'local');
        try {
            $report = DB::transaction(function () use ($request, $booking, $participant, $validated, $path) {
                $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
                if (! in_array($lockedBooking->status, ['confirmed', 'in_progress'], true)) {
                    abort(422, 'Ketidakhadiran tutor tidak dapat dilaporkan pada status ini.');
                }
                if (now()->lt($lockedBooking->start_at->copy()->addMinutes(15))) {
                    abort(422, 'Laporan dapat dibuat setelah tutor terlambat lebih dari 15 menit.');
                }
                if ($this->isPresenceFlow($lockedBooking) && $lockedBooking->student_confirmed_at) {
                    abort(422, 'Sesi sudah dimulai dan kehadiran telah dikonfirmasi. Gunakan Ada masalah setelah sesi bila pelaksanaannya tidak sesuai.');
                }
                if ($lockedBooking->reports()
                    ->where('type', 'teacher_absence')
                    ->where('reported_by', $request->user()->id)
                    ->where('status', 'pending')
                    ->exists()) {
                    abort(422, 'Laporan Anda sedang diperiksa admin.');
                }

                $lockedParticipant = $lockedBooking->participants()
                    ->where('id', $participant->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $report = SessionReport::create([
                    'booking_id' => $lockedBooking->id,
                    'teacher_id' => $lockedBooking->teacher_id,
                    'reported_by' => $request->user()->id,
                    'type' => 'teacher_absence',
                    'chronology' => $validated['chronology'],
                    'incident_at' => now(),
                    'evidence' => $path,
                    'status' => 'pending',
                ]);
                $lockedParticipant->update(['status' => 'teacher_absence_review']);
                $lockedParticipant->bookingRequest?->update(['status' => 'teacher_absence_review']);
                $lockedBooking->update([
                    'status' => 'absence_review',
                    'payout_status' => 'locked',
                ]);

                Notification::updateOrCreate(
                    ['unique_key' => "session-report-teacher-absence:{$report->id}:{$lockedBooking->teacher_id}"],
                    [
                        'user_id' => $lockedBooking->teacher_id,
                        'title' => 'Ketidakhadiran Anda dilaporkan',
                        'message' => 'Murid mengirim kronologi dan bukti. Admin akan memeriksa laporan sebelum memutus refund dan sanksi.',
                        'type' => 'warning',
                        'target_url' => '/guru/performa',
                        'is_read' => false,
                    ]
                );

                return $report;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'message' => 'Laporan ketidakhadiran tutor berhasil dikirim untuk pemeriksaan admin.',
            'data' => $report,
        ], 201);
    }

    public function adminCases(Request $request)
    {
        $scope = (string) $request->query('scope', 'active');
        abort_unless(in_array($scope, ['active', 'history'], true), 422, 'Scope pusat kasus tidak valid.');
        $active = $scope === 'active';

        return response()->json([
            'session_reports' => SessionReport::query()
                ->when($active, fn ($query) => $query->where('status', 'pending'))
                ->when(! $active, fn ($query) => $query->where('status', '!=', 'pending'))
                ->with(['booking.teacher', 'reportedStudent', 'reporter', 'teacher', 'reviewer'])
                ->latest()
                ->limit(200)
                ->get(),
            'disputes' => BookingDispute::query()
                ->when($active, fn ($query) => $query->where('status', 'pending'))
                ->when(! $active, fn ($query) => $query->where('status', '!=', 'pending'))
                ->with(['booking.teacher', 'booking.latestLearningProgressReport', 'student', 'resolver'])
                ->latest()
                ->limit(200)
                ->get(),
            'completion_reviews' => Booking::query()
                ->whereNotNull('admin_review_required_at')
                ->when($active, fn ($query) => $query->where('status', 'admin_review_required'))
                ->when(! $active, fn ($query) => $query->where('status', '!=', 'admin_review_required'))
                ->with(['teacher', 'participants.student', 'latestLearningProgressReport'])
                ->latest('admin_review_required_at')
                ->limit(200)
                ->get(),
            'refunds' => Refund::query()
                ->where('status', 'pending')
                ->with(['user', 'booking.teacher', 'order'])
                ->latest()
                ->limit(200)
                ->get(),
            'teacher_replacements' => config('features.teacher_replacement')
                ? TeacherReplacementRequest::query()
                    ->when($active, fn ($query) => $query->whereIn('status', TeacherReplacementRequest::OPEN_STATUSES))
                    ->when(! $active, fn ($query) => $query->whereNotIn('status', TeacherReplacementRequest::OPEN_STATUSES))
                    ->with([
                        'student:id,name,email',
                        'oldTeacher:id,name',
                        'newTeacher:id,name',
                        'reviewer:id,name',
                        'package:id,package_code,status',
                        'subject:id,subject_name,status',
                        'sessions.packageSession',
                        'sessions.oldBooking.reports',
                        'sessions.oldBooking.disputes',
                    ])
                    ->latest()
                    ->limit(200)
                    ->get()
                : [],
            'features' => [
                'teacher_replacement' => (bool) config('features.teacher_replacement'),
            ],
            'scope' => $scope,
        ]);
    }

    public function resolveDispute(
        Request $request,
        BookingDispute $bookingDispute
    ) {
        $validated = $request->validate([
            'resolution' => ['required', Rule::in(['student_refund', 'teacher_paid'])],
            'notes' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        if ($bookingDispute->status !== 'pending') {
            return response()->json(['message' => 'Keberatan ini sudah diputuskan.'], 422);
        }

        DB::transaction(function () use ($request, $bookingDispute, $validated) {
            $lockedDispute = BookingDispute::query()
                ->lockForUpdate()
                ->findOrFail($bookingDispute->id);
            if ($lockedDispute->status !== 'pending') {
                abort(422, 'Keberatan ini sudah diputuskan.');
            }

            $booking = $lockedDispute->booking()->lockForUpdate()->firstOrFail();
            $participant = $booking->participants()
                ->where('student_id', $lockedDispute->student_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDispute->update([
                'status' => 'resolved',
                'resolution' => $validated['resolution'],
                'resolution_notes' => $validated['notes'],
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ]);

            if ($validated['resolution'] === 'student_refund') {
                $this->queueRefund($participant, $booking, 'Keberatan murid disetujui');
                $booking->update([
                    'status' => 'refund_pending',
                    'payout_status' => 'locked',
                ]);
            } else {
                if ($this->isPresenceFlow($booking)) {
                    $this->applyPendingPackageProgress($booking);
                }
                $participant->update(['status' => 'approved', 'approved_at' => now()]);
                $participant->bookingRequest?->update(['status' => 'completed']);
                $this->finalizeBookingIfSettled($booking);
            }

            Notification::updateOrCreate(
                ['unique_key' => "session-dispute-resolved:{$lockedDispute->id}:{$lockedDispute->student_id}"],
                [
                    'user_id' => $lockedDispute->student_id,
                    'title' => 'Keberatan telah diputuskan',
                    'message' => $validated['resolution'] === 'student_refund'
                        ? 'Keberatan disetujui. Refund penuh masuk antrean transfer.'
                        : 'Sesi dinyatakan valid dan hak tutor diteruskan.',
                    'type' => 'info',
                    'target_url' => $validated['resolution'] === 'student_refund'
                        ? '/student/history'
                        : '/student/my-classes',
                    'is_read' => false,
                ]
            );
        });

        return response()->json(['message' => 'Keputusan keberatan berhasil disimpan.']);
    }

    public function resolveReport(
        Request $request,
        SessionReport $sessionReport
    ) {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'rejected'])],
            'notes' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        if ($sessionReport->status !== 'pending') {
            return response()->json(['message' => 'Laporan ini sudah diperiksa.'], 422);
        }

        DB::transaction(function () use ($request, $sessionReport, $validated) {
            $lockedReport = SessionReport::query()
                ->lockForUpdate()
                ->findOrFail($sessionReport->id);
            if ($lockedReport->status !== 'pending') {
                abort(422, 'Laporan ini sudah diperiksa.');
            }

            $booking = $lockedReport->booking()->lockForUpdate()->firstOrFail();
            $accepted = $validated['decision'] === 'accepted';
            $lockedReport->update([
                'status' => $validated['decision'],
                'reviewed_by' => $request->user()->id,
                'review_notes' => $validated['notes'],
                'reviewed_at' => now(),
            ]);

            if ($lockedReport->type === 'teacher_emergency') {
                Notification::updateOrCreate(
                    ['unique_key' => "session-report-emergency-reviewed:{$lockedReport->id}:{$booking->teacher_id}"],
                    [
                        'user_id' => $booking->teacher_id,
                        'title' => 'Laporan keadaan darurat diperiksa',
                        'message' => $accepted
                            ? 'Laporan keadaan darurat diterima. Refund peserta tetap diproses.'
                            : 'Laporan keadaan darurat ditolak. Refund peserta tetap diproses.',
                        'type' => $accepted ? 'info' : 'warning',
                        'target_url' => '/guru/performa',
                        'is_read' => false,
                    ]
                );

                return;
            }

            if ($lockedReport->type === 'teacher_absence') {
                $participant = $booking->participants()
                    ->where('student_id', $lockedReport->reported_by)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($accepted) {
                    // Satu kejadian dapat dilaporkan beberapa murid kelompok.
                    // Semua laporan sejawat ditutup bersama agar refund dan
                    // sanksi tutor hanya diproses satu kali.
                    SessionReport::query()
                        ->where('booking_id', $booking->id)
                        ->where('type', 'teacher_absence')
                        ->where('status', 'pending')
                        ->lockForUpdate()
                        ->get()
                        ->each->update([
                            'status' => 'accepted',
                            'reviewed_by' => $request->user()->id,
                            'review_notes' => 'Diselesaikan bersama laporan #'.$lockedReport->id
                                .': '.$validated['notes'],
                            'reviewed_at' => now(),
                        ]);

                    $booking->participants()
                        ->with(['order', 'bookingRequest'])
                        ->whereHas('order', fn ($query) => $query->where('status', 'paid'))
                        ->get()
                        ->each(function (BookingParticipant $affected) use ($booking) {
                            $this->queueRefund($affected, $booking, 'Tutor tidak hadir');
                            Notification::updateOrCreate(
                                ['unique_key' => "session-teacher-absence-refund:{$lockedReport->id}:{$affected->student_id}"],
                                [
                                    'user_id' => $affected->student_id,
                                    'title' => 'Refund ketidakhadiran tutor',
                                    'message' => 'Laporan diterima. Refund penuh masuk antrean. Pilih tujuan refund di Riwayat Transaksi.',
                                    'type' => 'success',
                                    'target_url' => '/student/history',
                                    'is_read' => false,
                                ]
                            );
                        });
                    $booking->update([
                        'status' => 'refund_pending',
                        'payout_status' => 'cancelled',
                    ]);
                } else {
                    $restoredStatus = $this->restoredStatusAfterReport($booking);
                    $otherPendingReports = SessionReport::query()
                        ->where('booking_id', $booking->id)
                        ->where('type', 'teacher_absence')
                        ->where('status', 'pending')
                        ->lockForUpdate()
                        ->get()
                        ->isNotEmpty();
                    $participantStatus = $booking->learningProgressReports()->exists() ? 'awaiting_student_approval' : 'paid';
                    $participant->update(['status' => $participantStatus]);
                    $participant->bookingRequest?->update(['status' => $restoredStatus]);
                    $booking->update([
                        'status' => $otherPendingReports ? 'absence_review' : $restoredStatus,
                        'payout_status' => $otherPendingReports
                            ? 'locked'
                            : $booking->payout_status,
                        'admin_review_required_at' => ! $otherPendingReports
                            && $restoredStatus === 'admin_review_required'
                            ? now()
                            : null,
                    ]);
                }

                if (! $accepted) {
                    Notification::updateOrCreate(
                        ['unique_key' => "session-report-not-proven:{$lockedReport->id}:{$lockedReport->reported_by}"],
                        [
                            'user_id' => $lockedReport->reported_by,
                            'title' => 'Laporan ketidakhadiran tutor diputuskan',
                            'message' => $otherPendingReports
                                ? 'Laporan Anda belum terbukti. Laporan peserta lain pada sesi yang sama masih diperiksa.'
                                : 'Laporan belum terbukti. Sesi dikembalikan ke proses pelaksanaan.',
                            'type' => 'info',
                            'target_url' => '/student/my-classes',
                            'is_read' => false,
                        ]
                    );
                }

                return;
            }

            $participant = $booking->participants()
                ->where('student_id', $lockedReport->reported_student_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($accepted) {
                $participant->update(['status' => 'no_show_confirmed', 'approved_at' => now()]);
                $participant->bookingRequest?->update(['status' => 'completed']);
                $this->finalizeBookingIfSettled($booking);
            } else {
                $restoredStatus = $this->restoredStatusAfterReport($booking);
                $participant->update([
                    'status' => $booking->learningProgressReports()->exists() ? 'awaiting_student_approval' : 'paid',
                ]);
                $participant->bookingRequest?->update(['status' => $restoredStatus]);
                $booking->update([
                    'status' => $restoredStatus,
                    'admin_review_required_at' => $restoredStatus === 'admin_review_required'
                        ? now()
                        : null,
                ]);
            }

            Notification::updateOrCreate(
                ['unique_key' => "session-student-absence-decision:{$lockedReport->id}:{$participant->student_id}"],
                [
                    'user_id' => $participant->student_id,
                    'title' => 'Laporan ketidakhadiran murid diputuskan',
                    'message' => $accepted
                        ? 'Laporan diterima. Pembayaran sesi dinyatakan hangus sesuai kebijakan.'
                        : 'Laporan ditolak. Status keikutsertaan Anda dikembalikan.',
                    'type' => $accepted ? 'warning' : 'info',
                    'target_url' => '/student/my-classes',
                    'is_read' => false,
                ]
            );
            Notification::updateOrCreate(
                ['unique_key' => "session-student-absence-decision-teacher:{$lockedReport->id}:{$booking->teacher_id}"],
                [
                    'user_id' => $booking->teacher_id,
                    'title' => 'Laporan ketidakhadiran murid diputuskan',
                    'message' => $accepted
                        ? 'Laporan diterima dan pembayaran murid dihitung sebagai pendapatan sesi.'
                        : 'Laporan ditolak.',
                    'type' => $accepted ? 'success' : 'warning',
                    'target_url' => '/guru/performa',
                    'is_read' => false,
                ]
            );
        });

        return response()->json(['message' => 'Hasil pemeriksaan laporan berhasil disimpan.']);
    }

    public function resolveCompletionReview(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'refund'])],
            'notes' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        if ($booking->status !== 'admin_review_required') {
            return response()->json(['message' => 'Sesi ini tidak menunggu pemeriksaan admin.'], 422);
        }

        DB::transaction(function () use ($booking, $validated) {
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($lockedBooking->status !== 'admin_review_required') {
                abort(422, 'Sesi ini tidak menunggu pemeriksaan admin.');
            }

            if ($validated['action'] === 'approve' && $this->isPresenceFlow($lockedBooking)) {
                $this->applyPendingPackageProgress($lockedBooking);
            }

            $lockedBooking->participants()->whereIn('status', [
                'paid',
                'awaiting_student_approval',
                'admin_review_required',
            ])
                ->with(['bookingRequest', 'order'])
                ->lockForUpdate()
                ->get()
                ->each(function (BookingParticipant $participant) use ($lockedBooking, $validated) {
                    if ($validated['action'] === 'approve') {
                        $participant->update(['status' => 'approved', 'approved_at' => now()]);
                        $participant->bookingRequest?->update(['status' => 'completed']);
                    } else {
                        $this->queueRefund(
                            $participant,
                            $lockedBooking,
                            'Pemeriksaan sesi oleh admin'
                        );
                    }
                });

            if ($validated['action'] === 'approve') {
                $this->finalizeBookingIfSettled($lockedBooking);
            } else {
                $lockedBooking->update([
                    'status' => 'refund_pending',
                    'payout_status' => 'cancelled',
                ]);
            }
        });

        return response()->json(['message' => 'Pemeriksaan sesi berhasil diselesaikan.']);
    }

    public function completeRefund(
        Request $request,
        Refund $refund,
        CustomerWalletService $wallets,
        PartialPackageRefundService $partialPackageRefunds,
        XenditMoneyMovementService $xendit,
        RefundSettlementService $settlement
    ) {
        $refund->refresh()->loadMissing('order');
        $breakdown = $refund->tenderBreakdown();
        if ($refund->order?->payment_provider === 'xendit' && $breakdown['external_funded_amount'] > 0.009) {
            abort_unless(in_array($refund->status, ['pending', 'failed'], true), 422, 'Refund ini sudah diproses.');
            try {
                $data = $xendit->createRefund($refund);
                $refund->refresh();
                if ($refund->gatewayFailed()) {
                    $settlement->markGatewayFailure($refund);

                    return response()->json([
                        'message' => 'Pengembalian ke metode pembayaran asal gagal. Murid dapat memilih Saldo BimbelKu atau admin mencoba ulang.',
                        'data' => $data,
                        'wallet_funded_amount' => $breakdown['wallet_funded_amount'],
                        'external_funded_amount' => $breakdown['external_funded_amount'],
                    ], 502);
                }

                return response()->json([
                    'message' => RefundStatusPresenter::present($refund->fresh())['status_message'],
                    'data' => $data,
                    'wallet_funded_amount' => $breakdown['wallet_funded_amount'],
                    'external_funded_amount' => $breakdown['external_funded_amount'],
                ], 202);
            } catch (\RuntimeException $exception) {
                $refund->refresh();
                if ($refund->gatewayFailed()) {
                    $settlement->markGatewayFailure($refund);
                }

                return response()->json(['message' => $exception->getMessage()], 502);
            }
        }

        $validated = $request->validate([
            'proof' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'destination_selection_version' => ['required', 'integer', 'min:1'],
        ]);

        $refund->refresh();
        if ($refund->status !== 'pending') {
            return response()->json(['message' => 'Refund ini sudah diproses.'], 422);
        }
        if (
            ! in_array($refund->destination_method, ['bank_transfer', 'bimbelku_balance'], true)
            || ! $refund->destination_selected_at
        ) {
            return response()->json([
                'message' => 'Murid belum memilih tujuan refund. Tunggu pilihan rekening/e-wallet atau Saldo BimbelKu.',
            ], 422);
        }

        // Snapshot awal hanya dipakai untuk menentukan kebutuhan upload.
        // Tujuan dan sumber dana diperiksa ulang di dalam row lock agar perubahan
        // murid yang berbarengan dengan proses admin tidak pernah dieksekusi stale.
        $refund->loadMissing('order');
        $requestedBreakdown = $refund->tenderBreakdown();
        $requestedDestinationMethod = $refund->destination_method;
        $requestedDestinationVersion = (int) $validated['destination_selection_version'];
        if ((int) $refund->destination_selection_version !== $requestedDestinationVersion) {
            return response()->json([
                'message' => 'Pilihan tujuan refund sudah diperbarui oleh murid. Muat ulang data refund sebelum memproses.',
            ], 409);
        }
        $isBankTransfer = $requestedDestinationMethod === 'bank_transfer';
        if ($isBankTransfer && $requestedBreakdown['external_funded_amount'] <= 0.009) {
            return response()->json([
                'message' => 'Refund ini seluruhnya berasal dari Saldo BimbelKu dan tidak boleh dicairkan ke rekening/e-wallet.',
            ], 422);
        }
        if ($isBankTransfer && (
            blank($refund->destination_bank_name)
            || blank($refund->destination_account_name)
            || blank($refund->destination_account_number)
        )) {
            return response()->json([
                'message' => 'Tujuan refund rekening/e-wallet belum lengkap. Minta murid memperbarui pilihan refund.',
            ], 422);
        }
        if ($isBankTransfer && $requestedBreakdown['external_funded_amount'] > 0.009 && ! $request->hasFile('proof')) {
            return response()->json([
                'message' => 'Bukti transfer refund wajib diunggah untuk bagian refund yang dikembalikan ke rekening/e-wallet.',
                'errors' => ['proof' => ['Bukti transfer refund wajib diunggah.']],
            ], 422);
        }

        $path = $isBankTransfer
            ? $request->file('proof')->store('refund_proofs', 'local')
            : null;
        $walletTransaction = null;
        $completedBreakdown = $requestedBreakdown;

        try {
            DB::transaction(function () use (
                $request,
                $refund,
                $validated,
                $path,
                $wallets,
                $requestedDestinationMethod,
                $requestedDestinationVersion,
                $partialPackageRefunds,
                &$walletTransaction,
                &$completedBreakdown
            ) {
                $lockedRefund = Refund::query()
                    ->with([
                        'order.participant.bookingRequest',
                        'order.cheapClassEnrollment',
                        'booking',
                    ])
                    ->lockForUpdate()
                    ->findOrFail($refund->id);

                if ($lockedRefund->status !== 'pending') {
                    abort(422, 'Refund ini sudah diproses.');
                }

                $destinationMethod = $lockedRefund->destination_method;
                abort_unless(
                    in_array($destinationMethod, ['bank_transfer', 'bimbelku_balance'], true)
                        && $lockedRefund->destination_selected_at,
                    422,
                    'Murid belum memilih tujuan refund.'
                );
                abort_if(
                    $destinationMethod !== $requestedDestinationMethod
                        || (int) $lockedRefund->destination_selection_version !== $requestedDestinationVersion,
                    409,
                    'Tujuan refund berubah saat diproses. Muat ulang data refund lalu coba lagi.'
                );

                $lockedRefund->loadMissing('order');
                $completedBreakdown = $lockedRefund->tenderBreakdown();

                if ($destinationMethod === 'bank_transfer') {
                    abort_if(
                        $completedBreakdown['external_funded_amount'] <= 0.009,
                        422,
                        'Refund ini seluruhnya berasal dari Saldo BimbelKu dan tidak boleh dicairkan ke rekening/e-wallet.'
                    );
                    abort_if(
                        blank($lockedRefund->destination_bank_name)
                            || blank($lockedRefund->destination_account_name)
                            || blank($lockedRefund->destination_account_number),
                        422,
                        'Tujuan refund rekening/e-wallet belum lengkap.'
                    );
                    abort_if(! $path, 422, 'Bukti transfer refund wajib diunggah untuk bagian refund rekening/e-wallet.');

                    // Store credit tidak dapat dicairkan. Jika pesanan dulu dibayar
                    // sebagian dengan Saldo BimbelKu, bagian tersebut otomatis
                    // kembali ke saldo; admin hanya mentransfer bagian eksternal.
                    if ($completedBreakdown['wallet_funded_amount'] > 0.009) {
                        $walletTransaction = $wallets->creditRefund(
                            $lockedRefund,
                            (int) $request->user()->id,
                            $completedBreakdown['wallet_funded_amount']
                        );
                    }
                } else {
                    // Jika murid beralih ke Saldo BimbelKu sebelum row lock
                    // didapat admin, jangan simpan bukti transfer untuk tujuan lama.
                    abort_if($path !== null, 409, 'Tujuan refund berubah saat diproses. Muat ulang data refund lalu coba lagi.');
                    $walletTransaction = $wallets->creditRefund($lockedRefund, (int) $request->user()->id);
                }

                $lockedRefund->update([
                    'status' => 'paid',
                    'destination_method' => $destinationMethod,
                    // Tujuan sudah dipilih dan disnapshot oleh murid. Admin hanya
                    // mengeksekusi tujuan tersebut dan tidak dapat menggantinya.
                    'proof' => $path,
                    'processed_by' => $request->user()->id,
                    'processed_at' => now(),
                    'notes' => $validated['notes'] ?? null,
                ]);
                $isTeacherReplacement = $lockedRefund->source_type === 'teacher_replacement';
                if (! $isTeacherReplacement) {
                    $lockedRefund->order->update(['status' => 'refunded']);
                    $this->restorePromotionClaimForRefund($lockedRefund->order, (string) $lockedRefund->reason);
                    $participant = $lockedRefund->order->participant;
                    $participant?->update(['status' => 'refunded']);
                    $participant?->bookingRequest?->update(['status' => 'refunded']);
                    $lockedRefund->order->cheapClassEnrollment?->update(['status' => 'refunded']);

                    $package = $lockedRefund->order->learningPackage;
                    if ($package && in_array($package->status, [
                        'refund_pending', 'no_teacher', 'matching', 'teacher_pending', 'cancelled', 'payment_expired', 'refunded',
                    ], true)) {
                        $package->update(['status' => 'refunded']);
                        $package->subjects()->update(['status' => 'refunded']);
                        app(\App\Services\ScheduleReleaseService::class)->releasePackage($package, 'refunded');
                    }
                } else {
                    $partialPackageRefunds->completeTeacherReplacementRefund($lockedRefund->fresh());
                }

                $remainingRefunds = $lockedRefund->booking?->refunds()->where('status', 'pending')->count() ?? 0;
                if ($lockedRefund->booking && $remainingRefunds === 0) {
                    $settledStatuses = [
                        'approved', 'no_show_confirmed', 'refunded', 'cancelled', 'teacher_rejected',
                    ];
                    $unsettled = $lockedRefund->booking->participants()
                        ->whereNotIn('status', $settledStatuses)
                        ->exists();
                    $earningParticipants = $lockedRefund->booking->participants()
                        ->whereIn('status', ['approved', 'no_show_confirmed'])
                        ->count();
                    $gross = $earningParticipants * (float) $lockedRefund->booking->total_amount;
                    $net = round(
                        $gross * (100 - (float) $lockedRefund->booking->commission_percent) / 100
                    );

                    $lockedRefund->booking->update([
                        'status' => $unsettled
                            ? 'partially_refunded'
                            : ($earningParticipants > 0 ? 'completed' : 'refunded'),
                        'gross_amount' => $gross,
                        'teacher_net_amount' => $net,
                        'payout_status' => $unsettled
                            ? 'locked'
                            : ($earningParticipants > 0 ? 'ready' : 'cancelled'),
                    ]);
                }

                $walletDestination = $destinationMethod === 'bimbelku_balance';
                $walletFunded = $completedBreakdown['wallet_funded_amount'];
                $externalFunded = $completedBreakdown['external_funded_amount'];
                $message = $walletDestination
                    ? 'Refund penuh sudah masuk ke Saldo BimbelKu dan tercatat pada riwayat saldo.'
                    : ($walletFunded > 0.009
                        ? 'Refund selesai: Rp '.number_format($walletFunded, 0, ',', '.').' kembali ke Saldo BimbelKu dan Rp '.number_format($externalFunded, 0, ',', '.').' ditransfer ke rekening/e-wallet pilihanmu.'
                        : 'Refund penuh telah dikirim oleh admin. Bukti transfer tersedia pada riwayat transaksi.');

                Notification::create([
                    'user_id' => $lockedRefund->user_id,
                    'title' => $walletDestination ? 'Refund masuk ke Saldo BimbelKu' : 'Refund selesai',
                    'message' => $message,
                    'type' => 'success',
                    'target_url' => '/student/history',
                    'unique_key' => "refund-completed:{$lockedRefund->id}",
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        $completedRefund = $refund->fresh();
        $message = $completedRefund->destination_method === 'bimbelku_balance'
            ? 'Refund berhasil dimasukkan ke Saldo BimbelKu.'
            : ($completedBreakdown['wallet_funded_amount'] > 0.009
                ? 'Refund selesai. Bagian Saldo BimbelKu dikembalikan ke saldo dan bagian transfer eksternal dicatat telah ditransfer.'
                : 'Refund ditandai telah ditransfer.');

        return response()->json([
            'message' => $message,
            'wallet_balance' => $walletTransaction?->balance_after,
            'wallet_funded_amount' => $completedBreakdown['wallet_funded_amount'],
            'external_funded_amount' => $completedBreakdown['external_funded_amount'],
        ]);
    }

    private function authorizeTeacher(Request $request, Booking $booking): void
    {
        abort_unless((int) $booking->teacher_id === (int) $request->user()->id, 403);
    }

    private function studentParticipant(Request $request, Booking $booking): BookingParticipant
    {
        return $booking->participants()
            ->where('student_id', $request->user()->id)
            ->whereHas('order', fn ($query) => $query->where('status', 'paid'))
            ->firstOrFail();
    }

    private function reportedParticipant(Booking $booking, ?int $studentId): BookingParticipant
    {
        return $booking->participants()
            ->where('student_id', $studentId ?? $booking->student_id)
            ->whereHas('order', fn ($query) => $query->where('status', 'paid'))
            ->firstOrFail();
    }

    private function restorePromotionClaimForRefund(Order $order, string $reason): void
    {
        $normalizedReason = mb_strtolower(trim($reason));
        $platformFault = in_array($normalizedReason, [
            'keadaan darurat tutor',
            'keberatan murid disetujui',
            'tutor tidak hadir',
        ], true);

        if (! $platformFault || ! $order->learning_package_id) {
            return;
        }

        PromotionClaim::query()
            ->where('order_id', $order->id)
            ->where('status', 'used')
            ->update([
                'status' => 'available',
                'order_id' => null,
                'learning_package_id' => null,
                'used_at' => null,
                'released_at' => now(),
            ]);
    }

    private function queueRefund(
        BookingParticipant $participant,
        Booking $booking,
        string $reason
    ): ?Refund {
        $order = $participant->order;
        if (! $order || $order->status !== 'paid') {
            return null;
        }

        $refund = Refund::firstOrCreate(
            ['order_id' => $order->id],
            [
                'user_id' => $participant->student_id,
                'booking_id' => $booking->id,
                'amount' => $order->amount,
                'reason' => $reason,
                'status' => 'pending',
            ]
        );
        $order->update(['status' => 'refund_pending']);
        $participant->update(['status' => 'refund_pending']);
        $participant->bookingRequest?->update(['status' => 'refund_pending']);
        $this->refreshBookingAmounts($booking);

        return $refund;
    }

    private function isPresenceFlow(Booking $booking): bool
    {
        return $booking->class_type === 'private';
    }

    /**
     * Session Flow V2 keeps Bab changes provisional until the murid accepts the
     * session (or Admin rules the session valid). The report/log exists for the
     * audit trail, while the package state remains untouched during a dispute.
     */
    private function applyPendingPackageProgress(Booking $booking): void
    {
        $packageSession = PackageSession::query()
            ->where('booking_id', $booking->id)
            ->first();

        if (! $packageSession) {
            return;
        }

        $logs = PackageSessionChapterLog::query()
            ->where('package_session_id', $packageSession->id)
            ->whereNotNull('learning_progress_report_id')
            ->orderBy('id')
            ->get();

        if ($logs->isEmpty()) {
            return;
        }

        $chapters = PackageChapter::query()
            ->where('package_subject_id', $packageSession->package_subject_id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        foreach ($logs as $log) {
            /** @var PackageChapter|null $chapter */
            $chapter = $chapters->get((int) $log->package_chapter_id);
            if (! $chapter) {
                continue;
            }

            $nextStatus = (string) $log->status_after;
            $chapter->update([
                'status' => $nextStatus,
                'needs_review' => (bool) $log->needs_review_after,
                'started_at' => $chapter->started_at ?? now(),
                'completed_at' => $nextStatus === 'completed'
                    ? ($chapter->completed_at ?? now())
                    : null,
            ]);
        }

    }

    private function refreshBookingAmounts(Booking $booking): void
    {
        $gross = (float) $booking->participants()
            ->whereHas('order', fn ($query) => $query->where('status', 'paid'))
            ->sum('amount');
        $net = round($gross * (100 - (float) $booking->commission_percent) / 100);

        $booking->update([
            'gross_amount' => $gross,
            'teacher_net_amount' => $net,
        ]);
    }

    private function finalizeBookingIfSettled(Booking $booking): void
    {
        $booking->refresh();
        $unsettled = $booking->participants()
            ->whereNotIn('status', [
                'approved', 'no_show_confirmed', 'refunded', 'cancelled', 'teacher_rejected',
            ])
            ->exists();

        if ($unsettled) {
            $booking->update(['status' => 'awaiting_student_approval']);

            return;
        }

        $earningParticipants = $booking->participants()
            ->whereIn('status', ['approved', 'no_show_confirmed'])
            ->count();
        $gross = $earningParticipants * (float) $booking->total_amount;
        $net = round($gross * (100 - (float) $booking->commission_percent) / 100);

        $booking->update([
            'status' => $earningParticipants > 0 ? 'completed' : 'refunded',
            'completed_at' => now(),
            'student_approved_at' => now(),
            'gross_amount' => $gross,
            'teacher_net_amount' => $net,
            'payout_status' => $earningParticipants > 0 ? 'ready' : 'cancelled',
        ]);
        $booking->order?->classroom?->sessions()->update(['is_completed' => true]);

        Notification::updateOrCreate(
            ['unique_key' => "session-completed:{$booking->id}:{$booking->teacher_id}"],
            [
                'user_id' => $booking->teacher_id,
                'title' => 'Sesi selesai',
                'message' => $earningParticipants > 0
                    ? 'Penyelesaian disahkan. Pendapatan siap masuk proses pencairan admin.'
                    : 'Sesi ditutup tanpa pendapatan tutor.',
                'type' => 'success',
                'target_url' => $earningParticipants > 0 ? '/guru/gaji' : '/guru/kelas',
                'is_read' => false,
            ]
        );
    }

    private function restoredStatusAfterReport(Booking $booking): string
    {
        if ($booking->learningProgressReports()->exists()) {
            return 'awaiting_student_approval';
        }
        if (now()->lt($booking->start_at)) {
            return 'confirmed';
        }
        if ($booking->student_confirmed_at && now()->lt($booking->end_at)) {
            return 'in_progress';
        }

        return 'admin_review_required';
    }
}
