<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\LearningPackage;
use App\Models\Order;
use App\Models\PackageSession;
use App\Models\Refund;
use App\Models\TeacherReplacementRequest;
use App\Models\TeacherReplacementSession;
use App\Models\User;
use App\Support\TeacherReplacementState;
use Illuminate\Support\Facades\DB;

class PartialPackageRefundService
{
    /** Session-level refund decisions that reserve value on one booking. */
    public const SESSION_REFUND_SOURCES = ['booking_case', 'teacher_emergency', 'teacher_absence', 'lapsed_session'];

    public function __construct(
        private readonly ScheduleReleaseService $scheduleRelease,
        private readonly FinancialLedgerService $ledger,
    ) {}

    /**
     * Resolved lazily: RefundSettlementService depends on this service, so a
     * constructor dependency back would create a container cycle.
     */
    private function settlements(): RefundSettlementService
    {
        return app(RefundSettlementService::class);
    }

    public function queueTeacherReplacement(TeacherReplacementRequest $replacement): Refund
    {
        return DB::transaction(function () use ($replacement) {
            $locked = TeacherReplacementRequest::query()
                ->lockForUpdate()->findOrFail($replacement->id);
            $existing = Refund::query()
                ->where('source_type', 'teacher_replacement')
                ->where('source_id', $locked->id)
                ->first();
            if ($existing) {
                return $existing;
            }
            abort_unless($locked->status === 'no_teacher', 422, 'Refund hanya tersedia setelah pencarian guru pengganti tidak berhasil.');
            $matchingRequest = $locked->matchingRequest()->lockForUpdate()->first();
            if ($matchingRequest) {
                $matchingRequest->offers()->lockForUpdate()->get();
            }
            $replacementRows = TeacherReplacementSession::query()
                ->where('teacher_replacement_request_id', $locked->id)
                ->lockForUpdate()
                ->get();
            PackageSession::query()->whereIn('id', $replacementRows->pluck('package_session_id'))->lockForUpdate()->get();
            Booking::query()->whereIn('id', $replacementRows->pluck('old_booking_id'))->lockForUpdate()->get();
            $replacementRows->load(['oldBooking', 'packageSession']);
            $locked->setRelation('sessions', $replacementRows);
            $locked->setRelation('matchingRequest', $matchingRequest);

            $order = Order::query()->lockForUpdate()
                ->where('learning_package_id', $locked->learning_package_id)
                ->where('status', 'paid')->latest()->firstOrFail();
            $amount = round($locked->sessions->sum(fn ($row) => (float) $row->oldBooking->total_amount), 2);
            $queued = Refund::query()->where('order_id', $order->id)
                ->whereNotIn('status', ['rejected', 'cancelled'])->get();
            $remaining = round((float) $order->amount - $queued->sum(fn ($refund) => (float) $refund->amount), 2);
            abort_if($amount <= 0 || $amount > $remaining, 422, 'Nominal refund sesi melebihi sisa dana order.');

            $orderAmount = max(0.01, (float) $order->amount);
            $walletTotal = min($orderAmount, (float) $order->wallet_applied_amount);
            $walletRemaining = max(0, $walletTotal - $queued->sum(fn ($refund) => (float) ($refund->wallet_refund_amount ?? $refund->tenderBreakdown()['wallet_funded_amount'])));
            $walletPart = min($amount, $walletRemaining, round($amount * ($walletTotal / $orderAmount), 2));
            $externalPart = round($amount - $walletPart, 2);

            $refund = Refund::create([
                'order_id' => $order->id,
                'user_id' => $locked->student_id,
                'amount' => $amount,
                'wallet_refund_amount' => $walletPart,
                'external_refund_amount' => $externalPart,
                'source_type' => 'teacher_replacement',
                'source_id' => $locked->id,
                'reason' => 'Refund sesi tersisa karena guru pengganti tidak tersedia',
                'status' => 'pending',
            ]);
            $locked->matchingRequest?->offers()->where('status', 'pending')->update(['status' => 'cancelled', 'responded_at' => now()]);
            $locked->matchingRequest?->update(['status' => 'refund_pending', 'matched_teacher_id' => null]);
            foreach ($locked->sessions as $row) {
                $row->packageSession->update(['status' => 'refund_pending']);
                $this->scheduleRelease->releaseSession($row->packageSession, 'refund_pending');
                $row->update(['status' => 'refund_pending']);
            }
            $locked->transitionTo(TeacherReplacementState::REFUND_PENDING);

            return $refund;
        }, 3);
    }

