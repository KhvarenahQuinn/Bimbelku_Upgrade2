<?php

namespace App\Observers;

use App\Models\Booking;
use App\Models\PackageSession;
use App\Services\FinancialLedgerService;

class BookingObserver
{
    public function updated(Booking $booking): void
    {
        if ($booking->wasChanged('status')) {
            $packageSession = PackageSession::query()
                ->where('booking_id', $booking->id)
                ->with('subject.package.subjects.sessions.booking')
                ->first();
            if ($packageSession) {
                $packageSession->update(['status' => $booking->status]);
                $package = $packageSession->subject->package;
                $completed = $package->subjects
                    ->flatMap->sessions
                    ->filter(fn (PackageSession $session) => $session->booking?->status === 'completed'
                            || ($session->booking_id === $booking->id && $booking->status === 'completed')
                    )
                    ->count();
                $package->update(['used_sessions' => $completed]);
                if ($booking->status === 'completed') {
                    $subjectCompleted = $packageSession->subject->sessions
                        ->every(fn (PackageSession $session) => $session->booking?->status === 'completed'
                                || $session->booking_id === $booking->id
                        );
                    if ($subjectCompleted) {
                        $packageSession->subject->update(['status' => 'completed']);
                    }
                    if ($completed >= $package->total_sessions) {
                        $package->update(['status' => 'completed', 'completed_at' => now()]);
                    }
                }
            }
        }

        if (
            $booking->wasChanged('payout_status')
            && $booking->payout_status === 'ready'
            && (float) $booking->gross_amount > 0
        ) {
            app(FinancialLedgerService::class)->recordBookingSettled($booking);
        }
    }
}
