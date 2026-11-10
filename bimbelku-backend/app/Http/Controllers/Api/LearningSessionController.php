<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\CheapClassSession;
use App\Models\ClassroomConversationState;
use App\Models\ClassroomMessage;
use App\Models\ClassroomMessageRead;
use App\Models\LearningProgressReport;
use App\Models\Notification;
use App\Models\PackageChapter;
use App\Models\PackageSession;
use App\Models\PackageSessionChapterLog;
use App\Models\ParticipantAttendance;
use App\Models\SessionAttendance;
use App\Models\Setting;
use App\Models\User;
use App\Services\ClassroomSystemMessageService;
use App\Support\PackageChapterProgress;
use App\Support\PublicMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LearningSessionController extends Controller
{
    public function __construct(private readonly ClassroomSystemMessageService $systemMessages) {}

    public function conversations(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['student', 'teacher'], true), 403);

        $bookings = $this->conversationBookingsQuery($user)
            ->with([
                'teacher:id,name',
                'teacher.teacherProfile:id,user_id,photo',
                'bookingRequest:id,subject_name,chapter',
                'participants.student:id,name',
                'participants.order:id,status',
            ])
            ->latest('start_at')
            ->limit(200)
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $this->systemMessages->ensureForBookings($bookings);

        $conversationKeys = $bookings
            ->map(fn (Booking $booking) => $this->conversationKey($booking, $user))
            ->unique()
            ->values();
        $states = ClassroomConversationState::query()
            ->where('user_id', $user->id)
            ->whereIn('conversation_key', $conversationKeys)
            ->get()
            ->keyBy('conversation_key');

        $payload = $bookings
            ->groupBy(fn (Booking $booking) => $this->conversationKey($booking, $user))
            ->map(function ($conversationBookings, string $conversationKey) use ($user, $states) {
                $bookingIds = $conversationBookings->pluck('id')->map(fn ($id) => (int) $id)->values();
                $state = $states->get($conversationKey);
                $hiddenThrough = (int) ($state?->hidden_through_message_id ?? 0);

                $latest = ClassroomMessage::query()
                    ->with('sender:id,name')
                    ->whereIn('booking_id', $bookingIds)
                    ->when($hiddenThrough > 0, fn ($messages) => $messages->where('id', '>', $hiddenThrough))
                    ->latest('id')
                    ->first();

                // Percakapan yang dihapus hanya disembunyikan untuk pengguna tersebut.
                // Percakapan muncul kembali ketika ada pesan baru setelah titik penghapusan.
                if (! $latest && $state) {
                    return null;
                }

                $canonical = $latest
                    ? $conversationBookings->firstWhere('id', $latest->booking_id)
                    : null;
                $canonical ??= $conversationBookings->sortByDesc('start_at')->first();
                if (! $canonical) {
                    return null;
                }

                $unread = ClassroomMessage::query()
                    ->whereIn('booking_id', $bookingIds)
                    ->when($hiddenThrough > 0, fn ($messages) => $messages->where('id', '>', $hiddenThrough))
                    ->where('sender_id', '!=', $user->id)
                    ->where('message_type', '!=', 'system')
                    ->whereDoesntHave('reads', fn ($reads) => $reads->where('user_id', $user->id))
                    ->count();

                $subjects = $conversationBookings
                    ->map(fn (Booking $booking) => $booking->bookingRequest?->subject_name)
                    ->filter()
                    ->unique()
                    ->values();
                $subjectLabel = $subjects->count() > 1
                    ? $subjects->first().' +'.($subjects->count() - 1)
                    : ($subjects->first() ?? 'Bimbingan');

                $paidStudents = $canonical->participants
                    ->filter(fn ($participant) => $participant->order?->status === 'paid');
                $counterpartName = $user->role === 'teacher'
                    ? ($paidStudents->first()?->student?->name ?? 'Murid BimbelKu')
                    : ($canonical->teacher?->name ?? 'Tutor BimbelKu');

                return [
                    'conversation_key' => $conversationKey,
                    'booking_id' => (int) $canonical->id,
                    'booking_ids' => $bookingIds,
                    'booking_count' => $bookingIds->count(),
                    'subject' => $subjectLabel,
                    'subjects' => $subjects,
                    'title' => $bookingIds->count() > 1
                        ? $bookingIds->count().' sesi terhubung'
                        : ($canonical->bookingRequest?->chapter ?: 'Ruang belajar'),
                    'counterpart_name' => $counterpartName,
                    'counterpart_avatar' => $user->role === 'student'
                        ? PublicMedia::url($canonical->teacher?->teacherProfile?->photo)
                        : null,
                    'class_type' => 'private',
                    'status' => $canonical->status,
                    'start_at' => $canonical->start_at,
                    'unread_count' => $unread,
                    'latest_message' => $latest ? [
                        'body' => $latest->body ?: ($latest->attachment_path ? 'Mengirim lampiran' : ''),
                        'sender_name' => $latest->message_type === 'system'
                            ? 'BimbelKu'
                            : ($latest->sender?->name ?? 'Pengguna BimbelKu'),
                        'created_at' => $latest->created_at,
                        'has_attachment' => (bool) $latest->attachment_path,
                    ] : null,
                ];
            })
            ->filter()
            ->sortByDesc(fn ($item) => $item['latest_message']['created_at'] ?? $item['start_at'])
            ->values();

        return response()->json(['data' => $payload]);
    }

    public function destroyConversation(Request $request, Booking $booking)
    {
        $role = $this->sessionRole($request->user(), $booking);
        $this->ensurePaidAccess($request->user(), $booking, $role);
        abort_unless(in_array($role, ['student', 'teacher'], true), 403);

        $conversationBookings = $this->relatedConversationBookings($request->user(), $booking);
        $this->systemMessages->ensureForBookings($conversationBookings);
        $bookingIds = $conversationBookings->pluck('id');
        $lastMessageId = (int) (ClassroomMessage::query()
            ->whereIn('booking_id', $bookingIds)
            ->max('id') ?? 0);
        $conversationKey = $this->conversationKey($booking, $request->user());

        ClassroomConversationState::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'conversation_key' => $conversationKey,
            ],
            [
                'hidden_through_message_id' => $lastMessageId,
                'hidden_at' => now(),
            ]
        );
        $this->markConversationBookingsRead($conversationBookings, $request->user());

        return response()->json([
            'message' => 'Percakapan dihapus dari daftar Anda. Chat akan muncul kembali jika ada pesan baru.',
        ]);
    }

    public function nextAction(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['student', 'teacher', 'admin'], true), 403);

        $actions = collect();

        if (in_array($user->role, ['student', 'teacher'], true)) {
            $query = Booking::query()
                ->where('class_type', 'private')
                ->whereIn('status', ['confirmed', 'in_progress', 'awaiting_student_approval'])
                ->with([
                    'teacher:id,name',
                    'bookingRequest:id,subject_name,chapter',
                    'participants.student:id,name',
                    'participants.order:id,status',
                    'participantAttendances:id,booking_id,booking_participant_id,student_id,status,marked_at',
                    'sessionAttendances:id,booking_id,user_id,role,check_in_at,check_out_at',
                    'learningProgressReports:id,booking_id,student_id,teacher_id,published_at',
                ]);

            if ($user->role === 'teacher') {
                $query->where('teacher_id', $user->id)
                    ->whereHas('participants.order', fn ($orders) => $orders->where('status', 'paid'));
            } else {
                $query->whereHas('participants', function ($participants) use ($user) {
                    $participants->where('student_id', $user->id)
                        ->whereHas('order', fn ($orders) => $orders->where('status', 'paid'));
                });
            }

            $bookings = $query
                ->where(function ($candidate) {
                    $candidate->whereIn('status', ['in_progress', 'awaiting_student_approval'])
                        ->orWhere(function ($confirmed) {
                            $confirmed->where('status', 'confirmed')
                                ->whereBetween('start_at', [now()->subHours(6), now()->addHours(6)]);
                        });
                })
                ->orderBy('start_at')
                ->limit(40)
                ->get();

            $actions = $actions->concat($bookings
                ->map(fn (Booking $booking) => $this->sessionReminderAction($user, $booking))
                ->filter());
        }

        $actions = $actions
            ->concat($this->cheapClassReminderActions($user))
            ->sort(function (array $left, array $right) {
                if ($left['priority'] !== $right['priority']) {
                    return $right['priority'] <=> $left['priority'];
                }

                return ($left['sort_at'] ?? PHP_INT_MAX) <=> ($right['sort_at'] ?? PHP_INT_MAX);
            })
            ->values();

        $next = $actions->first();
        if ($next) {
            unset($next['priority'], $next['sort_at']);
        }

        return response()->json([
            'data' => $next,
            'meta' => ['pending_count' => $actions->count(), 'refreshed_at' => now()->toIso8601String()],
        ]);
    }

    public function show(Request $request, Booking $booking)
    {
        $role = $this->sessionRole($request->user(), $booking);
        $this->ensurePaidAccess($request->user(), $booking, $role);
        abort_unless($booking->class_type === 'private', 410, 'Flow kelas grup lama sudah dipensiunkan.');

        $conversationMode = $request->boolean('conversation') && in_array($role, ['student', 'teacher'], true);
        $conversationBookings = collect([$booking]);
        $hiddenThrough = 0;
        if ($conversationMode) {
            $conversationBookings = $this->relatedConversationBookings($request->user(), $booking);
            $this->systemMessages->ensureForBookings($conversationBookings);
            $hiddenThrough = (int) (ClassroomConversationState::query()
                ->where('user_id', $request->user()->id)
                ->where('conversation_key', $this->conversationKey($booking, $request->user()))
                ->value('hidden_through_message_id') ?? 0);
        } else {
            $this->systemMessages->ensureOrderConnected($booking);
        }

        $booking->load([
            'teacher:id,name',
            'bookingRequest:id,subject_name,education_level,grade,chapter,learning_goal',
            'participants.student:id,name',
            'participants.bookingRequest:id,subject_name,education_level,grade,chapter,learning_goal',
            'participants.order:id,status',
            'learningProgressReports' => function ($query) use ($role, $request) {
                $query->with(['student:id,name', 'chapterLogs.chapter'])->oldest('session_number');
                if ($role === 'student') {
                    $query->where('student_id', $request->user()->id);
                }
            },
            'sessionAttendances.user:id,name',
            'participantAttendances',
            'scheduleChangeRequests' => fn ($query) => $query
                ->with(['requester:id,name,role', 'responses.user:id,name,role'])
                ->latest()->limit(10),
        ]);

        if ($conversationMode) {
            $this->markConversationBookingsRead($conversationBookings, $request->user(), $hiddenThrough);
        } else {
            $this->markConversationRead($booking, $request->user());
        }

        $rawMessages = ClassroomMessage::query()
            ->whereIn('booking_id', $conversationBookings->pluck('id'))
            ->when($conversationMode && $hiddenThrough > 0, fn ($messages) => $messages->where('id', '>', $hiddenThrough))
            ->with(['sender:id,name,role', 'reads'])
            ->latest('id')->limit(100)->get()->reverse()->values();
        if ($conversationMode) {
            $rawMessages = $rawMessages->unique(fn (ClassroomMessage $message) => $message->message_type === 'system'
                ? 'system:'.($message->system_event_key ?: 'generic').':'.md5((string) $message->body)
                : 'message:'.$message->id)->values();
        }
        $messages = $rawMessages->map(fn (ClassroomMessage $message) => $this->messagePayload(
            $message, (int) $request->user()->id, $role
        ));

        $participant = $booking->participants->firstWhere('student_id', $request->user()->id)
            ?? $booking->participants->first(fn ($item) => $item->order?->status === 'paid');
        $requestSnapshot = $participant?->bookingRequest ?? $booking->bookingRequest;
        $teacherAttendance = $booking->sessionAttendances->firstWhere('user_id', $booking->teacher_id);
        $paidParticipants = $booking->participants->filter(fn ($item) => $item->order?->status === 'paid');
        $participantPayload = $paidParticipants->map(function ($item) use ($booking, $role, $request) {
            if ($role === 'student' && (int) $item->student_id !== (int) $request->user()->id) {
                return null;
            }
            $attendance = $booking->participantAttendances->firstWhere('booking_participant_id', $item->id);

            return [
                'participant_id' => $item->id,
                'student_id' => $item->student_id,
                'name' => $item->student?->name ?? 'Murid BimbelKu',
                'attendance' => $attendance ? [
                    'status' => $attendance->status,
                    'notes' => $attendance->notes,
                    'marked_at' => $attendance->marked_at,
                ] : null,
            ];
        })->filter()->values();

        $scheduleChanges = $booking->scheduleChangeRequests->map(function ($change) use ($request) {
            $myResponse = $change->responses->firstWhere('user_id', $request->user()->id);

            return [
                'id' => $change->id,
                'requested_by' => $change->requested_by,
                'requester_name' => $change->requester?->name ?? 'Pengguna BimbelKu',
                'requester_role' => $change->requester_role,
                'scope' => $change->scope ?? 'single',
                'affected_count' => count($change->affected_schedules ?? []) ?: 1,
                'original_start_at' => $change->original_start_at,
                'original_end_at' => $change->original_end_at,
                'proposed_start_at' => $change->proposed_start_at,
                'proposed_end_at' => $change->proposed_end_at,
                'reason' => $change->reason,
                'status' => $change->status,
                'expires_at' => $change->expires_at,
                'my_decision' => $myResponse?->decision,
                'can_respond' => $change->status === 'pending' && $myResponse?->decision === 'pending'
                    && (! $change->expires_at || $change->expires_at->isFuture()),
                'responses' => $change->responses->map(fn ($response) => [
                    'user_name' => $response->user?->name ?? 'Pengguna',
                    'role' => $response->user?->role,
                    'decision' => $response->decision,
                    'responded_at' => $response->responded_at,
                ])->values(),
            ];
        })->values();

        $packageSession = PackageSession::query()
            ->where('booking_id', $booking->id)
            ->with(['subject.chapters' => fn ($query) => $query->orderBy('sort_order')])
            ->first();
        $packageChapters = $packageSession?->subject?->chapters ?? collect();
        $chapterSummary = PackageChapterProgress::summary($packageChapters);
        $materialProgress = $packageChapters->isNotEmpty()
            ? (int) $chapterSummary['progress_percent']
            : (int) ($booking->learningProgressReports->last()?->progress_percent ?? 0);
        $currentChapterLogs = $packageSession
            ? PackageSessionChapterLog::query()->where('package_session_id', $packageSession->id)->with('chapter')->get()
            : collect();
        $currentChapterLogs = PackageChapterProgress::chapterLogs($currentChapterLogs)->keyBy('chapter');

        return response()->json([
            'booking' => [
                'id' => $booking->id,
                'subject' => $requestSnapshot?->subject_name ?? 'Bimbingan',
                'education_level' => $requestSnapshot?->education_level,
                'grade' => $requestSnapshot?->grade,
                'chapter' => $requestSnapshot?->chapter,
                'requested_goal' => $requestSnapshot?->learning_goal,
                'teacher_name' => $booking->teacher?->name ?? 'Tutor',
                'student_name' => $participant?->student?->name ?? 'Murid',
                'learning_mode' => $booking->learning_mode,
                'class_type' => 'private',
                'status' => $booking->status,
                'session_flow_version' => 'presence_confirmation_v2',
                'tutor_ready_at' => $booking->tutor_ready_at,
                'student_confirmed_at' => $booking->student_confirmed_at,
                'session_focus_note' => $booking->session_focus_note,
                'start_at' => $booking->start_at,
                'end_at' => $booking->end_at,
                'session_started_at' => $booking->session_started_at,
                'session_ended_at' => $booking->session_ended_at,
            ],
            'role' => $role,
            'messages' => $messages,
            'progress_reports' => $booking->learningProgressReports->map(fn (LearningProgressReport $report) => [
                'id' => $report->id,
                'student_id' => $report->student_id,
                'student_name' => $report->student?->name ?? 'Murid BimbelKu',
                'session_number' => $report->session_number,
                'material_covered' => $report->material_covered,
                'mastered_skills' => $report->mastered_skills,
                'difficulties' => $report->difficulties,
                'next_exercise' => $report->next_exercise,
                'attendance' => $report->attendance,
                'progress_percent' => $report->progress_percent,
                'no_material_change' => (bool) $report->no_material_change,
                'no_change_reason' => $report->no_change_reason,
                'notes' => $report->notes,
                'actual_duration_minutes' => $report->actual_duration_minutes,
                'published_at' => $report->published_at,
                'chapters' => PackageChapterProgress::chapterLogs($report->chapterLogs),
            ])->values(),
            'learning_chapters' => PackageChapterProgress::chapters($packageChapters)->map(function (array $chapter) use ($currentChapterLogs) {
                $sessionLog = $currentChapterLogs->get($chapter['chapter']);

                return [
                    ...$chapter,
                    'session_status' => $sessionLog['status_after'] ?? null,
                    'session_activity' => $sessionLog['activity_type'] ?? null,
                    'session_needs_review' => $sessionLog['needs_review'] ?? null,
                    'session_notes' => $sessionLog['notes'] ?? null,
                ];
            })->values(),
            'material_progress_percent' => $materialProgress,
            'attendance' => $teacherAttendance,
            'participants' => $participantPayload,
            'schedule_changes' => $scheduleChanges,
            'permissions' => [
                'can_chat' => in_array($booking->status, $this->interactiveStatuses(), true),
                'can_mark_ready' => $role === 'teacher' && $booking->status === 'confirmed'
                    && $this->withinCheckInWindow($booking) && ! $booking->student_confirmed_at,
                'can_confirm_presence' => $role === 'student' && $booking->status === 'confirmed'
                    && $this->withinCheckInWindow($booking) && $booking->tutor_ready_at && ! $booking->student_confirmed_at,
                'can_check_out' => $role === 'teacher' && $teacherAttendance && ! $teacherAttendance->check_out_at,
                'can_report_progress' => $this->canReportProgress($booking, $role, $teacherAttendance),
                'can_request_schedule_change' => in_array($role, ['student', 'teacher'], true)
                    && $booking->status === 'confirmed' && now()->lt($booking->start_at),
                'can_request_future_schedule' => in_array($role, ['student', 'teacher'], true)
                    && $booking->status === 'confirmed' && now()->lt($booking->start_at)
                    && $packageSession && PackageSession::query()
                        ->where('package_subject_id', $packageSession->package_subject_id)
                        ->where('sequence', '>', $packageSession->sequence)
                        ->whereHas('booking', fn ($query) => $query->where('status', 'confirmed'))->exists(),
            ],
        ]);
    }

    public function storeMessage(Request $request, Booking $booking)
    {
        $role = $this->sessionRole($request->user(), $booking);
        $this->ensurePaidAccess($request->user(), $booking, $role);

        abort_unless(in_array($role, ['student', 'teacher'], true), 403);
        abort_unless(in_array($booking->status, $this->interactiveStatuses(), true), 422, 'Chat kelas sudah ditutup.');

        $maxMb = max(1, min(10, (int) (Setting::where('key', 'chat_attachment_max_mb')->value('value') ?? 5)));
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:1000', 'required_without:attachment'],
            'attachment' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:'.($maxMb * 1024),
                'required_without:body',
            ],
            'client_token' => ['nullable', 'uuid'],
        ]);
        $body = trim((string) ($validated['body'] ?? ''));
        if ($body !== '' && $this->containsExternalContact($body)) {
            throw ValidationException::withMessages([
                'body' => 'Nomor telepon, email, akun media sosial, dan tautan luar tidak boleh dikirim.',
            ]);
        }
        $clientToken = $validated['client_token'] ?? (string) Str::uuid();
        $existing = ClassroomMessage::query()
            ->where('booking_id', $booking->id)
            ->where('sender_id', $request->user()->id)
            ->where('client_token', $clientToken)
            ->first();
        if ($existing) {
            return response()->json([
                'message' => 'Pesan yang sama sudah tersimpan.',
                'data' => $this->messagePayload($existing, $request->user()->id),
            ]);
        }

        $path = $request->hasFile('attachment')
            ? $request->file('attachment')->store('classroom_attachments', 'local')
            : null;
        try {
            $message = DB::transaction(function () use (
                $request,
                $booking,
                $body,
                $path,
                $clientToken
            ) {
                $record = ClassroomMessage::create([
                    'booking_id' => $booking->id,
                    'sender_id' => $request->user()->id,
                    'body' => $body,
                    'attachment_path' => $path,
                    'attachment_name' => $request->file('attachment')?->getClientOriginalName(),
                    'attachment_mime' => $request->file('attachment')?->getMimeType(),
                    'attachment_size' => $request->file('attachment')?->getSize(),
                    'client_token' => $clientToken,
                ]);

                foreach ($this->chatRecipientIds($booking, (int) $request->user()->id) as $recipientId) {
                    Notification::updateOrCreate(
                        ['unique_key' => "classroom-message:{$record->id}:{$recipientId}"],
                        [
                            'user_id' => $recipientId,
                            'title' => 'Pesan kelas baru',
                            'message' => $body !== '' ? Str::limit($body, 140) : 'Lampiran baru dikirim melalui chat BimbelKu.',
                            'type' => 'info',
                            'target_url' => $this->messageTargetUrl($recipientId, $booking),
                            'is_read' => false,
                        ]
                    );
                }

                return $record;
            }, 3);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return response()->json([
            'message' => 'Pesan terkirim melalui chat BimbelKu.',
            'data' => $this->messagePayload($message, $request->user()->id),
        ], 201);
    }

    public function teacherReady(Request $request, Booking $booking)
    {
        $role = $this->sessionRole($request->user(), $booking);
        $this->ensurePaidAccess($request->user(), $booking, $role);
        abort_unless($role === 'teacher', 403);
        abort_unless($booking->class_type === 'private' && $this->isPresenceFlow($booking), 422, 'Sesi ini masih memakai alur verifikasi lama.');
        abort_unless($booking->status === 'confirmed', 422, 'Sesi ini sudah berubah status dan tidak dapat ditandai siap.');
        abort_unless($this->withinCheckInWindow($booking), 422, 'Tombol siap mengajar tersedia mendekati jadwal sesi.');
        abort_unless($booking->learning_mode !== 'online' || filled($booking->meeting_link), 422, 'Simpan tautan Zoom terlebih dahulu di Kelola sesi sebelum menyatakan siap mengajar.');
        $rules = [
            'focus_note' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'integer', 'min:0', 'max:5000'],
        ];
        if ($booking->learning_mode === 'offline') {
            $rules['latitude'][0] = 'required';
            $rules['longitude'][0] = 'required';
        }
        $validated = $request->validate($rules);

        if ($booking->learning_mode === 'offline') {
            $this->validateSessionLocation(
                $booking,
                (float) $validated['latitude'],
                (float) $validated['longitude']
            );
        }

        $result = DB::transaction(function () use ($request, $booking, $validated) {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            abort_unless($this->isPresenceFlow($locked), 422, 'Sesi ini masih memakai alur verifikasi lama.');
            abort_unless($locked->status === 'confirmed', 422, 'Sesi ini sudah berubah status dan tidak dapat ditandai siap.');
            abort_if($locked->student_confirmed_at, 422, 'Murid sudah mengonfirmasi kehadiran dan sesi telah dimulai.');
            abort_unless($locked->learning_mode !== 'online' || filled($locked->meeting_link), 422, 'Simpan tautan Zoom terlebih dahulu di Kelola sesi sebelum menyatakan siap mengajar.');

            $readyAt = $locked->tutor_ready_at ?: now();
            $locked->forceFill([
                'tutor_ready_at' => $readyAt,
                'session_focus_note' => trim((string) ($validated['focus_note'] ?? '')) ?: null,
                'tutor_ready_latitude' => $locked->learning_mode === 'offline' ? ($validated['latitude'] ?? null) : null,
                'tutor_ready_longitude' => $locked->learning_mode === 'offline' ? ($validated['longitude'] ?? null) : null,
                'tutor_ready_accuracy_meters' => $locked->learning_mode === 'offline' ? ($validated['accuracy_meters'] ?? null) : null,
                'tutor_ready_ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            ])->save();

            $studentId = $this->paidStudentId($locked);
            Notification::updateOrCreate(
                ['unique_key' => "presence-ready:{$locked->id}:{$studentId}"],
                [
                    'user_id' => $studentId,
                    'title' => 'Tutor sudah siap mengajar',
                    'message' => 'Konfirmasi kehadiranmu dengan satu tap agar sesi dapat dimulai.',
                    'type' => 'info',
                    'target_url' => "/student/my-classes?session={$locked->id}&session_action=presence",
                ]
            );

            return $locked->fresh();
        }, 3);

        return response()->json([
            'message' => 'Kesiapanmu tercatat. Murid diminta mengonfirmasi kehadiran.',
            'data' => [
                'tutor_ready_at' => $result->tutor_ready_at,
                'focus_note' => $result->session_focus_note,
            ],
        ]);
    }

    public function studentConfirmPresence(Request $request, Booking $booking)
    {
        $role = $this->sessionRole($request->user(), $booking);
        $this->ensurePaidAccess($request->user(), $booking, $role);
        abort_unless($role === 'student', 403);
        abort_unless($booking->class_type === 'private' && $this->isPresenceFlow($booking), 422, 'Sesi ini masih memakai alur verifikasi lama.');
        abort_unless(in_array($booking->status, ['confirmed', 'in_progress'], true), 422, 'Sesi ini sudah berubah status dan tidak dapat dikonfirmasi.');
        abort_unless($this->withinCheckInWindow($booking), 422, 'Konfirmasi kehadiran tersedia mendekati jadwal sesi.');
        $payload = DB::transaction(function () use ($request, $booking) {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            abort_unless($this->isPresenceFlow($locked), 422, 'Sesi ini masih memakai alur verifikasi lama.');
            abort_unless(in_array($locked->status, ['confirmed', 'in_progress'], true), 422, 'Sesi ini sudah berubah status dan tidak dapat dikonfirmasi.');
            abort_unless($locked->tutor_ready_at, 422, 'Tutor belum menyatakan siap mengajar.');

            $participant = $locked->participants()
                ->where('student_id', $request->user()->id)
                ->whereHas('order', fn ($query) => $query->where('status', 'paid'))
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->student_confirmed_at) {
                abort_unless($locked->status === 'in_progress', 422, 'Konfirmasi kehadiran sudah tercatat pada sesi yang tidak lagi aktif.');
                $attendance = $locked->sessionAttendances()
                    ->where('user_id', $locked->teacher_id)
                    ->first();

                return [$locked, $attendance, $participant];
            }

            abort_unless($locked->status === 'confirmed', 422, 'Sesi ini sudah berubah status dan tidak dapat dimulai.');

            $confirmedAt = now();
            $attendance = SessionAttendance::updateOrCreate(
                ['booking_id' => $locked->id, 'user_id' => $locked->teacher_id],
                [
                    'role' => 'teacher',
                    'check_in_at' => $confirmedAt,
                    'check_out_at' => null,
                    'latitude' => $locked->tutor_ready_latitude,
                    'longitude' => $locked->tutor_ready_longitude,
                    'accuracy_meters' => $locked->tutor_ready_accuracy_meters,
                    'ip_hash' => $locked->tutor_ready_ip_hash,
                ]
            );
            ParticipantAttendance::updateOrCreate(
                ['booking_participant_id' => $participant->id],
                [
                    'booking_id' => $locked->id,
                    'student_id' => $participant->student_id,
                    'marked_by' => $request->user()->id,
                    'status' => 'present',
                    'notes' => 'Kehadiran dikonfirmasi murid melalui Session Flow V2.',
                    'marked_at' => $confirmedAt,
                ]
            );

            $locked->forceFill([
                'status' => 'in_progress',
                'student_confirmed_at' => $confirmedAt,
                'session_started_at' => $confirmedAt,
            ])->save();

            Notification::create([
                'user_id' => $locked->teacher_id,
                'title' => 'Murid sudah hadir',
                'message' => 'Kehadiran murid telah dikonfirmasi. Sesi resmi dimulai, selamat mengajar.',
                'type' => 'success',
                'target_url' => "/guru/kelas?session={$locked->id}&session_action=session",
                'unique_key' => "presence-confirmed:{$locked->id}:{$locked->teacher_id}",
            ]);

            return [$locked->fresh(), $attendance, $participant];
        }, 3);

        return response()->json([
            'message' => 'Kehadiran terkonfirmasi. Sesi resmi dimulai, selamat belajar!',
            'data' => [
                'student_confirmed_at' => $payload[0]->student_confirmed_at,
                'session_started_at' => $payload[0]->session_started_at,
                'attendance_id' => $payload[1]?->id,
            ],
        ]);
    }

    public function teacherCheckOut(Request $request, Booking $booking)
    {
        $role = $this->sessionRole($request->user(), $booking);
        $this->ensurePaidAccess($request->user(), $booking, $role);
        abort_unless($role === 'teacher', 403);

        $attendance = DB::transaction(function () use ($booking, $request) {
            $attendance = SessionAttendance::query()
                ->where('booking_id', $booking->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (! $attendance) {
                throw ValidationException::withMessages(['session' => 'Check-in harus dilakukan terlebih dahulu.']);
            }
            if ($attendance->check_out_at) {
                throw ValidationException::withMessages(['session' => 'Check-out sudah tercatat.']);
            }

            $paidParticipantCount = $booking->participants()
                ->whereHas('order', fn ($query) => $query->where('status', 'paid'))
                ->count();
            $markedParticipantCount = ParticipantAttendance::query()
                ->where('booking_id', $booking->id)
                ->count();
            if ($paidParticipantCount < 1 || $markedParticipantCount !== $paidParticipantCount) {
                throw ValidationException::withMessages([
                    'attendance' => 'Kehadiran seluruh murid harus dicatat sebelum check-out.',
                ]);
            }

            $attendance->update(['check_out_at' => now()]);
            Booking::query()->whereKey($booking->id)->update(['session_ended_at' => now()]);

            return $attendance->fresh();
        });

        return response()->json([
            'message' => 'Check-out berhasil. Durasi sesi telah dicatat.',
            'data' => $attendance,
        ]);
    }

    public function storeProgressReport(Request $request, Booking $booking)
    {
        $role = $this->sessionRole($request->user(), $booking);
        $this->ensurePaidAccess($request->user(), $booking, $role);
        abort_unless($role === 'teacher', 403);
        abort_unless($booking->class_type === 'private', 410, 'Flow kelas grup lama sudah dipensiunkan.');
        abort_unless($booking->student_confirmed_at && $booking->status === 'in_progress', 422, 'Sesi harus sudah dikonfirmasi murid dan sedang berlangsung.');

        $attendance = $booking->sessionAttendances()->where('user_id', $request->user()->id)->first();
        abort_unless($attendance?->check_out_at, 422, 'Akhiri sesi terlebih dahulu sebelum menyimpan hasil belajar.');

        $validated = $request->validate([
            'material_covered' => ['required', 'string', 'min:3', 'max:3000'],
            'mastered_skills' => ['required', 'string', 'min:3', 'max:3000'],
            'difficulties' => ['nullable', 'string', 'max:3000'],
            'next_exercise' => ['required', 'string', 'min:3', 'max:3000'],
            'attendance' => ['nullable', Rule::in(['present', 'late', 'partial', 'excused'])],
            'no_material_change' => ['nullable', 'boolean'],
            'no_change_reason' => ['nullable', Rule::in(['review', 'practice', 'evaluation', 'remedial', 'session_disrupted', 'other'])],
            'chapter_updates' => ['nullable', 'array', 'max:8'],
            'chapter_updates.*.chapter' => ['required', 'string', 'max:180', 'distinct'],
            'chapter_updates.*.activity_type' => ['required', Rule::in(['taught', 'continued', 'reviewed'])],
            'chapter_updates.*.status_after' => ['required', Rule::in(['in_progress', 'completed'])],
            'chapter_updates.*.needs_review' => ['nullable', 'boolean'],
            'chapter_updates.*.notes' => ['nullable', 'string', 'max:1000'],
            // Paket tanpa Bab memakai penilaian materi tutor. Paket dengan Bab
            // tetap menghitung progress dari perubahan status Bab.
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $packageSession = PackageSession::query()
            ->where('booking_id', $booking->id)
            ->with(['subject.chapters', 'subject.package'])
            ->first();
        $packageChapters = $packageSession?->subject?->chapters ?? collect();
        // A new focus-only order has no declared chapter selection. If an old
        // chapter-based order loses its rows, do not silently bypass its progress.
        $requiresLegacyChapters = $packageSession
            && ! $packageSession->subject?->package?->learning_program_id
            && (filled($packageSession->subject?->chapter)
                || ! empty($packageSession->subject?->curriculum_chapter_ids));
        abort_if($requiresLegacyChapters && $packageChapters->isEmpty(), 422, 'Katalog Bab paket tidak tersedia. Hubungi admin.');
        $packageWithoutChapters = $packageSession && $packageChapters->isEmpty();
        $tracksChapters = $packageSession && $packageChapters->isNotEmpty();

        $noMaterialChange = (bool) ($validated['no_material_change'] ?? false);
        if ($tracksChapters) {
            if (! $noMaterialChange && empty($validated['chapter_updates'])) {
                throw ValidationException::withMessages(['chapter_updates' => 'Pilih sedikitnya satu Bab yang dibahas.']);
            }
            if ($noMaterialChange && empty($validated['no_change_reason'])) {
                throw ValidationException::withMessages(['no_change_reason' => 'Pilih alasan ketika sesi tidak mengubah progress Bab.']);
            }
            if ($noMaterialChange) {
                $validated['chapter_updates'] = [];
            } else {
                $validated['no_change_reason'] = null;
            }
        } elseif ($packageWithoutChapters) {
            if (! empty($validated['chapter_updates'])) {
                throw ValidationException::withMessages([
                    'chapter_updates' => 'Pesanan ini memakai target belajar tanpa pilihan Bab. Isi materi yang dibahas dan hasil belajar.',
                ]);
            }
            $validated['chapter_updates'] = [];
            $validated['no_material_change'] = false;
            $validated['no_change_reason'] = null;
        } else {
            // Tidak menghidupkan kembali BookingRequest UI lama. Ini hanya menjaga
            // Session Flow V2 tetap dapat menutup row booking privat lama yang sudah
            // terlanjur ada tanpa membutuhkan tabel Subbab yang telah dipensiunkan.
            $validated['chapter_updates'] = [];
            $validated['no_material_change'] = true;
            $validated['no_change_reason'] = $validated['no_change_reason'] ?? 'other';
        }

        $studentId = $this->paidStudentId($booking);
        $participant = $booking->participants()->where('student_id', $studentId)
            ->whereHas('order', fn ($query) => $query->where('status', 'paid'))->first();
        abort_unless($participant, 422, 'Murid tidak terdaftar sebagai peserta berbayar.');
        $participantAttendance = ParticipantAttendance::query()->where('booking_participant_id', $participant->id)->first();
        abort_unless($participantAttendance, 422, 'Kehadiran murid belum terkonfirmasi.');
        abort_if($participantAttendance->status === 'absent', 422, 'Murid yang tidak hadir memakai alur ketidakhadiran, bukan hasil belajar.');
        $validated['attendance'] = $participantAttendance->status;
        $durationMinutes = max(1, $attendance->check_in_at->diffInMinutes($attendance->check_out_at));

        $report = DB::transaction(function () use ($booking, $request, $validated, $durationMinutes, $studentId, $packageSession, $packageChapters, $tracksChapters) {
            if (LearningProgressReport::query()->where('booking_id', $booking->id)->where('student_id', $studentId)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['session' => 'Hasil belajar sesi ini sudah tersimpan.']);
            }

            $chapterUpdates = $packageSession
                ? collect($validated['chapter_updates'] ?? [])
                : collect();
            $submittedProgressPercent = (int) ($validated['progress_percent'] ?? 0);
            unset($validated['chapter_updates'], $validated['progress_percent']);

            if ($tracksChapters) {
                $chaptersByName = $packageChapters->keyBy(fn (PackageChapter $chapter) => trim((string) $chapter->title));

                foreach ($chapterUpdates as $update) {
                    $chapter = $chaptersByName->get(trim((string) $update['chapter']));
                    if (! $chapter) {
                        throw ValidationException::withMessages(['chapter_updates' => 'Bab tidak termasuk dalam target paket ini.']);
                    }

                    PackageSessionChapterLog::updateOrCreate(
                        ['package_session_id' => $packageSession->id, 'package_chapter_id' => $chapter->id],
                        [
                            'activity_type' => $update['activity_type'],
                            'status_before' => $chapter->status ?: 'not_started',
                            'status_after' => $update['status_after'],
                            'needs_review_after' => (bool) ($update['needs_review'] ?? false),
                            'notes' => trim((string) ($update['notes'] ?? '')) ?: null,
                        ]
                    );
                }

                // Session Flow V2 keeps chapter mutations provisional until the student
                // confirms the session. Preview the percentage without committing it here.
                $preview = $packageChapters->map(function (PackageChapter $chapter) use ($chapterUpdates) {
                    $update = $chapterUpdates->first(fn ($item) => trim((string) $item['chapter']) === trim((string) $chapter->title));
                    if (! $update) {
                        return $chapter;
                    }
                    $copy = clone $chapter;
                    $copy->status = $update['status_after'];
                    $copy->needs_review = (bool) ($update['needs_review'] ?? false);

                    return $copy;
                });
                $progressPercent = (int) PackageChapterProgress::summary($preview)['progress_percent'];
            } else {
                $progressPercent = $submittedProgressPercent;
            }

            $report = LearningProgressReport::create([
                ...$validated,
                'booking_id' => $booking->id,
                'student_id' => $studentId,
                'teacher_id' => $request->user()->id,
                'session_number' => $packageSession?->sequence ?? 1,
                'progress_percent' => $progressPercent,
                'actual_duration_minutes' => $durationMinutes,
                'published_at' => now(),
            ]);
            if ($packageSession) {
                PackageSessionChapterLog::query()->where('package_session_id', $packageSession->id)
                    ->update(['learning_progress_report_id' => $report->id]);
            }

            $reviewHours = max(1, min(72, (int) (Setting::where('key', 'session_presence_review_hours')->value('value') ?? 24)));
            $reviewDeadline = now()->addHours($reviewHours);
            Booking::query()->whereKey($booking->id)->update([
                'status' => 'awaiting_student_approval',
                'completion_notes' => ($validated['notes'] ?? null) ?: $validated['mastered_skills'],
                'completion_submitted_at' => now(),
                'objection_deadline' => $reviewDeadline,
                'payout_status' => 'locked',
            ]);
            $participantRow = BookingParticipant::query()->where('booking_id', $booking->id)
                ->where('student_id', $studentId)->lockForUpdate()->firstOrFail();
            $participantRow->update(['status' => 'awaiting_student_approval']);
            $participantRow->bookingRequest?->update(['status' => 'awaiting_student_approval']);

            Notification::updateOrCreate(
                ['unique_key' => "presence-review:{$booking->id}:{$studentId}"],
                [
                    'user_id' => $studentId,
                    'title' => 'Sesi selesai, cek sebentar',
                    'message' => 'Tutor sudah mengisi hasil belajar. Pilih Sesi Sesuai atau Ada masalah agar sesi dapat ditutup.',
                    'type' => 'info',
                    'target_url' => "/student/my-classes?session={$booking->id}&session_action=review",
                    'is_read' => false,
                ]
            );

            return $report;
        }, 3);

        return response()->json([
            'message' => 'Hasil belajar tersimpan. Murid sekarang diminta memilih Sesi Sesuai atau Ada masalah.',
            'data' => $report,
        ], 201);
    }

    private function sessionReminderAction(User $user, Booking $booking): ?array
    {
        if ($booking->class_type !== 'private') {
            return null;
        }
        $subject = $booking->bookingRequest?->subject_name ?: 'Bimbingan';
        $base = [
            'action_key' => "{$user->role}:{$booking->id}:{$booking->status}",
            'booking_id' => (int) $booking->id,
            'subject' => $subject,
            'teacher_name' => $booking->teacher?->name ?? 'Tutor BimbelKu',
            'start_at' => $booking->start_at,
            'end_at' => $booking->end_at,
        ];

        if ($user->role === 'student') {
            $participant = $booking->participants->first(fn ($item) => (int) $item->student_id === (int) $user->id && $item->order?->status === 'paid');
            if (! $participant) {
                return null;
            }

            if ($booking->status === 'awaiting_student_approval'
                && $participant->status === 'awaiting_student_approval'
                && ! $participant->approved_at
                && $booking->objection_deadline?->isFuture()) {
                return [
                    ...$base,
                    'action_key' => "student:{$booking->id}:review",
                    'kind' => 'student_review_session',
                    'priority' => 100,
                    'sort_at' => $booking->objection_deadline?->getTimestamp() ?? PHP_INT_MAX,
                    'target_url' => "/student/my-classes?session={$booking->id}&session_action=review",
                    'secondary_kind' => 'student_dispute',
                ];
            }

            if ($booking->status === 'confirmed' && $booking->tutor_ready_at && ! $booking->student_confirmed_at && $this->withinCheckInWindow($booking)) {
                return [
                    ...$base,
                    'action_key' => "student:{$booking->id}:presence",
                    'kind' => 'student_confirm_presence',
                    'priority' => 92,
                    'sort_at' => $booking->start_at?->getTimestamp() ?? PHP_INT_MAX,
                    'target_url' => "/student/my-classes?session={$booking->id}&session_action=presence",
                ];
            }

            if ($booking->status === 'in_progress' && $booking->student_confirmed_at) {
                return [
                    ...$base,
                    'action_key' => "student:{$booking->id}:session-started-v1",
                    'kind' => 'student_session_started',
                    'priority' => 78,
                    'sort_at' => $booking->session_started_at?->getTimestamp() ?? $booking->start_at?->getTimestamp() ?? PHP_INT_MAX,
                    'target_url' => "/student/my-classes?session={$booking->id}&session_action=started",
                    'informational' => true,
                ];
            }

            return null;
        }

        if ($user->role !== 'teacher' || (int) $booking->teacher_id !== (int) $user->id) {
            return null;
        }
        $teacherAttendance = $booking->sessionAttendances->firstWhere('user_id', $booking->teacher_id);
        $paidParticipants = $booking->participants->filter(fn ($participant) => $participant->order?->status === 'paid');
        if ($paidParticipants->isEmpty()) {
            return null;
        }
        $attendanceComplete = $paidParticipants->every(fn ($participant) => $booking->participantAttendances->contains('booking_participant_id', $participant->id));
        $progressComplete = $paidParticipants->every(fn ($participant) => $booking->learningProgressReports->contains('student_id', $participant->student_id));

        if (! $booking->tutor_ready_at && ! $booking->student_confirmed_at && $booking->status === 'confirmed' && $this->withinCheckInWindow($booking)) {
            return [...$base, 'action_key' => "teacher:{$booking->id}:ready", 'kind' => 'teacher_mark_ready', 'priority' => 82,
                'sort_at' => $booking->start_at?->getTimestamp() ?? PHP_INT_MAX, 'target_url' => "/guru/kelas?session={$booking->id}&session_action=ready"];
        }
        if ($booking->tutor_ready_at && ! $booking->student_confirmed_at && $booking->status === 'confirmed') {
            return [...$base, 'action_key' => "teacher:{$booking->id}:waiting-presence", 'kind' => 'teacher_waiting_student_presence', 'priority' => 58,
                'sort_at' => $booking->start_at?->getTimestamp() ?? PHP_INT_MAX, 'target_url' => "/guru/kelas?session={$booking->id}&session_action=ready"];
        }
        if ($teacherAttendance && ! $teacherAttendance->check_out_at && $attendanceComplete && $booking->student_confirmed_at
            && now()->lt($booking->end_at->copy()->subMinutes(15))) {
            return [...$base, 'action_key' => "teacher:{$booking->id}:session-ready-v2", 'kind' => 'teacher_session_ready', 'priority' => 40,
                'sort_at' => $booking->start_at?->getTimestamp() ?? PHP_INT_MAX, 'target_url' => "/guru/kelas?session={$booking->id}&session_action=session"];
        }
        if ($teacherAttendance && ! $teacherAttendance->check_out_at && $attendanceComplete
            && now()->gte($booking->end_at->copy()->subMinutes(15))) {
            return [...$base, 'action_key' => "teacher:{$booking->id}:check-out", 'kind' => 'teacher_check_out', 'priority' => 90,
                'sort_at' => $booking->end_at?->getTimestamp() ?? PHP_INT_MAX, 'target_url' => "/guru/kelas?session={$booking->id}&session_action=checkout"];
        }
        if ($teacherAttendance?->check_out_at && ! $progressComplete && $this->canReportProgress($booking, 'teacher', $teacherAttendance)) {
            return [...$base, 'action_key' => "teacher:{$booking->id}:progress", 'kind' => 'teacher_report_progress', 'priority' => 96,
                'sort_at' => $booking->end_at?->getTimestamp() ?? PHP_INT_MAX, 'target_url' => "/guru/kelas?session={$booking->id}&session_action=progress"];
        }

        return null;
    }

    private function cheapClassReminderActions(User $user)
    {
        if ($user->role === 'teacher') {
            return CheapClassSession::query()
                ->where(function ($sessions) {
                    $sessions
                        ->whereIn('status', ['report_required', 'revision_requested'])
                        ->orWhere(function ($current) {
                            $current
                                ->whereIn('status', ['scheduled', 'in_progress'])
                                ->where('starts_at', '<=', now()->addMinutes(10))
                                ->where('ends_at', '>', now());
                        })
                        ->orWhere(function ($endedScheduled) {
                            $endedScheduled
                                ->where('status', 'scheduled')
                                ->where('ends_at', '<=', now());
                        });
                })
                ->whereHas('cheapClass', fn ($classes) => $classes
                    ->where('teacher_id', $user->id)
                    ->where('status', 'confirmed'))
                ->with('cheapClass:id,teacher_id,subject_name,status')
                ->orderBy('ends_at')
                ->limit(20)
                ->get()
                ->map(function (CheapClassSession $session) {
                    $isRevision = $session->status === 'revision_requested';
                    $class = $session->cheapClass;
                    $isCurrent = $session->starts_at?->lte(now()->addMinutes(10)) && $session->ends_at?->isFuture();
                    $isStarted = $isCurrent && (bool) $session->teacher_started_at;
                    $actionKind = $isCurrent
                        ? ($isStarted ? 'cheap_teacher_session_started' : 'cheap_teacher_mark_ready')
                        : ($isRevision ? 'cheap_teacher_revision_requested' : 'cheap_teacher_report_required');

                    return [
                        'action_key' => "cheap-class:teacher:{$session->id}:{$actionKind}:".(int) ($session->report_revision_count ?? 0),
                        'kind' => $actionKind,
                        'priority' => $isCurrent ? ($isStarted ? 74 : 94) : ($isRevision ? 76 : 70),
                        'sort_at' => $session->ends_at?->getTimestamp() ?? PHP_INT_MAX,
                        'cheap_class_id' => (int) $session->cheap_class_id,
                        'cheap_class_session_id' => (int) $session->id,
                        'session_number' => (int) $session->session_number,
                        'subject' => $class?->subject_name ?: 'Kelas Kelompok',
                        'start_at' => $session->starts_at,
                        'end_at' => $session->ends_at,
                        'admin_review_notes' => $isRevision ? $session->admin_review_notes : null,
                        'target_url' => "/guru/kelas?class_kind=group&cheap_class={$session->cheap_class_id}&cheap_session={$session->id}&cheap_action=".($isCurrent ? ($isStarted ? 'live' : 'start') : ($isRevision ? 'revision' : 'report')),
                        'informational' => $isStarted,
                    ];
                });
        }

        if ($user->role === 'admin') {
            return CheapClassSession::query()
                ->where('status', 'awaiting_admin_verification')
                ->whereHas('cheapClass', fn ($classes) => $classes->where('status', 'confirmed'))
                ->with(['cheapClass:id,teacher_id,subject_name,status', 'cheapClass.teacher:id,name'])
                ->orderBy('report_submitted_at')
                ->limit(30)
                ->get()
                ->map(function (CheapClassSession $session) {
                    $class = $session->cheapClass;

                    return [
                        'action_key' => "cheap-class:admin:{$session->id}:verify:".(int) ($session->report_revision_count ?? 0),
                        'kind' => 'cheap_admin_verify_report',
                        'priority' => 100,
                        'sort_at' => $session->report_submitted_at?->getTimestamp() ?? PHP_INT_MAX,
                        'cheap_class_id' => (int) $session->cheap_class_id,
                        'cheap_class_session_id' => (int) $session->id,
                        'session_number' => (int) $session->session_number,
                        'subject' => $class?->subject_name ?: 'Kelas Kelompok',
                        'teacher_name' => $class?->teacher?->name ?? 'Tutor BimbelKu',
                        'start_at' => $session->starts_at,
                        'end_at' => $session->ends_at,
                        'attended_participants_count' => $session->attended_participants_count,
                        'target_url' => '/admin/kelas-murah/jadwal?view=sessions&scope=active&status=awaiting_admin_verification',
                    ];
                });
        }

        if ($user->role === 'student') {
            $notifications = Notification::query()
                ->where('user_id', $user->id)
                ->where('is_read', false)
                ->where(function ($items) use ($user) {
                    $items
                        ->where('unique_key', 'like', 'cheap-class-session-started:%:student:'.$user->id)
                        ->orWhere('unique_key', 'like', 'cheap-class-session-verified:%:student:'.$user->id);
                })
                ->latest('id')
                ->limit(20)
                ->get();

            $sessionIds = $notifications
                ->map(function (Notification $notification) {
                    return preg_match('/^cheap-class-session-(?:started|verified):(\d+):student:/', (string) $notification->unique_key, $matches)
                        ? (int) $matches[1]
                        : null;
                })
                ->filter()
                ->values();
            $sessions = CheapClassSession::query()
                ->whereIn('id', $sessionIds)
                ->with('cheapClass:id,subject_name,status')
                ->get()
                ->keyBy('id');

            return $notifications->map(function (Notification $notification) use ($sessions) {
                preg_match('/^cheap-class-session-(started|verified):(\d+):student:/', (string) $notification->unique_key, $matches);
                $event = $matches[1] ?? 'verified';
                $session = isset($matches[2]) ? $sessions->get((int) $matches[2]) : null;
                $class = $session?->cheapClass;
                $completed = $class?->status === 'completed' || $notification->title === 'Kelas Kelompok selesai';
                $started = $event === 'started';
                if ($started && (! $session || $session->status !== 'in_progress' || ! $session->ends_at?->isFuture())) {
                    return null;
                }

                return [
                    'action_key' => "cheap-class:student:notification:{$notification->id}",
                    'kind' => $started ? 'cheap_student_session_started' : ($completed ? 'cheap_student_class_completed' : 'cheap_student_progress_updated'),
                    'priority' => $started ? 90 : 20,
                    'sort_at' => $notification->id * -1,
                    'cheap_class_id' => $class?->id ? (int) $class->id : null,
                    'cheap_class_session_id' => $session?->id ? (int) $session->id : null,
                    'session_number' => $session?->session_number ? (int) $session->session_number : null,
                    'notification_id' => (int) $notification->id,
                    'subject' => $class?->subject_name ?: 'Kelas Kelompok',
                    'start_at' => $session?->starts_at,
                    'end_at' => $session?->ends_at,
                    'target_url' => $notification->target_url ?: '/student/progress',
                    'informational' => true,
                ];
            })->filter()->values();
        }

        return collect();
    }

    private function sessionRole(User $user, Booking $booking): string
    {
        if ($user->role === 'admin') {
            return 'admin';
        }
        if ($user->role === 'teacher' && $booking->teacher_id === $user->id) {
            return 'teacher';
        }
        if (
            $user->role === 'student'
            && $booking->participants()->where('student_id', $user->id)->exists()
        ) {
            return 'student';
        }

        abort(403, 'Anda tidak terdaftar pada sesi ini.');
    }

    private function ensurePaidAccess(User $user, Booking $booking, string $role): void
    {
        if ($role === 'admin') {
            return;
        }
        if ($role === 'teacher') {
            abort_unless(
                $booking->participants()
                    ->whereHas('order', fn ($query) => $query->where('status', 'paid'))
                    ->exists(),
                403,
                'Chat dan pelaksanaan dibuka setelah pembayaran dikonfirmasi.'
            );

            return;
        }

        abort_unless(
            $booking->participants()
                ->where('student_id', $user->id)
                ->whereHas('order', fn ($query) => $query->where('status', 'paid'))
                ->exists(),
            403,
            'Chat dan pelaksanaan dibuka setelah pembayaran dikonfirmasi.'
        );
    }

    private function paidStudentId(Booking $booking): int
    {
        $participant = $booking->participants()
            ->whereHas('order', fn ($query) => $query->where('status', 'paid'))
            ->oldest('id')
            ->first();
        abort_unless($participant, 422, 'Belum ada pembayaran murid yang dikonfirmasi.');

        return (int) $participant->student_id;
    }

    private function isPresenceFlow(Booking $booking): bool
    {
        return $booking->class_type === 'private';
    }

    private function interactiveStatuses(): array
    {
        return [
            'confirmed',
            'in_progress',
            'awaiting_student_approval',
            'disputed',
            'absence_review',
            'admin_review_required',
            'completed',
        ];
    }

    private function conversationBookingsQuery(User $user)
    {
        $query = Booking::query()
            ->where('class_type', 'private')
            ->whereIn('status', $this->interactiveStatuses());

        if ($user->role === 'teacher') {
            $query->where('teacher_id', $user->id)
                ->whereHas('participants.order', fn ($orders) => $orders->where('status', 'paid'));
        } else {
            $query->whereHas('participants', function ($participants) use ($user) {
                $participants->where('student_id', $user->id)
                    ->whereHas('order', fn ($orders) => $orders->where('status', 'paid'));
            });
        }

        return $query;
    }

    private function relatedConversationBookings(User $user, Booking $booking)
    {
        abort_unless($booking->class_type === 'private', 410, 'Percakapan kelas grup lama sudah dipensiunkan.');
        $studentId = $this->conversationStudentId($booking, $user);
        abort_unless($studentId, 403, 'Peserta percakapan tidak ditemukan.');

        $query = $this->conversationBookingsQuery($user)
            ->with([
                'teacher:id,name',
                'teacher.teacherProfile:id,user_id,photo',
                'bookingRequest:id,subject_name,chapter',
                'participants.student:id,name',
                'participants.bookingRequest:id,subject_name,chapter',
                'participants.order:id,status',
            ])
            ->where('class_type', 'private')
            ->where('teacher_id', $booking->teacher_id)
            ->whereHas('participants', function ($participants) use ($studentId) {
                $participants->where('student_id', $studentId)
                    ->whereHas('order', fn ($orders) => $orders->where('status', 'paid'));
            });

        $bookings = $query->latest('start_at')->limit(200)->get();
        abort_unless($bookings->contains(fn (Booking $item) => (int) $item->id === (int) $booking->id), 403, 'Percakapan ini tidak dapat diakses.');

        return $bookings;
    }

    private function conversationKey(Booking $booking, User $viewer): string
    {
        $studentId = $this->conversationStudentId($booking, $viewer) ?: 0;

        return 'private:'.(int) $booking->teacher_id.':'.(int) $studentId;
    }

    private function conversationStudentId(Booking $booking, User $viewer): ?int
    {
        if ($viewer->role === 'student') {
            return (int) $viewer->id;
        }

        $booking->loadMissing('participants.order');
        $participant = $booking->participants
            ->first(fn ($item) => $item->order?->status === 'paid');

        return $participant?->student_id
            ? (int) $participant->student_id
            : ($booking->student_id ? (int) $booking->student_id : null);
    }

    private function withinCheckInWindow(Booking $booking): bool
    {
        if (! in_array($booking->status, ['confirmed', 'in_progress'], true)) {
            return false;
        }
        $before = max(
            0,
            (int) (Setting::where('key', 'session_checkin_before_minutes')->value('value') ?? 30)
        );
        $after = max(
            15,
            (int) (Setting::where('key', 'session_checkin_after_minutes')->value('value') ?? 60)
        );

        return now()->between(
            $booking->start_at->copy()->subMinutes($before),
            $booking->start_at->copy()->addMinutes($after)
        );
    }

    private function validateSessionLocation(Booking $booking, float $latitude, float $longitude): void
    {
        $booking->loadMissing('bookingRequest');
        $request = $booking->bookingRequest;
        if ($request?->latitude === null || $request?->longitude === null) {
            throw ValidationException::withMessages([
                'latitude' => 'Koordinat lokasi sesi belum tersedia. Hubungi admin.',
            ]);
        }
        $distance = $this->distanceKm(
            (float) $request->latitude,
            (float) $request->longitude,
            $latitude,
            $longitude
        );
        $tolerance = max(
            0.1,
            min(3, (float) (Setting::where('key', 'session_location_tolerance_km')->value('value') ?? 1))
        );
        if ($distance > $tolerance) {
            throw ValidationException::withMessages([
                'latitude' => "Lokasi check-in berjarak {$distance} km dari lokasi sesi.",
            ]);
        }
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    private function containsExternalContact(string $body): bool
    {
        return preg_match('/\b(?:https?:\/\/|www\.)\S+/iu', $body) === 1
            || preg_match('/\b(?:[a-z0-9-]+\.)+(?:com|id|net|org|me|io|co)\b/iu', $body) === 1
            || preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu', $body) === 1
            || preg_match('/(?<!\d)(?:\+?62|0)[\s().-]*(?:\d[\s().-]*){8,13}(?!\d)/u', $body) === 1
            || preg_match('/(?:^|\s)@[a-z0-9_.]{3,}/iu', $body) === 1
            || preg_match('/\b(?:whats?app|telegram|instagram|facebook|tiktok|discord|line|wa|ig)\b/iu', $body) === 1;
    }

    private function messagePayload(
        ClassroomMessage $message,
        int $viewerId,
        ?string $viewerRole = null,
    ): array {
        $message->loadMissing('sender:id,name,role');
        $isSystem = $message->message_type === 'system';
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $actionUrl = $viewerRole === 'teacher'
            ? ($metadata['action_url_teacher'] ?? null)
            : ($metadata['action_url_student'] ?? null);

        return [
            'id' => $message->id,
            'body' => $message->body,
            'message_type' => $message->message_type ?: 'user',
            'system_event_key' => $message->system_event_key,
            'metadata' => [
                ...$metadata,
                'action_url' => $actionUrl,
            ],
            'sender_id' => $message->sender_id,
            'sender_name' => $isSystem ? 'BimbelKu' : ($message->sender?->name ?? 'Pengguna BimbelKu'),
            'sender_role' => $isSystem ? 'system' : $message->sender?->role,
            'is_mine' => ! $isSystem && (int) $message->sender_id === $viewerId,
            'is_read' => $message->relationLoaded('reads') && $message->reads->isNotEmpty(),
            'attachment' => $message->attachment_path ? [
                'name' => $message->attachment_name,
                'mime' => $message->attachment_mime,
                'size' => (int) $message->attachment_size,
                'url' => "classroom-messages/{$message->id}/attachment",
            ] : null,
            'created_at' => $message->created_at,
        ];
    }

    private function markConversationBookingsRead($bookings, User $viewer, int $afterMessageId = 0): void
    {
        foreach ($bookings as $booking) {
            $this->markConversationRead($booking, $viewer, $afterMessageId);
        }
    }

    private function markConversationRead(
        Booking $booking,
        User $viewer,
        int $afterMessageId = 0,
    ): void {
        $booking->classroomMessages()
            ->when($afterMessageId > 0, fn ($messages) => $messages->where('id', '>', $afterMessageId))
            ->where(function ($messages) use ($viewer) {
                $messages->where('sender_id', '!=', $viewer->id)
                    ->orWhere('message_type', 'system');
            })
            ->whereDoesntHave('reads', fn ($reads) => $reads->where('user_id', $viewer->id))
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($messages) use ($viewer) {
                $now = now();
                ClassroomMessageRead::insertOrIgnore($messages->map(fn ($message) => [
                    'classroom_message_id' => $message->id,
                    'user_id' => $viewer->id,
                    'read_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    private function canReportProgress(Booking $booking, string $role, ?SessionAttendance $teacherAttendance): bool
    {
        if ($role !== 'teacher' || ! $teacherAttendance?->check_out_at || ! $booking->student_confirmed_at) {
            return false;
        }
        $reportedStudentIds = $booking->learningProgressReports->pluck('student_id')->map(fn ($id) => (int) $id);

        return $booking->participants
            ->filter(fn ($participant) => $participant->order?->status === 'paid')
            ->contains(fn ($participant) => ! $reportedStudentIds->contains((int) $participant->student_id));
    }

    private function chatRecipientIds(Booking $booking, int $senderId): array
    {
        return collect([$booking->teacher_id])
            ->merge($booking->participants()
                ->whereHas('order', fn ($query) => $query->where('status', 'paid'))
                ->pluck('student_id'))
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === $senderId)
            ->unique()
            ->values()
            ->all();
    }

    private function messageTargetUrl(int $recipientId, Booking $booking): string
    {
        return $recipientId === (int) $booking->teacher_id
            ? "/guru/pesan?booking={$booking->id}"
            : "/student/messages?booking={$booking->id}";
    }
}