    /**
     * Queue a refund for a single session that did not take place.
     *
     * Difference from the legacy order-level path: the amount comes from
     * `booking_participant.amount` (already discount/promo aware), the wallet
     * and external portions are snapshotted once, the cumulative external
     * portion can never exceed what the gateway actually received, and the
     * correction journal is written in the same transaction so the tutor share
     * is never silently rewritten by a later settlement.
     */
    public function queueSessionRefund(
        Booking $booking,
        BookingParticipant $participant,
        float $grossDelta,
        string $costBearer,
        string $sourceType,
        int $sourceId,
        string $reason,
        string $decisionActor,
        ?int $actorId = null,
        ?string $decisionNotes = null
    ): Refund {
        return DB::transaction(function () use (
            $booking, $participant, $grossDelta, $costBearer, $sourceType, $sourceId, $reason, $decisionActor, $actorId, $decisionNotes
        ) {
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $lockedParticipant = BookingParticipant::query()->lockForUpdate()->findOrFail($participant->id);

            $existing = Refund::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first();
            if ($existing) {
                return $existing;
            }

            $order = Order::query()->lockForUpdate()->findOrFail($lockedBooking->order_id);

            $amount = round($grossDelta, 2);
            $participantValue = round((float) $lockedParticipant->amount, 2);
            abort_if($amount <= 0, 422, 'Nominal refund sesi harus lebih dari nol.');
            abort_if(
                $participantValue > 0 && $amount > $participantValue + 0.009,
                422,
                'Nominal refund melebihi nilai sesi yang dibayar murid.'
            );
            try {
                $lockedBooking->assertRefundable($amount);
            } catch (\DomainException $exception) {
                abort(422, $exception->getMessage());
            }

            [$walletPart, $externalPart] = $this->splitTender($order, $amount);

            $refund = Refund::create([
                'order_id' => $order->id,
                'user_id' => $lockedParticipant->student_id,
                'booking_id' => $lockedBooking->id,
                'amount' => $amount,
                'wallet_refund_amount' => $walletPart,
                'external_refund_amount' => $externalPart,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reason' => mb_substr($reason, 0, 180),
                'cost_bearer' => $costBearer,
                'decided_by' => $decisionActor,
                'decision_actor_id' => $actorId,
                'decision_notes' => $decisionNotes,
                'status' => 'pending',
            ]);

            $lockedParticipant->update(['status' => 'refund_pending']);
            $lockedParticipant->bookingRequest?->update(['status' => 'refund_pending']);
            $this->settlements()->releaseBookingForSessionRefund($lockedBooking, 'refund_pending');

            $netDelta = round($amount * (100 - (float) $lockedBooking->commission_percent) / 100, 2);
            $this->ledger->recordSessionRefundAdjustment($lockedBooking->fresh(), $refund, $amount, $netDelta);

            return $refund;
        }, 3);
    }

    /**
     * Wallet store credit is non-withdrawable, so the wallet portion returns to
     * the balance and only the externally funded part can leave the platform.
     * The cumulative external portion is capped by what the gateway received.
     *
     * @return array{0:float,1:float}
     */
    private function splitTender(Order $order, float $amount): array
    {
        $orderAmount = max(0.01, (float) $order->amount);
        $walletTotal = min($orderAmount, (float) $order->wallet_applied_amount);

        $reserved = Refund::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->get();
        $walletUsed = round((float) $reserved->sum(
            fn (Refund $refund) => (float) ($refund->wallet_refund_amount ?? $refund->tenderBreakdown()['wallet_funded_amount'])
        ), 2);
        $externalUsed = round((float) $reserved->sum(
            fn (Refund $refund) => (float) ($refund->external_refund_amount ?? $refund->tenderBreakdown()['external_funded_amount'])
        ), 2);
        $externalCap = round((float) $order->external_received_amount, 2);

        $walletPart = round(min($amount, max(0, $walletTotal - $walletUsed), $amount * ($walletTotal / $orderAmount)), 2);
        $externalPart = round($amount - $walletPart, 2);

        abort_if(
            $externalPart <= 0.009 && $walletPart <= 0.009,
            422,
            'Tidak ada dana yang dapat dikembalikan pada tagihan ini.'
        );
        abort_if(
            $externalPart > 0.009 && $externalUsed + $externalPart > $externalCap + 0.009,
            422,
            'Porsi eksternal refund melebihi dana yang diterima gateway.'
        );

        return [max(0, $walletPart), max(0, $externalPart)];
    }

