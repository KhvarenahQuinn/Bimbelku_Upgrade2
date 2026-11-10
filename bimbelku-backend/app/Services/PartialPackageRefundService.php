<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Order;
use App\Models\PackageSession;
use App\Models\Refund;
use App\Models\TeacherReplacementRequest;
use App\Models\TeacherReplacementSession;
use App\Support\TeacherReplacementState;
use Illuminate\Support\Facades\DB;

class PartialPackageRefundService
{
    public function __construct(
        private readonly ScheduleReleaseService $scheduleRelease,
    ) {}

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
}
