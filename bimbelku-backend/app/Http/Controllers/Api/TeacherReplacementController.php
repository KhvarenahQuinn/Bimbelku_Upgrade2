<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningPackage;
use App\Models\PackageSubject;
use App\Models\TeacherReplacementRequest;
use App\Services\PartialPackageRefundService;
use App\Services\RefundSettlementService;
use App\Services\TeacherMatchingService;
use App\Services\TeacherReplacementService;
use App\Services\UploadSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TeacherReplacementController extends Controller
{
    public function store(Request $request, LearningPackage $learningPackage, PackageSubject $packageSubject, TeacherReplacementService $service, UploadSecurityService $uploads)
    {
        Gate::authorize('create', [TeacherReplacementRequest::class, $learningPackage, $packageSubject]);
        $validated = $request->validate([
            'reason_code' => ['required', Rule::in(['communication', 'schedule', 'learning_fit', 'teacher_unavailable', 'conduct', 'other'])],
            'reason_detail' => ['required', 'string', 'min:20', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $path = $request->hasFile('evidence') ? $uploads->store($request->file('evidence'), 'teacher_replacement_evidence', 'local') : null;
        try {
            $replacement = $service->submit($request->user(), $learningPackage, $packageSubject, [...$validated, 'evidence_path' => $path]);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return response()->json(['message' => 'Pengajuan ganti guru dikirim untuk diperiksa admin.', 'data' => $replacement], 201);
    }

    public function adminIndex(Request $request)
    {
        Gate::authorize('viewAny', TeacherReplacementRequest::class);
        $scope = $request->string('scope')->value() === 'history' ? 'history' : 'active';
        $query = TeacherReplacementRequest::query()->with(['student:id,name', 'oldTeacher:id,name', 'newTeacher:id,name', 'subject:id,subject_name', 'package:id,package_code', 'sessions']);
        $scope === 'active'
            ? $query->whereIn('status', TeacherReplacementRequest::OPEN_STATUSES)
            : $query->whereNotIn('status', TeacherReplacementRequest::OPEN_STATUSES);

        return response()->json($query->latest()->paginate(20));
    }

    public function show(Request $request, TeacherReplacementRequest $teacherReplacement)
    {
        Gate::authorize('view', $teacherReplacement);

        return response()->json(['data' => $this->detail($teacherReplacement)]);
    }

    public function adminShow(Request $request, TeacherReplacementRequest $teacherReplacement)
    {
        Gate::authorize('view', $teacherReplacement);

        return response()->json(['data' => $this->detail($teacherReplacement)]);
    }

    public function approve(Request $request, TeacherReplacementRequest $teacherReplacement, TeacherReplacementService $service, TeacherMatchingService $matching)
    {
        Gate::authorize('review', $teacherReplacement);
        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:20', 'max:2000'],
        ]);
        $result = $service->approve(
            $teacherReplacement,
            $request->user(),
            $validated['notes'],
        );
        $dispatch = ['offer' => null, 'deferred' => false];
        if (! $result['needs_reschedule']) {
            $dispatch = $this->dispatchMatchingSafely($matching, $result['booking_request']);
        }

        $message = $result['needs_reschedule']
            ? 'Disetujui. Murid perlu mengubah jadwal sesi tersisa.'
            : ($dispatch['deferred'] ? 'Disetujui. Pencarian guru pengganti dijadwalkan ulang otomatis.' : 'Disetujui. Pencarian guru pengganti dimulai.');

        return response()->json(['message' => $message, 'data' => $result['replacement']]);
    }

    public function reject(Request $request, TeacherReplacementRequest $teacherReplacement, TeacherReplacementService $service)
    {
        Gate::authorize('review', $teacherReplacement);
        $validated = $request->validate(['notes' => ['required', 'string', 'min:20', 'max:2000']]);

        return response()->json(['message' => 'Pengajuan ditolak.', 'data' => $service->reject($teacherReplacement, $request->user(), $validated['notes'])]);
    }

    public function cancel(Request $request, TeacherReplacementRequest $teacherReplacement, TeacherReplacementService $service)
    {
        Gate::authorize('view', $teacherReplacement);

        return response()->json([
            'message' => 'Pengajuan ganti guru dibatalkan.',
            'data' => $service->cancelPending($teacherReplacement, $request->user()),
        ]);
    }

    public function retry(Request $request, TeacherReplacementRequest $teacherReplacement, TeacherReplacementService $service, TeacherMatchingService $matching)
    {
        Gate::authorize('view', $teacherReplacement);
        $result = $service->retry($teacherReplacement, $request->user());
        $dispatch = $this->dispatchMatchingSafely($matching, $result['booking_request']);

        return response()->json([
            'message' => $dispatch['deferred'] ? 'Pencarian dijadwalkan ulang otomatis.' : ($dispatch['offer'] ? 'Pencarian guru pengganti dimulai kembali.' : 'Belum ada guru tersedia. Kamu dapat mengubah jadwal atau memilih refund.'),
            'data' => $result['replacement']->fresh(),
        ]);
    }

    public function reschedule(Request $request, TeacherReplacementRequest $teacherReplacement, TeacherReplacementService $service, TeacherMatchingService $matching)
    {
        Gate::authorize('view', $teacherReplacement);
        $validated = $request->validate([
            'schedules' => ['required', 'array', 'min:1', 'max:24'],
            'schedules.*' => ['required', 'date'],
        ]);
        $result = $service->reschedule($teacherReplacement, $request->user(), $validated['schedules']);
        $dispatch = $this->dispatchMatchingSafely($matching, $result['booking_request']);

        return response()->json([
            'message' => $dispatch['deferred'] ? 'Jadwal diperbarui. Pencarian tutor dijadwalkan ulang otomatis.' : ($dispatch['offer'] ? 'Jadwal diperbarui dan pencarian guru dimulai.' : 'Jadwal diperbarui, tetapi guru belum tersedia.'),
            'data' => $result['replacement']->fresh(),
        ]);
    }

    public function requestRefund(Request $request, TeacherReplacementRequest $teacherReplacement, PartialPackageRefundService $refunds, RefundSettlementService $settlement)
    {
        Gate::authorize('view', $teacherReplacement);
        $refund = $refunds->queueTeacherReplacement($teacherReplacement);
        $automatic = $settlement->startAutomatic($refund);

        return response()->json([
            'message' => $automatic
                ? 'Refund sesi tersisa diproses otomatis ke sumber pembayaran asal.'
                : 'Refund sesi tersisa siap diajukan. Pilih dan konfirmasi tujuan refund di Riwayat Transaksi.',
            'data' => $refund->fresh(),
        ], 201);
    }

    /**
     * Perubahan state sudah committed sebelum matching dijalankan. Gangguan
     * sementara pada dispatcher tidak boleh dilaporkan sebagai kegagalan
     * approval/reschedule yang sebenarnya sudah tersimpan.
     */
    private function dispatchMatchingSafely(TeacherMatchingService $matching, $bookingRequest): array
    {
        try {
            return ['offer' => $matching->dispatchNextOffer($bookingRequest), 'deferred' => false];
        } catch (\Throwable $exception) {
            report($exception);
            $bookingRequest->forceFill(['next_matching_at' => now()])->save();

            return ['offer' => null, 'deferred' => true];
        }
    }

    private function detail(TeacherReplacementRequest $replacement): TeacherReplacementRequest
    {
        return $replacement->load([
            'student:id,name,email',
            'oldTeacher:id,name',
            'newTeacher:id,name',
            'reviewer:id,name',
            'package:id,package_code,status,expires_at',
            'subject:id,subject_name,status,assigned_teacher_id',
            'sessions.packageSession',
            'sessions.oldBooking.reports',
            'sessions.oldBooking.disputes',
            'matchingRequest.latestMatchingExhaustion',
        ]);
    }
}