    public function completeTeacherReplacementRefund(Refund $refund): void
    {
        if ($refund->source_type !== 'teacher_replacement') {
            return;
        }

        DB::transaction(function () use ($refund) {
            $lockedRefund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            abort_unless($lockedRefund->status === 'paid', 422, 'Refund belum selesai diproses.');
            $replacement = TeacherReplacementRequest::query()->lockForUpdate()->findOrFail($lockedRefund->source_id);
            if ($replacement->status === TeacherReplacementState::REFUNDED) {
                return;
            }
            abort_unless($replacement->status === TeacherReplacementState::REFUND_PENDING, 422, 'Status replacement tidak sesuai untuk penyelesaian refund.');
            $rows = $replacement->sessions()->lockForUpdate()->get();
            PackageSession::query()->whereIn('id', $rows->pluck('package_session_id'))->lockForUpdate()->get();
            foreach ($rows as $row) {
                $this->scheduleRelease->releaseSession($row->packageSession, 'refunded');
                $row->update(['status' => 'refunded']);
            }
            $replacement->transitionTo(TeacherReplacementState::REFUNDED, ['completed_at' => now()]);

            $subject = $replacement->subject()->lockForUpdate()->firstOrFail();
            $subjectSettled = $subject->sessions()->with('booking')->get()->every(fn (PackageSession $session) => in_array($session->status, ['completed', 'refunded'], true)
                || $session->booking?->status === 'completed');
            if ($subjectSettled) {
                $subject->update(['status' => 'completed']);
            }
            $package = $replacement->package()->lockForUpdate()->firstOrFail();
            $allSettled = $package->subjects()->with('sessions.booking')->get()
                ->flatMap->sessions
                ->every(fn (PackageSession $session) => in_array($session->status, ['completed', 'refunded'], true)
                    || $session->booking?->status === 'completed');
            if ($allSettled) {
                $package->update(['status' => 'completed', 'completed_at' => now()]);
            }
        }, 3);
    }

    /**
     * Sessions that can still be returned when the tutor cannot continue.
     * Delivered sessions (attendance, presence taps, completed status) are
     * never part of the exit refund.
     *
     * @return array<int, Booking>
     */
    public function tutorExitSessions(LearningPackage $package): array
    {
        $bookings = Booking::query()
            ->whereIn('id', PackageSession::query()
                ->whereIn('package_subject_id', $package->subjects()->select('id'))
                ->whereNotNull('booking_id')
                ->select('booking_id'))
            ->whereIn('status', ['confirmed', 'admin_review_required', 'refund_pending'])
            ->where('start_at', '>', now())
            ->withCount(['sessionAttendances', 'participantAttendances'])
            ->get();

        return $bookings
            ->filter(fn (Booking $booking) => blank($booking->tutor_ready_at)
                && blank($booking->student_confirmed_at)
                && (int) $booking->session_attendances_count === 0
                && (int) $booking->participant_attendances_count === 0)
            ->values()
            ->all();
    }

    /**
     * Preview only: nothing is written, so the admin can review the exact
     * sessions and value before confirming.
     *
     * @return array{sessions:array<int,array<string,mixed>>,total_amount:float,wallet_amount:float,external_amount:float,already_queued:bool}
     */
    public function previewTutorExit(LearningPackage $package): array
    {
        $sessions = $this->tutorExitSessions($package);
        $total = 0.0;
        $rows = [];
        foreach ($sessions as $booking) {
            $amount = round((float) $booking->participants()->max('amount'), 2);
            $total = round($total + $amount, 2);
            $rows[] = [
                'booking_id' => $booking->id,
                'start_at' => $booking->start_at?->toIso8601String(),
                'end_at' => $booking->end_at?->toIso8601String(),
                'subject' => $booking->bookingRequest?->subject_name,
                'amount' => $amount,
            ];
        }

        $split = $this->previewTenderSplit($package, $total);

        return [
            'sessions' => $rows,
            'total_amount' => $total,
            'wallet_amount' => $split['wallet'],
            'external_amount' => $split['external'],
            'already_queued' => Refund::query()
                ->where('source_type', 'tutor_exit')
                ->where('source_id', $package->id)
                ->exists(),
        ];
    }

