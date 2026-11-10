<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheapClass;
use App\Models\CheapClassSession;
use App\Models\Notification;
use App\Models\User;
use App\Services\CheapClassService;
use App\Support\CheapClassSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeacherCheapClassController extends Controller
{
    public function index(Request $request, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $cheapClasses->refreshLifecycle();
        $classes = CheapClass::query()
            ->where('teacher_id', $request->user()->id)
            ->withCount(['enrollments as confirmed_participants_count' => fn ($items) => $items->where('status', 'confirmed')])
            ->with(['sessions' => fn ($sessions) => $sessions->orderBy('session_number')])
            ->orderByDesc('starts_at')
            ->limit(200)
            ->get()
            ->map(function (CheapClass $class) use ($cheapClasses) {
                $payload = $class->toArray();
                $payload['subjects'] = $cheapClasses->classSubjects($class);
                $payload['progress_summary'] = $cheapClasses->chapterProgressSummary($class);
                $payload['subject_name'] = $cheapClasses->subjectSummary($class);
                $payload['chapter'] = $cheapClasses->chapterSummary($class);

                return $payload;
            });

        return response()->json($classes);
    }

    public function updateMeetingLink(Request $request, CheapClass $cheapClass)
    {
        CheapClassSchema::ensureReady();
        abort_unless((int) $cheapClass->teacher_id === (int) $request->user()->id, 403);
        $data = $request->validate(['meeting_link' => [
            'required',
            'url:http,https',
            'max:500',
            function (string $attribute, mixed $value, \Closure $fail) {
                $host = mb_strtolower((string) parse_url((string) $value, PHP_URL_HOST));
                if ($host !== 'zoom.us' && ! str_ends_with($host, '.zoom.us')) {
                    $fail('Gunakan tautan Zoom resmi dengan domain zoom.us.');
                }
            },
        ]]);
        abort_unless($cheapClass->status === 'confirmed', 422, 'Tautan Zoom dapat diisi setelah kelas dikonfirmasi.');
        abort_unless($cheapClass->sessions()->where('ends_at', '>', now())->exists(), 422, 'Seluruh sesi paket sudah berakhir.');
        $cheapClass->update(['meeting_link' => $data['meeting_link']]);

        return response()->json(['message' => 'Tautan Zoom Kelas Kelompok disimpan.']);
    }

    public function startSession(Request $request, CheapClass $cheapClass)
    {
        CheapClassSchema::ensureReady();
        abort_unless((int) $cheapClass->teacher_id === (int) $request->user()->id, 403);
        abort_unless($cheapClass->status === 'confirmed', 422, 'Kelas belum siap untuk dimulai.');
        abort_unless($cheapClass->meeting_link, 422, 'Isi tautan Zoom terlebih dahulu sebelum memulai kelas.');

        [$session, $justStarted] = DB::transaction(function () use ($request, $cheapClass) {
            $session = CheapClassSession::query()
                ->where('cheap_class_id', $cheapClass->id)
                ->where('starts_at', '<=', now()->addMinutes(10))
                ->where('ends_at', '>', now())
                ->orderBy('session_number')
                ->lockForUpdate()
                ->first();

            abort_unless($session, 422, 'Belum ada sesi yang dapat dimulai saat ini.');

            $justStarted = ! $session->teacher_started_at;
            if ($justStarted) {
                $session->update([
                    'status' => 'in_progress',
                    'teacher_started_at' => now(),
                    'teacher_started_by' => $request->user()->id,
                ]);

                $cheapClass->enrollments()
                    ->where('status', 'confirmed')
                    ->whereHas('order', fn ($orders) => $orders->where('status', 'paid'))
                    ->pluck('student_id')
                    ->each(function (int $studentId) use ($cheapClass, $session) {
                        Notification::updateOrCreate(
                            ['unique_key' => "cheap-class-session-started:{$session->id}:student:{$studentId}"],
                            [
                                'user_id' => $studentId,
                                'title' => 'Kelas Kelompok sudah dimulai',
                                'message' => "Tutor sudah hadir dan memulai sesi {$session->session_number}. Buka Kelas Saya untuk bergabung.",
                                'type' => 'info',
                                'target_url' => "/student/my-classes?class_kind=group&cheap_class={$cheapClass->id}&cheap_session={$session->id}&session_action=started",
                                'is_read' => false,
                            ]
                        );
                    });
            }

            return [$session->fresh(), $justStarted];
        }, 3);

        return response()->json([
            'message' => $justStarted
                ? 'Kehadiran tercatat. Kelas dimulai dan murid sudah diberi tahu.'
                : 'Kehadiran sudah tercatat. Kelas masih berlangsung.',
            'session' => $session,
        ]);
    }

    public function updateProgress(Request $request, CheapClass $cheapClass, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $cheapClasses->refreshLifecycle(false);
        abort_unless((int) $cheapClass->teacher_id === (int) $request->user()->id, 403);
        abort_unless(
            $cheapClass->status === 'confirmed',
            422,
            'Laporan sesi hanya dapat dikirim pada Kelas Kelompok yang sedang berjalan.'
        );

        $validated = $request->validate([
            'session_id' => ['required', 'integer', 'exists:cheap_class_sessions,id'],
            'attended_participants_count' => ['required', 'integer', 'min:0'],
            'session_notes' => ['required', 'string', 'min:10', 'max:1500'],
            'updates' => ['required', 'array', 'min:1', 'max:4'],
            'updates.*.subject_index' => ['required', 'integer', 'min:0', 'max:3', 'distinct'],
            'updates.*.progress_status' => ['required', Rule::in(['not_started', 'in_progress', 'completed'])],
            'updates.*.needs_review' => ['nullable', 'boolean'],
            'updates.*.progress_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        [$session, $subjects, $summary] = DB::transaction(function () use ($request, $cheapClass, $cheapClasses, $validated) {
            $class = CheapClass::query()->lockForUpdate()->findOrFail($cheapClass->id);
            abort_unless((int) $class->teacher_id === (int) $request->user()->id, 403);
            abort_unless($class->status === 'confirmed', 422, 'Kelas Kelompok sudah tidak menerima laporan sesi baru.');

            /** @var CheapClassSession|null $session */
            $session = CheapClassSession::query()
                ->whereKey((int) $validated['session_id'])
                ->where('cheap_class_id', $class->id)
                ->lockForUpdate()
                ->first();
            abort_unless($session, 422, 'Sesi Kelas Kelompok tidak ditemukan pada paket ini.');
            abort_unless($session->ends_at?->lte(now()), 422, 'Laporan sesi baru dapat dikirim setelah jadwal belajar berakhir.');
            abort_unless(
                in_array($session->status, ['report_required', 'revision_requested'], true),
                422,
                $session->status === 'awaiting_admin_verification'
                    ? 'Laporan sesi ini sedang menunggu verifikasi admin.'
                    : ($session->status === 'completed'
                        ? 'Laporan sesi ini sudah diverifikasi dan dikunci.'
                        : 'Sesi ini belum siap menerima laporan.')
            );

            $confirmedParticipants = $class->enrollments()->where('status', 'confirmed')->count();
            $attendedCount = (int) $validated['attended_participants_count'];
            abort_if($attendedCount > $confirmedParticipants, 422, 'Jumlah murid hadir tidak boleh melebihi peserta terkonfirmasi.');

            $subjects = $cheapClasses->classSubjects($class);
            $sessionUpdates = [];
            foreach ($validated['updates'] as $update) {
                $index = (int) $update['subject_index'];
                abort_unless(array_key_exists($index, $subjects), 422, 'Bab Kelas Kelompok tidak ditemukan.');

                $status = (string) $update['progress_status'];
                $needsReview = $status === 'completed'
                    ? (bool) ($update['needs_review'] ?? false)
                    : false;
                $notes = filled($update['progress_notes'] ?? null)
                    ? trim((string) $update['progress_notes'])
                    : null;
                $beforeStatus = (string) ($subjects[$index]['progress_status'] ?? 'not_started');
                $beforeNeedsReview = (bool) ($subjects[$index]['needs_review'] ?? false);
                $beforeNotes = $subjects[$index]['progress_notes'] ?? null;

                if ($beforeStatus !== $status || $beforeNeedsReview !== $needsReview || $beforeNotes !== $notes) {
                    $sessionUpdates[] = [
                        'subject_index' => $index,
                        'subject_name' => $subjects[$index]['subject_name'] ?? 'Mata pelajaran',
                        'chapter' => $subjects[$index]['chapter'] ?? 'Bab',
                        'status_before' => $beforeStatus,
                        'status_after' => $status,
                        'needs_review_before' => $beforeNeedsReview,
                        'needs_review_after' => $needsReview,
                        'notes' => $notes,
                    ];
                }
            }

            // Progress belum menjadi progress resmi murid di tahap ini. Snapshot
            // laporan disimpan di sesi dan baru diterapkan ke class.subjects ketika
            // admin menekan Konfirmasi Sesi.
            $session->update([
                'status' => 'awaiting_admin_verification',
                'progress_updates' => $sessionUpdates,
                'progress_notes' => trim((string) $validated['session_notes']),
                'progress_recorded_at' => now(),
                'progress_recorded_by' => $request->user()->id,
                'attended_participants_count' => $attendedCount,
                'report_submitted_at' => now(),
                'report_submitted_by' => $request->user()->id,
                'admin_reviewed_at' => null,
                'admin_reviewed_by' => null,
            ]);

            return [$session->fresh(), $subjects, $cheapClasses->chapterProgressSummary($class)];
        }, 3);

        User::query()->where('role', 'admin')->pluck('id')->each(function (int $adminId) use ($cheapClass, $session) {
            Notification::updateOrCreate(
                ['unique_key' => "cheap-class-session-report:{$session->id}:admin:{$adminId}"],
                [
                    'user_id' => $adminId,
                    'title' => 'Laporan sesi Kelas Kelompok masuk',
                    'message' => "Sesi {$session->session_number} Kelas Kelompok {$cheapClass->subject_name} menunggu verifikasi.",
                    'type' => 'info',
                    'target_url' => '/admin/kelas-murah/jadwal?view=sessions&scope=active&status=awaiting_admin_verification',
                    'is_read' => false,
                ]
            );
        });

        return response()->json([
            'message' => 'Laporan sesi dikirim. Admin akan memeriksa progress dan kehadiran sebelum sesi dinyatakan selesai.',
            'subjects' => $subjects,
            'progress_summary' => $summary,
            'session' => $session,
        ]);
    }
}
