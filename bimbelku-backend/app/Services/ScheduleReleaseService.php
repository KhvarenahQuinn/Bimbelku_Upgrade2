<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LearningPackage;
use App\Models\PackageSession;

/**
 * Frees the calendar slot held by a package or a single package session.
 *
 * A refunded/cancelled package must not keep blocking the same hour for the
 * student. The database is the source of truth for conflict validation, so the
 * scheduled times, booking link, and active bookings all have to be released.
 */
class ScheduleReleaseService
{
    public function releasePackage(
        LearningPackage $package,
        string $sessionStatus = 'cancelled',
        string $bookingStatus = 'cancelled'
    ): void {
        $package->load('subjects.sessions.booking.bookingRequest');

        foreach ($package->subjects as $subject) {
            foreach ($subject->sessions as $session) {
                $this->releaseSession($session, $sessionStatus, $bookingStatus);
            }
        }
    }

    public function releaseSession(
        PackageSession $session,
        string $sessionStatus = 'cancelled',
        string $bookingStatus = 'cancelled'
    ): void {
        if (! $session->exists) {
            return;
        }

        $session->load('booking.bookingRequest');
        $booking = $session->booking;

        // Never touch a session that has already been delivered. Only the
        // still-active schedule slot is released.
        if ($session->status === 'completed' || $booking?->status === 'completed') {
            return;
        }

        if ($booking) {
            $this->releaseBooking($booking, $bookingStatus);
        }

        $session->forceFill([
            'booking_id' => null,
            'status' => $sessionStatus,
        ])->save();
    }

    public function releaseBooking(Booking $booking, string $status = 'cancelled'): void
    {
        if (! $booking->exists || $booking->status === 'completed') {
            return;
        }

        $booking->forceFill([
            'status' => $status,
            'payout_status' => 'cancelled',
        ])->save();

        $booking->participants()->update(['status' => $status]);

        $bookingRequest = $booking->bookingRequest;
        if (! $bookingRequest) {
            return;
        }

        $bookingRequest->update(['status' => $status]);
        $bookingRequest->offers()
            ->whereIn('status', ['pending', 'accepted'])
            ->update(['status' => 'cancelled', 'responded_at' => now()]);
    }
}
