<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Support\PublicMedia;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminClassController extends Controller
{
    private const MONITORED_STATUSES = [
        'confirmed',
        'in_progress',
        'awaiting_student_approval',
        'disputed',
        'absence_review',
        'admin_review_required',
        'completed',
        'emergency_refund_pending',
        'refund_pending',
        'partially_refunded',
        'refunded',
    ];

    private const ATTENTION_STATUSES = [
        'disputed',
        'absence_review',
        'admin_review_required',
        'emergency_refund_pending',
        'refund_pending',
    ];

    private const HISTORY_STATUSES = [
        'completed',
        'partially_refunded',
        'refunded',
    ];

    private const ACTIVE_STATUSES = [
        'in_progress',
        'awaiting_student_approval',
        'disputed',
        'absence_review',
        'admin_review_required',
        'emergency_refund_pending',
        'refund_pending',
    ];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $scope = (string) $request->query('scope', 'attention');
        $status = trim((string) $request->query('status', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $perPage = max(10, min(50, (int) $request->query('per_page', 20)));
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        abort_unless(in_array($scope, ['attention', 'active', 'upcoming', 'history'], true), 422, 'Scope monitoring tidak valid.');
        abort_if($status !== '' && ! in_array($status, self::MONITORED_STATUSES, true), 422, 'Status kelas tidak valid.');

        $timezone = config('app.timezone', 'Asia/Jakarta');
        $now = now();
        $fromBoundary = $dateFrom !== '' ? Carbon::createFromFormat('Y-m-d', $dateFrom, $timezone)->startOfDay() : null;
        $toBoundary = $dateTo !== '' ? Carbon::createFromFormat('Y-m-d', $dateTo, $timezone)->addDay()->startOfDay() : null;
        abort_if($fromBoundary && $toBoundary && $toBoundary->lte($fromBoundary), 422, 'Rentang tanggal monitoring tidak valid.');

        $base = Booking::query()->where('class_type', 'private')->whereIn('status', self::MONITORED_STATUSES);
        $attentionScope = fn ($query) => $query->whereIn('status', self::ATTENTION_STATUSES);

        $activeScope = fn ($query) => $query->where(function ($active) use ($now) {
            $active->whereIn('status', self::ACTIVE_STATUSES)
                ->orWhere(fn ($confirmed) => $confirmed->where('status', 'confirmed')->where('start_at', '<=', $now));
        });

        $summary = [
            'attention' => (clone $base)->where($attentionScope)->count(),
            'active' => (clone $base)->where($activeScope)->count(),
            'upcoming' => (clone $base)->where('status', 'confirmed')->where('start_at', '>', $now)->count(),
            'history' => (clone $base)->whereIn('status', self::HISTORY_STATUSES)->count(),
        ];

        $query = Booking::query()
            ->where('class_type', 'private')
            ->whereIn('status', self::MONITORED_STATUSES)
            ->when($scope === 'attention', $attentionScope)
            ->when($scope === 'active', $activeScope)
            ->when($scope === 'upcoming', fn ($q) => $q->where('status', 'confirmed')->where('start_at', '>', $now))
            ->when($scope === 'history', fn ($q) => $q->whereIn('status', self::HISTORY_STATUSES))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            // Range comparisons keep the existing start_at indexes usable; avoid DATE(start_at).
            ->when($fromBoundary, fn ($q) => $q->where('start_at', '>=', $fromBoundary))
            ->when($toBoundary, fn ($q) => $q->where('start_at', '<', $toBoundary))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery
                        ->whereHas('teacher', fn ($teacher) => $teacher->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('bookingRequest', function ($bookingRequest) use ($search) {
                            $bookingRequest
                                ->where('subject_name', 'like', "%{$search}%")
                                ->orWhere('chapter', 'like', "%{$search}%");
                        });
                });
            })
            ->select([
                'id', 'booking_request_id', 'teacher_id', 'class_type', 'learning_mode',
                'status', 'start_at', 'end_at',
            ])
            ->with([
                'teacher:id,name',
                'bookingRequest:id,subject_name,chapter',
            ])
            ->withCount([
                'participants as student_count' => fn ($participants) => $participants
                    ->whereNotIn('status', ['cancelled', 'teacher_rejected', 'payment_expired']),
            ]);

        if ($scope === 'upcoming') {
            $query->orderBy('start_at');
        } else {
            $query->orderByDesc('start_at');
        }

        $bookings = $query->paginate($perPage)->withQueryString();

        $bookings->getCollection()->transform(function (Booking $booking) {
            $request = $booking->bookingRequest;
            $isCompleted = in_array($booking->status, self::HISTORY_STATUSES, true);
            $attentionReason = in_array($booking->status, self::ATTENTION_STATUSES, true)
                ? 'Status sesi memerlukan keputusan admin.'
                : null;

            return [
                'id' => $booking->id,
                'title' => $this->classTitle($booking),
                'subject' => $request?->subject_name ?? 'Bimbingan',
                'chapter' => $request?->chapter,
                'type' => 'Privat',
                'class_type' => 'private',
                'method' => $booking->learning_mode,
                'status' => $booking->status,
                'status_label' => $this->statusLabel($booking->status),
                'teacher_name' => $booking->teacher?->name ?? 'Tutor belum terhubung',
                'student_count' => (int) $booking->student_count,
                'total_sessions' => 1,
                'completed_sessions' => $isCompleted ? 1 : 0,
                'progress' => $this->progress($booking->status),
                'needs_admin_attention' => $attentionReason !== null,
                'attention_reason' => $attentionReason,
                'action' => $this->actionForStatus($booking),
                'start_at' => $booking->start_at,
                'end_at' => $booking->end_at,
            ];
        });

        return response()->json([
            'data' => $bookings->items(),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'from' => $bookings->firstItem(),
                'to' => $bookings->lastItem(),
                'scope' => $scope,
            ],
            'summary' => $summary,
        ]);
    }

    public function show(int $id)
    {
        $booking = Booking::query()
            ->whereIn('status', self::MONITORED_STATUSES)
            ->with([
                'teacher.teacherProfile',
                'bookingRequest',
                'participants.student',
                'participants.order.refund',
                'reports' => fn ($query) => $query->latest(),
                'disputes' => fn ($query) => $query->latest(),
            ])
            ->findOrFail($id);

        $request = $booking->bookingRequest;
        $profile = $booking->teacher?->teacherProfile;
        $participants = $booking->participants
            ->whereNotIn('status', ['cancelled', 'teacher_rejected', 'payment_expired'])
            ->values();

        return response()->json([
            'info' => [
                'id' => $booking->id,
                'title' => $this->classTitle($booking),
                'subject' => $request?->subject_name ?? 'Bimbingan',
                'chapter' => $request?->chapter,
                'type' => 'Privat',
                'method' => $booking->learning_mode,
                'status' => $booking->status,
                'status_label' => $this->statusLabel($booking->status),
                'price' => (float) $booking->total_amount,
                'gross_amount' => (float) $booking->gross_amount,
                'teacher_net_amount' => (float) $booking->teacher_net_amount,
                'commission_percent' => (float) $booking->commission_percent,
                'payout_status' => $booking->payout_status,
                'progress' => $this->progress($booking->status),
                'location' => $booking->learning_mode === 'offline'
                    ? ($booking->address ?: 'Alamat belum tersedia')
                    : 'Kelas online',
                'meeting_link' => $booking->learning_mode === 'online'
                    ? $booking->meeting_link
                    : null,
                'start_at' => $booking->start_at,
                'end_at' => $booking->end_at,
                'completion_notes' => $booking->completion_notes,
            ],
            'teacher' => [
                'id' => $booking->teacher?->id,
                'name' => $booking->teacher?->name ?? 'Tutor tidak ditemukan',
                'email' => $booking->teacher?->email,
                'phone' => $profile?->whatsapp_number,
                'photo' => PublicMedia::url($profile?->photo),
            ],
            'students' => $participants->map(function ($participant) {
                return [
                    'id' => $participant->student?->id,
                    'name' => $participant->student?->name ?? 'Murid tidak ditemukan',
                    'email' => $participant->student?->email,
                    'phone' => $participant->student?->phone,
                    'status' => $participant->status,
                    'order_status' => $participant->order?->status,
                    'amount' => (float) $participant->amount,
                    'refund_status' => $participant->order?->refund?->status,
                    'joined_at' => $participant->created_at,
                ];
            })->values(),
            'sessions' => [[
                'id' => $booking->id,
                'title' => $request?->chapter ?: 'Sesi bimbingan',
                'date' => $booking->start_at?->translatedFormat('l, d F Y H:i').' WIB',
                'is_completed' => $booking->status === 'completed',
                'material' => $request?->chapter ?: 'Materi belum dirinci',
            ]],
            'latest_report' => $booking->reports->first(),
            'latest_dispute' => $booking->disputes->first(),
        ]);
    }

    private function actionForStatus(Booking $booking): ?array
    {
        return match ($booking->status) {
            'refund_pending',
            'emergency_refund_pending',
            'partially_refunded' => [
                'label' => 'Kelola Refund',
                'url' => '/admin/refunds',
            ],

            'disputed' => [
                'label' => 'Review Kasus',
                'url' => '/admin/cases',
            ],

            'absence_review',
            'admin_review_required',
            'awaiting_student_approval' => [
                'label' => 'Review Kelas',
                'url' => '/admin/cases',
            ],

            default => null,
        };
    }

    private function classTitle(Booking $booking): string
    {
        $request = $booking->bookingRequest;
        $detail = $request?->chapter;

        return trim(($request?->subject_name ?? 'Bimbingan').($detail ? " · {$detail}" : ''));
    }

    private function progress(string $status): int
    {
        return match ($status) {
            'confirmed' => 25,
            'in_progress' => 50,
            'awaiting_student_approval', 'disputed', 'absence_review', 'admin_review_required' => 75,
            'completed', 'emergency_refund_pending', 'refund_pending', 'partially_refunded', 'refunded' => 100,
            default => 0,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => 'Dikonfirmasi',
            'in_progress' => 'Sedang berlangsung',
            'awaiting_student_approval' => 'Menunggu persetujuan murid',
            'disputed' => 'Dalam keberatan',
            'absence_review' => 'Kehadiran diperiksa',
            'admin_review_required' => 'Menunggu tinjauan admin',
            'completed' => 'Selesai',
            'emergency_refund_pending' => 'Refund keadaan darurat',
            'refund_pending' => 'Refund diproses',
            'partially_refunded' => 'Sebagian direfund',
            'refunded' => 'Direfund',
            default => $status,
        };
    }
}
