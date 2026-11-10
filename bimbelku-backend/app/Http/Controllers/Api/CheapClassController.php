<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheapClass;
use App\Models\CheapClassEnrollment;
use App\Services\CheapClassService;
use App\Support\CheapClassSchema;
use Illuminate\Http\Request;

class CheapClassController extends Controller
{
    public function index(Request $request, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $cheapClasses->refreshLifecycle();
        $progressScope = $request->query('scope') === 'progress';
        $ownedScope = $request->query('scope') === 'owned';

        $classes = CheapClass::query()
            ->with([
                'enrollments' => fn ($items) => $items
                    ->where('student_id', $request->user()->id)
                    ->with('order'),
                'teacher.teacherProfile',
                'sessions',
            ])
            ->withCount([
                'enrollments as active_participant_count' => fn ($items) => $items
                    ->whereIn('status', ['seat_held', 'payment_submitted', 'payment_rejected', 'confirmed']),
                'enrollments as confirmed_participant_count' => fn ($items) => $items
                    ->where('status', 'confirmed'),
                'enrollments as pending_payment_count' => fn ($items) => $items
                    ->whereIn('status', ['seat_held', 'payment_submitted', 'payment_rejected']),
            ])
            ->when($progressScope, function ($query) use ($request) {
                $query
                    ->whereIn('status', ['confirmed', 'completed'])
                    ->whereHas('enrollments', fn ($items) => $items
                        ->where('student_id', $request->user()->id)
                        ->where('status', 'confirmed')
                        ->whereHas('order', fn ($orders) => $orders->where('status', 'paid')));
            }, function ($query) use ($request, $ownedScope) {
                if ($ownedScope) {
                    $query->whereHas('enrollments', fn ($items) => $items
                        ->where('student_id', $request->user()->id));

                    return;
                }

                $query
                    ->whereIn('status', ['waiting_teacher', 'open', 'registration_closed', 'awaiting_verification', 'confirmed', 'completed', 'cancelled'])
                    ->where(function ($visibility) use ($request) {
                        $visibility->whereHas('enrollments', fn ($items) => $items
                            ->where('student_id', $request->user()->id))
                            ->orWhere(function ($offers) {
                                $offers->where('status', 'open')
                                    ->whereNotNull('teacher_id')
                                    ->where('registration_opens_at', '<=', now())
                                    ->where('registration_deadline', '>', now())
                                    ->whereHas('sessions', fn ($sessions) => $sessions->where('ends_at', '>', now()));
                            });
                    });
            })
            ->when(
                $progressScope,
                fn ($query) => $query->orderByDesc('starts_at'),
                fn ($query) => $query->orderBy('starts_at')
            )
            ->limit(100)
            ->get();

        return response()->json($classes->map(
            fn (CheapClass $class) => $cheapClasses->studentPayload($class, $request->user()->id)
        )->values());
    }

    public function show(Request $request, CheapClass $cheapClass, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $cheapClasses->refreshLifecycle();
        $class = $cheapClass->fresh();
        $progressScope = $request->query('scope') === 'progress';
        $hasEnrollment = $class->enrollments()
            ->where('student_id', $request->user()->id)
            ->exists();
        $hasProgressAccess = $progressScope
            && in_array($class->status, ['confirmed', 'completed'], true)
            && $class->enrollments()
                ->where('student_id', $request->user()->id)
                ->where('status', 'confirmed')
                ->whereHas('order', fn ($orders) => $orders->where('status', 'paid'))
                ->exists();
        $isPublicOffer = ! $progressScope
            && $class->status === 'open'
            && $class->teacher_id
            && $class->registration_opens_at->lte(now())
            && $class->registration_deadline->isFuture()
            && $class->sessions()->where('ends_at', '>', now())->exists();
        abort_unless(
            $progressScope ? $hasProgressAccess : ($hasEnrollment || $isPublicOffer),
            404,
            'Kelas Kelompok tidak ditemukan atau tidak lagi ditawarkan.'
        );

        $class->load([
            'enrollments' => fn ($items) => $items
                ->where('student_id', $request->user()->id)
                ->with('order'),
            'teacher.teacherProfile',
            'sessions',
        ])->loadCount([
            'enrollments as active_participant_count' => fn ($items) => $items
                ->whereIn('status', ['seat_held', 'payment_submitted', 'payment_rejected', 'confirmed']),
            'enrollments as confirmed_participant_count' => fn ($items) => $items
                ->where('status', 'confirmed'),
            'enrollments as pending_payment_count' => fn ($items) => $items
                ->whereIn('status', ['seat_held', 'payment_submitted', 'payment_rejected']),
        ]);

        return response()->json($cheapClasses->studentPayload($class, $request->user()->id));
    }

    public function join(Request $request, CheapClass $cheapClass, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        [$enrollment, $order] = $cheapClasses->join($cheapClass, $request->user());

        return response()->json([
            'message' => 'Kursi berhasil ditahan. Selesaikan pembayaran sebelum batas waktu.',
            'enrollment_id' => $enrollment->id,
            'order_id' => $order->id,
            'order_number' => $order->order_id,
            'payment_due_at' => $enrollment->seat_expires_at,
            'order_kind' => 'cheap_class',
            'enrollment_status' => $enrollment->status,
            'cheap_class_status' => $enrollment->cheapClass?->status ?? $cheapClass->status,
            'can_cancel' => true,
            'can_resubmit' => false,
        ], 201);
    }

    public function cancel(Request $request, CheapClassEnrollment $cheapClassEnrollment, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $cheapClasses->cancelEnrollment($cheapClassEnrollment, $request->user());

        return response()->json(['message' => 'Keikutsertaan Kelas Kelompok dibatalkan.']);
    }
}
