<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassroomMessage;
use App\Models\Notification;
use App\Models\PackageSession;
use App\Models\PackageSubject;
use App\Models\Rating;
use App\Models\ScheduleChangeResponse;
use App\Models\TeacherOffer;
use App\Models\TeacherPayoutRequest;
use App\Models\TeacherProfile;
use App\Services\TeacherPayoutService;
use App\Support\XenditPayoutChannelCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class TeacherOperationsController extends Controller
{
    public function dashboard(Request $request)
    {
        $teacherId = (int) $request->user()->id;
        $profile = TeacherProfile::query()->where('user_id', $teacherId)->first();
        $activeStatuses = [
            'confirmed',
            'in_progress',
            'awaiting_student_approval',
            'disputed',
            'absence_review',
            'admin_review_required',
        ];
        $classes = Booking::query()
            ->where('teacher_id', $teacherId)
            ->whereIn('status', [...$activeStatuses, 'completed'])
            ->with(['bookingRequest:id,subject_name,chapter', 'participants:id,booking_id,student_id,status'])
            ->latest('start_at')
            ->limit(100)
            ->get();
        $nextSession = $classes
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->filter(fn ($booking) => $booking->end_at->isFuture())
            ->sortBy('start_at')
            ->first();

        $pendingOffers = TeacherOffer::query()
            ->where('teacher_id', $teacherId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->count();
        $unreadMessages = ClassroomMessage::query()
            ->where('sender_id', '!=', $teacherId)
            ->whereHas('booking', fn ($query) => $query->where('teacher_id', $teacherId))
            ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $teacherId))
            ->count();
        $scheduleAnswers = ScheduleChangeResponse::query()
            ->where('user_id', $teacherId)
            ->where('decision', 'pending')
            ->whereHas('request', fn ($query) => $query->where('status', 'pending'))
            ->count();

        $balance = Booking::query()
            ->where('teacher_id', $teacherId)
            ->where('status', 'completed')
            ->selectRaw('COALESCE(SUM(CASE WHEN teacher_net_amount - teacher_paid_amount - teacher_reserved_amount > 0 THEN teacher_net_amount - teacher_paid_amount - teacher_reserved_amount ELSE 0 END), 0) AS available')
            ->selectRaw('COALESCE(SUM(teacher_reserved_amount), 0) AS requested')
            ->selectRaw("COALESCE(SUM(CASE WHEN payout_status = 'locked' THEN teacher_net_amount ELSE 0 END), 0) AS held")
            ->first();
        $rating = Rating::query()
            ->where('teacher_id', $teacherId)
            ->selectRaw('COUNT(*) AS rating_count, COALESCE(AVG(rating), 0) AS rating_average')
            ->first();

        $packageSubjects = PackageSubject::query()
            ->where('assigned_teacher_id', $teacherId)
            ->whereHas('package', fn ($query) => $query->whereIn('status', ['active', 'completed']))
            ->with(['sessions.booking:id,status'])
            ->get();
        $sessionTotal = (int) $packageSubjects->sum('allocated_sessions');
        $sessionCompleted = $packageSubjects
            ->flatMap->sessions
            ->filter(fn (PackageSession $session) => $session->booking?->status === 'completed')
            ->count();

        return response()->json([
            'teacher' => [
                'name' => $request->user()->name,
                'is_accepting_requests' => (bool) ($profile?->is_accepting_requests ?? false),
                'suspended_until' => $profile?->suspended_until,
            ],
            'priorities' => [
                'pending_offers' => $pendingOffers,
                'unread_messages' => $unreadMessages,
                'unread_notifications' => Notification::query()
                    ->where('user_id', $teacherId)
                    ->where('is_read', false)
                    ->count(),
                'schedule_responses' => $scheduleAnswers,
            ],
            'classes' => [
                'active' => $classes->whereIn('status', $activeStatuses)->count(),
                'in_progress' => $classes->where('status', 'in_progress')->count(),
                'awaiting_student' => $classes->where('status', 'awaiting_student_approval')->count(),
                'student_count' => $classes->flatMap->participants->pluck('student_id')->unique()->count(),
                'session_progress' => [
                    'total' => $sessionTotal,
                    'completed' => $sessionCompleted,
                    'remaining' => max(0, $sessionTotal - $sessionCompleted),
                ],
                'next' => $nextSession ? [
                    'id' => $nextSession->id,
                    'subject' => $nextSession->bookingRequest?->subject_name ?? 'Bimbingan',
                    'chapter' => $nextSession->bookingRequest?->chapter,
                    'start_at' => $nextSession->start_at,
                    'end_at' => $nextSession->end_at,
                    'learning_mode' => $nextSession->learning_mode,
                    'status' => $nextSession->status,
                ] : null,
            ],
            'earnings' => [
                'held' => round((float) ($balance?->held ?? 0)),
                'available' => round((float) ($balance?->available ?? 0)),
                'requested' => round((float) ($balance?->requested ?? 0)),
            ],
            'rating' => [
                'average' => round((float) ($rating?->rating_average ?? 0), 1),
                'count' => (int) ($rating?->rating_count ?? 0),
            ],
        ]);
    }

    public function performance(Request $request)
    {
        $teacherId = (int) $request->user()->id;
        $profile = TeacherProfile::query()->where('user_id', $teacherId)->firstOrFail();
        $ratings = Rating::query()
            ->where('teacher_id', $teacherId)
            ->with(['student:id,name', 'booking.bookingRequest:id,subject_name'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Rating $rating) => [
                'id' => $rating->id,
                'booking_id' => $rating->booking_id,
                'student_name' => $rating->student?->name ?? 'Murid BimbelKu',
                'subject' => $rating->booking?->bookingRequest?->subject_name,
                'rating' => (int) $rating->rating,
                'review' => $rating->review,
                'created_at' => $rating->created_at,
            ]);

        return response()->json([
            'suspended_until' => $profile->suspended_until,
            'rating' => [
                'average' => round((float) $ratings->avg('rating'), 1),
                'count' => $ratings->count(),
                'distribution' => collect([5, 4, 3, 2, 1])->mapWithKeys(
                    fn ($star) => [(string) $star => $ratings->where('rating', $star)->count()]
                ),
            ],
            'ratings' => $ratings,
        ]);
    }

    public function payoutRequests(Request $request)
    {
        return response()->json(TeacherPayoutRequest::query()
            ->where('teacher_id', $request->user()->id)
            ->latest('requested_at')
            ->limit(100)
            ->get()
            ->map(fn (TeacherPayoutRequest $item) => [
                'id' => $item->id,
                'booking_ids' => $item->booking_ids,
                'gross_amount' => (float) $item->gross_amount,
                'commission_amount' => (float) $item->commission_amount,
                'requested_amount' => (float) ($item->requested_amount ?? $item->net_amount),
                'tax_amount' => (float) ($item->tax_amount ?? 0),
                'net_amount' => (float) $item->net_amount,
                'bank_name' => $item->bank_name,
                'account_number_masked' => $this->maskAccount($item->getRawOriginal('account_number')),
                'account_name' => $item->account_name,
                'status' => $item->status,
                'review_notes' => $item->review_notes,
                'requested_at' => $item->requested_at,
                'processed_at' => $item->processed_at,
            ]));
    }

    public function requestPayout(Request $request, TeacherPayoutService $payouts)
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:'.TeacherPayoutService::MINIMUM_AMOUNT],
            // Rekening baru hanya dipakai bila dikirim; tanpa bank_details
            // transaksi memakai rekening tersimpan.
            'bank_details' => ['nullable', 'array'],
            'bank_details.payout_channel_code' => ['nullable', 'string', Rule::in(XenditPayoutChannelCatalog::codes())],
            'bank_details.bank_name' => ['nullable', 'string', 'max:100'],
            'bank_details.account_number' => ['nullable', 'string', 'min:8', 'max:20', 'regex:/^[0-9]+$/'],
            'bank_details.account_name' => ['nullable', 'string', 'max:150'],
            'verification' => ['nullable', 'array'],
            'verification.otp' => ['nullable', 'string', 'size:6'],
            'verification.password' => ['nullable', 'string', 'max:200'],
            'current_password' => ['nullable', 'string', 'max:200'],
        ]);
        if (isset($validated['current_password']) && ! isset($validated['verification']['password'])) {
            $validated['verification'] = array_merge($validated['verification'] ?? [], [
                'password' => $validated['current_password'],
            ]);
        }
        $teacher = $request->user();
        $available = $payouts->availableAmount((int) $teacher->id);
        if ($available <= 0) {
            return response()->json([
                'message' => 'Saldo tidak tersedia untuk dicairkan. Ada sesi yang dikembalikan setelah pencairan sebelumnya.',
            ], 422);
        }
        $amount = isset($validated['amount'])
            ? round((float) $validated['amount'], 2)
            : $available;
        try {
            $result = $payouts->request(
                $teacher,
                $amount,
                $validated['bank_details'] ?? null,
                $validated['verification'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Pencairan sedang dikirim otomatis ke rekening terdaftar.',
            'data' => [
                'id' => $result['request']->id,
                'requested_amount' => (float) $result['request']->requested_amount,
                'tax_amount' => (float) $result['request']->tax_amount,
                'transfer_amount' => (float) $result['request']->net_amount,
                'status' => $result['request']->status,
            ],
        ], 202);
    }

    private function maskAccount(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits === '' ? '-' : str_repeat('•', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
