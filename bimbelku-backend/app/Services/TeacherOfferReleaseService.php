<?php

namespace App\Services;

use App\Models\BookingRequest;
use App\Models\TeacherOffer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeacherOfferReleaseService
{
    /**
     * Membatalkan penawaran tanpa memberi sanksi ketika kelayakan tutor
     * berubah karena profil, jadwal, verifikasi, pembatasan sementara, atau keputusan admin.
     *
     * @return Collection<int, BookingRequest>
     */
    public function releaseForTeacher(int $teacherId, string $reason): Collection
    {
        return $this->releaseQuery(
            TeacherOffer::query()
                ->where('teacher_id', $teacherId)
                ->where('status', 'pending'),
            $teacherId,
            $reason
        );
    }

    /**
     * @return Collection<int, BookingRequest>
     */
    public function releaseForTeacherDateRange(
        int $teacherId,
        Carbon $startDate,
        Carbon $endDate,
        string $reason
    ): Collection {
        return $this->releaseQuery(
            TeacherOffer::query()
                ->where('teacher_id', $teacherId)
                ->where('status', 'pending')
                ->whereHas('bookingRequest', function (Builder $requests) use ($startDate, $endDate) {
                    $requests->where(function (Builder $dates) use ($startDate, $endDate) {
                        $dates->whereBetween('scheduled_date', [$startDate->toDateString(), $endDate->toDateString()])
                            ->orWhereHas('packageSubject.sessions', fn (Builder $sessions) => $sessions
                                ->whereDate('scheduled_start_at', '>=', $startDate->toDateString())
                                ->whereDate('scheduled_start_at', '<=', $endDate->toDateString()));
                    });
                }),
            $teacherId,
            $reason
        );
    }

    /**
     * Membebaskan penawaran lain yang waktunya bertabrakan setelah tutor
     * menerima sebuah sesi. Penawaran ini bukan kelalaian tutor.
     *
     * @return Collection<int, BookingRequest>
     */
    public function releaseConflictingForTeacher(
        int $teacherId,
        Carbon $startAt,
        Carbon $endAt,
        int $acceptedOfferId,
        string $reason
    ): Collection {
        return $this->releaseQuery(
            TeacherOffer::query()
                ->where('teacher_id', $teacherId)
                ->where('status', 'pending')
                ->whereKeyNot($acceptedOfferId)
                ->whereHas('bookingRequest', fn (Builder $requests) => $requests
                    ->whereDate('scheduled_date', $startAt->toDateString())
                    ->where('start_time', '<', $endAt->format('H:i:s'))
                    ->where('end_time', '>', $startAt->format('H:i:s'))),
            $teacherId,
            $reason
        );
    }

    /**
     * @return Collection<int, BookingRequest>
     */
    private function releaseQuery(
        Builder $query,
        int $teacherId,
        string $reason
    ): Collection {
        $requestIds = collect();

        $query
            ->orderBy('id')
            ->chunkById(100, function ($offers) use ($reason, $requestIds) {
                foreach ($offers as $offerSnapshot) {
                    $requestId = DB::transaction(function () use (
                        $offerSnapshot,
                        $reason
                    ) {
                        $bookingRequest = BookingRequest::query()
                            ->lockForUpdate()
                            ->find($offerSnapshot->booking_request_id);
                        $offer = TeacherOffer::query()
                            ->lockForUpdate()
                            ->find($offerSnapshot->id);
                        if (! $offer || $offer->status !== 'pending') {
                            return null;
                        }

                        $offer->update([
                            'status' => 'cancelled',
                            'responded_at' => now(),
                            'rejection_reason' => $reason,
                            'no_response_penalty_applied' => false,
                        ]);

                        if (
                            ! $bookingRequest
                            || $bookingRequest->status !== 'teacher_pending'
                        ) {
                            return null;
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

                        return $otherDeadline ? null : $bookingRequest->id;
                    }, 3);

                    if ($requestId) {
                        $requestIds->push($requestId);
                    }
                }
            });

        return BookingRequest::query()
            ->whereIn('id', $requestIds->unique()->values())
            ->where('status', 'matching')
            ->get();
    }
}