    /**
     * One aggregate refund for every remaining session of the package.
     */
    public function queueTutorExit(LearningPackage $package, User $actor, string $reason, string $evidenceNote, array $bookingIds): Refund
    {
        return DB::transaction(function () use ($package, $actor, $reason, $evidenceNote, $bookingIds) {
            $locked = LearningPackage::query()->lockForUpdate()->findOrFail($package->id);
            $existing = Refund::query()
                ->where('source_type', 'tutor_exit')
                ->where('source_id', $locked->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            $candidates = collect($this->tutorExitSessions($locked))->keyBy('id');
            $selected = collect($bookingIds)->map(fn ($id) => (int) $id)->unique()->values();
            abort_if($selected->isEmpty(), 422, 'Pilih minimal satu sesi yang belum terlaksana.');
            abort_if(
                $selected->diff($candidates->keys())->isNotEmpty(),
                422,
                'Ada sesi yang sudah terlaksana atau tidak lagi memenuhi syarat refund.'
            );

            $bookings = Booking::query()->whereIn('id', $selected)->lockForUpdate()->get();
            $order = Order::query()->lockForUpdate()
                ->where('learning_package_id', $locked->id)
                ->where('status', 'paid')
                ->latest()
                ->first();

            $total = 0.0;
            $bookingAmounts = [];
            foreach ($bookings as $booking) {
                $amount = round((float) $booking->participants()->max('amount'), 2);
                abort_if($amount <= 0, 422, 'Nilai sesi tidak ditemukan untuk sesi #'.$booking->id.'.');
                try {
                    $booking->assertRefundable($amount);
                } catch (\DomainException $exception) {
                    abort(422, $exception->getMessage());
                }
                $bookingAmounts[$booking->id] = $amount;
                $total = round($total + $amount, 2);
            }

            // The refund is anchored on the order that paid the package. When
            // the package is unpaid only the booking payload is resolved.
            $refundOrder = $order ?? $bookings->first()?->order;
            abort_unless($refundOrder, 422, 'Tagihan paket tidak ditemukan.');

            [$walletPart, $externalPart] = $this->splitTender($refundOrder, $total);

            $refund = Refund::create([
                'order_id' => $refundOrder->id,
                'user_id' => $locked->student_id,
                'amount' => $total,
                'wallet_refund_amount' => $walletPart,
                'external_refund_amount' => $externalPart,
                'source_type' => 'tutor_exit',
                'source_id' => $locked->id,
                'reason' => mb_substr($reason, 0, 180),
                'cost_bearer' => 'tutor_share',
                'decided_by' => 'admin',
                'decision_actor_id' => $actor->id,
                'decision_notes' => $evidenceNote,
                'status' => 'pending',
            ]);

            foreach ($bookings as $booking) {
                foreach ($booking->participants()->get() as $participant) {
                    $participant->update(['status' => 'refund_pending']);
                    $participant->bookingRequest?->update(['status' => 'refund_pending']);
                }
                $amount = $bookingAmounts[$booking->id];
                $netDelta = round($amount * (100 - (float) $booking->commission_percent) / 100, 2);
                $this->ledger->recordSessionRefundAdjustment($booking->fresh(), $refund, $amount, $netDelta);
                $booking->update(['status' => 'refund_pending', 'payout_status' => 'locked']);
                PackageSession::query()
                    ->where('booking_id', $booking->id)
                    ->update(['status' => 'refund_pending']);
            }

            $locked->update(['status' => 'refund_pending']);

            return $refund;
        }, 3);
    }

    /** @return array{wallet:float,external:float} */
    private function previewTenderSplit(LearningPackage $package, float $total): array
    {
        if ($total <= 0) {
            return ['wallet' => 0.0, 'external' => 0.0];
        }
        $order = Order::query()
            ->where('learning_package_id', $package->id)
            ->where('status', 'paid')
            ->latest()
            ->first();
        if (! $order) {
            return ['wallet' => 0.0, 'external' => $total];
        }
        $orderAmount = max(0.01, (float) $order->amount);
        $walletTotal = min($orderAmount, (float) $order->wallet_applied_amount);
        $walletPart = round(min($total, $walletTotal, $total * ($walletTotal / $orderAmount)), 2);

        return ['wallet' => $walletPart, 'external' => round($total - $walletPart, 2)];
    }
}
