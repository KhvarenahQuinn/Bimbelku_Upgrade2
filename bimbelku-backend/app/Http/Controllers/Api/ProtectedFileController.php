<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingDispute;
use App\Models\ClassroomMessage;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\SessionReport;
use App\Models\TeacherReplacementRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProtectedFileController extends Controller
{
    public function paymentProof(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        return $this->respond($order->payment_proof);
    }

    public function classroomMessageAttachment(Request $request, ClassroomMessage $classroomMessage)
    {
        $this->authorizeBooking($request, $classroomMessage->booking);

        return $this->respond($classroomMessage->attachment_path, $classroomMessage->attachment_name);
    }

    public function teacherReplacementEvidence(Request $request, TeacherReplacementRequest $teacherReplacement)
    {
        abort_unless(
            $request->user()->role === 'admin'
            || (int) $teacherReplacement->student_id === (int) $request->user()->id,
            403
        );

        return $this->respond($teacherReplacement->evidence_path);
    }

    public function reportEvidence(Request $request, SessionReport $sessionReport)
    {
        $user = $request->user();
        $allowed = $user->role === 'admin'
            || (int) $sessionReport->reported_by === (int) $user->id
            || (
                $sessionReport->type === 'student_absence'
                && (
                    (int) $sessionReport->teacher_id === (int) $user->id
                    || (int) $sessionReport->reported_student_id === (int) $user->id
                )
            )
            || (
                in_array($sessionReport->type, ['teacher_absence', 'teacher_emergency'], true)
                && (int) $sessionReport->teacher_id === (int) $user->id
            );
        abort_unless($allowed, 403);

        return $this->respond($sessionReport->evidence);
    }

    public function disputeEvidence(Request $request, BookingDispute $bookingDispute)
    {
        $user = $request->user();
        $allowed = $user->role === 'admin'
            || (int) $bookingDispute->student_id === (int) $user->id
            || (int) $bookingDispute->booking?->teacher_id === (int) $user->id;
        abort_unless($allowed, 403);

        return $this->respond($bookingDispute->evidence);
    }

    public function payoutProof(Request $request, Payout $payout)
    {
        abort_unless(
            $request->user()->role === 'admin'
            || (int) $payout->user_id === (int) $request->user()->id,
            403
        );

        return $this->respond($payout->proof_url);
    }

    public function refundProof(Request $request, Refund $refund)
    {
        abort_unless(
            $request->user()->role === 'admin'
            || (int) $refund->user_id === (int) $request->user()->id,
            403
        );

        return $this->respond($refund->proof);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless(
            $request->user()->role === 'admin'
            || (int) $order->user_id === (int) $request->user()->id,
            403
        );
    }

    private function authorizeBooking(Request $request, ?Booking $booking): void
    {
        abort_unless($booking, 404);

        $allowed = $request->user()->role === 'admin'
            || (int) $booking->teacher_id === (int) $request->user()->id
            || $booking->participants()
                ->where('student_id', $request->user()->id)
                ->whereHas('order', fn ($query) => $query->whereIn('status', [
                    'paid',
                    'refund_pending',
                    'refunded',
                ]))
                ->exists();

        abort_unless($allowed, 403);
    }

    private function respond(?string $path, ?string $displayName = null)
    {
        abort_unless($path, 404);

        $filename = $this->safeFilename($displayName ?: $path);
        $headers = [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ];

        if (Storage::disk('local')->exists($path)) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file(Storage::disk('local')->path($path)) ?: 'application/octet-stream';

            return Storage::disk('local')->response($path, $mime, $headers);
        }

        abort(404);
    }

    private function safeFilename(string $name): string
    {
        $filename = basename(str_replace(['\\', "\r", "\n", '"'], ['', '', '', ''], $name));

        return $filename !== '' ? $filename : 'file';
    }
}
