<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\TeacherOffer;
use App\Services\PackageCheckoutService;
use App\Services\PackageEarningsAllocation;
use App\Services\TeacherMatchingService;
use App\Services\TeacherReplacementService;
use App\Support\TeacherReplacementState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeacherOfferController extends Controller
{
    public function index(Request $request, TeacherMatchingService $matchingService, PackageEarningsAllocation $earningsAllocation)
    {
        $matchingService->expireStaleOffersForTeacher($request->user()->id);
        $validated = $request->validate([
            'scope' => ['nullable', Rule::in(['active', 'history'])],
            'status' => ['nullable', Rule::in(['all', 'accepted', 'rejected', 'expired'])],
        ]);
        $scope = $validated['scope'] ?? 'active';
        $statusFilter = $validated['status'] ?? 'all';
        $statuses = $scope === 'history'
            ? match ($statusFilter) {
                'accepted' => ['accepted'],
                'rejected' => ['rejected'],
                'expired' => ['expired'],
                default => ['accepted', 'rejected', 'expired', 'student_rejected', 'cancelled'],
            }
            : ['pending'];

        $offers = TeacherOffer::query()
            ->where('teacher_id', $request->user()->id)
            ->whereIn('status', $statuses)
            ->with([
                'bookingRequest.student',
                'bookingRequest.booking.orders',
                'bookingRequest.packageSubject.package.plan',
                'bookingRequest.packageSubject.sessions',
            ])
            ->latest('offered_at')
            ->paginate(15);

        $commissionPercent = max(
            0,
            min(100, (float) (Setting::where('key', 'admin_fee')->value('value') ?? 20))
        );

        $offers->getCollection()->transform(function (TeacherOffer $offer) use ($commissionPercent, $earningsAllocation) {
            $request = $offer->bookingRequest;
            $effectiveCommission = (float) (
                $request?->booking?->commission_percent
                ?? $commissionPercent
            );
            $canSeeFullAddress = $offer->status === 'accepted'
                && in_array($request?->booking?->status, [
                    'confirmed',
                    'in_progress',
                    'awaiting_student_approval',
                    'disputed',
                    'absence_review',
                    'admin_review_required',
                    'completed',
                ], true);

            if ($request) {
                $participantCount = (int) ($request->packageSubject?->package?->participant_count ?? 1);
                $offerGross = (float) $request->total_amount;
                $offerNet = round($offerGross * (100 - $effectiveCommission) / 100, 2);
                if ($request->packageSubject && ! $request->teacher_replacement_request_id) {
                    $allocation = $earningsAllocation->forPackage($request->packageSubject->package);
                    $subjectAmount = $allocation[$request->packageSubject->id] ?? null;
                    if ($subjectAmount) {
                        $offerGross = $subjectAmount['gross_amount'];
                        $offerNet = $subjectAmount['teacher_net_amount'];
                        $effectiveCommission = 100 - PackageEarningsAllocation::TEACHER_SHARE_PERCENT;
                        $request->setAttribute('total_amount', $offerGross);
                        $subjectHours = max(1, (int) $request->packageSubject->allocated_sessions
                            * (int) $request->packageSubject->package->duration_hours);
                        $request->setAttribute('hourly_rate', round($offerGross / $subjectHours, 2));
                    }
                }
                $request->setAttribute('participant_count', $participantCount);
                $request->setAttribute('source_label', $request->teacher_replacement_request_id ? 'Guru Pengganti · Paket Belajar' : 'Paket Belajar');
                $request->setAttribute('commission_percent', $effectiveCommission);
                $request->setAttribute('amount_per_student', $offerGross / max(1, $participantCount));
                $request->setAttribute('gross_amount_minimum', $offerGross);
                $request->setAttribute('gross_amount_if_all_pay', $offerGross);
                $request->setAttribute(
                    'estimated_net_amount',
                    $offerNet
                );
                if ($request->student && ! $canSeeFullAddress) {
                    $request->student->setAttribute('name', 'Murid BimbelKu');
                }
                $request->student?->setVisible(['id', 'name']);
                $request->setHidden(array_values(array_unique([
                    ...$request->getHidden(),
                    'attachment',
                    'phone',
                    'latitude',
                    'longitude',
                ])));
                $request->unsetRelation('booking');
            }

            if (! $canSeeFullAddress && $request) {
                $request->setAttribute('address', null);
                $request->setAttribute('maps_link', null);
                $request->setAttribute('latitude', null);
                $request->setAttribute('longitude', null);
            }

            return $offer;
        });

        return response()->json([
            ...$offers->toArray(),
            'scope' => $scope,
            'active_count' => TeacherOffer::query()
                ->where('teacher_id', $request->user()->id)
                ->where('status', 'pending')
                ->count(),
            'history_count' => TeacherOffer::query()
                ->where('teacher_id', $request->user()->id)
                ->whereIn('status', ['accepted', 'rejected', 'expired', 'student_rejected', 'cancelled'])
                ->count(),
        ]);
    }

    public function accept(
        Request $request,
        TeacherOffer $teacherOffer,
        PackageCheckoutService $packageCheckoutService, TeacherReplacementService $replacementService
    ) {
        abort_unless($teacherOffer->teacher_id === $request->user()->id, 403);

        $teacherOffer->loadMissing('bookingRequest.packageSubject');
        if ($teacherOffer->bookingRequest?->teacher_replacement_request_id) {
            $result = $replacementService->acceptOffer($teacherOffer, $request->user());

            return response()->json([
                'message' => 'Permintaan guru pengganti diterima. Sesi tersisa sudah dialihkan kepadamu.',
                'data' => $result,
            ]);
        }
        if ($teacherOffer->bookingRequest?->package_subject_id) {
            $result = $packageCheckoutService->acceptPackageOffer($teacherOffer, $request->user());

            return response()->json([
                'message' => $result['package_activated']
                    ? 'Seluruh tutor menerima paket. Jadwal belajar sudah aktif.'
                    : 'Permintaan paket diterima. Sistem masih mencari tutor untuk mapel lain.',
                'data' => $result,
            ]);
        }

        return response()->json([
            'message' => 'Flow penawaran langsung lama sudah dipensiunkan. Muat ulang daftar permintaan.',
        ], 410);
    }

    public function reject(
        Request $request,
        TeacherOffer $teacherOffer,
        TeacherMatchingService $matchingService
    ) {
        abort_unless($teacherOffer->teacher_id === $request->user()->id, 403);

        $validated = $request->validate([
            'reason' => ['required', Rule::in(['too_far', 'schedule', 'material', 'personal', 'other'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if (
            $validated['reason'] === 'other'
            && mb_strlen(trim((string) ($validated['note'] ?? ''))) < 10
        ) {
            return response()->json([
                'message' => 'Jelaskan alasan lain sedikitnya 10 karakter.',
            ], 422);
        }

        $result = DB::transaction(function () use ($teacherOffer, $validated, $request) {
            $bookingRequest = BookingRequest::query()
                ->lockForUpdate()
                ->findOrFail($teacherOffer->booking_request_id);
            $bookingRequest->loadMissing(['packageSubject.package', 'teacherReplacement']);
            $isReplacement = (bool) $bookingRequest->teacher_replacement_request_id;
            $isRenewal = ! $isReplacement && (bool) $bookingRequest->packageSubject?->package?->renewal_of_id;
            $offer = TeacherOffer::query()->lockForUpdate()->findOrFail($teacherOffer->id);
            if ($offer->status !== 'pending') {
                abort(422, 'Penawaran ini sudah diproses.');
            }
            if ($offer->expires_at->isPast()) {
                return ['expired' => true];
            }

            if (
                $bookingRequest->status !== 'teacher_pending'
                || ($bookingRequest->matched_teacher_id
                    && (int) $bookingRequest->matched_teacher_id !== (int) $request->user()->id)
            ) {
                abort(422, 'Permintaan ini sudah dialihkan atau dibatalkan.');
            }

            $offer->update([
                'status' => 'rejected',
                'responded_at' => now(),
                'rejection_reason' => $validated['reason']
                    .(! empty($validated['note']) ? ': '.$validated['note'] : ''),
            ]);

            if ($isRenewal) {
                $bookingRequest->offers()
                    ->where('status', 'pending')
                    ->whereKeyNot($offer->id)
                    ->update([
                        'status' => 'cancelled',
                        'responded_at' => now(),
                    ]);
                $bookingRequest->update([
                    'status' => 'cancelled',
                    'matched_teacher_id' => null,
                    'teacher_response_deadline' => null,
                    'next_matching_at' => null,
                ]);
                $package = $bookingRequest->packageSubject?->package;
                $package?->update(['status' => 'cancelled']);
                $package?->subjects()->update(['status' => 'cancelled']);
                $package?->subjects()->each(function ($subject) {
                    $subject->sessions()->whereNotIn('status', ['completed', 'cancelled'])->update(['status' => 'cancelled']);
                });
                Notification::updateOrCreate(
                    ['unique_key' => "renewal-cancelled:{$bookingRequest->id}:{$bookingRequest->student_id}"],
                    [
                        'user_id' => $bookingRequest->student_id,
                        'title' => 'Perpanjangan dibatalkan',
                        'message' => 'Tutor pilihanmu menolak perpanjangan. Permintaan ini dibatalkan dan tidak dialihkan ke tutor lain.',
                        'type' => 'warning',
                        'target_url' => '/student/my-classes?tab=history',
                        'is_read' => false,
                    ]
                );

                return ['expired' => false, 'renewal_cancelled' => true];
            }

            $otherDeadline = $bookingRequest->offers()
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->max('expires_at');
            $bookingRequest->update($otherDeadline ? [
                'status' => 'teacher_pending',
                'matched_teacher_id' => null,
                'teacher_response_deadline' => $otherDeadline,
            ] : [
                'status' => 'matching',
                'matched_teacher_id' => null,
                'teacher_response_deadline' => null,
            ]);
            if ($isReplacement) {
                TeacherReplacementState::assertCanTransition(
                    (string) $bookingRequest->teacherReplacement?->status,
                    $otherDeadline ? TeacherReplacementState::TEACHER_PENDING : TeacherReplacementState::MATCHING,
                );
                $bookingRequest->teacherReplacement?->update([
                    'status' => $otherDeadline ? 'teacher_pending' : 'matching',
                    'version' => DB::raw('version + 1'),
                ]);
            }

            return ['expired' => false];
        });

        if ($result['expired']) {
            $matchingService->expireOfferAndContinue($teacherOffer);

            return response()->json([
                'message' => 'Batas waktu jawaban sudah berakhir.',
            ], 422);
        }

        if ($result['renewal_cancelled'] ?? false) {
            return response()->json([
                'message' => 'Perpanjangan dibatalkan karena tutor pilihan menolak. Tidak dialihkan ke tutor lain.',
            ]);
        }

        // Rolling pool langsung mengisi slot yang baru kosong tanpa menunggu
        // tutor lain pada permintaan yang sama selesai merespons.
        $matchingService->dispatchNextOffer($teacherOffer->bookingRequest);

        return response()->json([
            'message' => $validated['reason'] === 'too_far'
                ? 'Permintaan ditolak karena jarak. Poin tidak dikurangi.'
                : 'Permintaan ditolak dan dialihkan kepada tutor berikutnya.',
        ]);
    }
}
