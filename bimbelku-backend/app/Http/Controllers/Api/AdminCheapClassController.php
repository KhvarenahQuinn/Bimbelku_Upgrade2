<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheapClass;
use App\Models\CheapClassSession;
use App\Models\CheapClassTemplate;
use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Models\Notification;
use App\Services\CheapClassService;
use App\Support\CheapClassSchema;
use App\Support\EducationCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCheapClassController extends Controller
{
    public function form()
    {
        $setup = CheapClassSchema::status();
        if (! $setup['ready']) {
            return response()->json([
                'setup' => $setup,
                'subjects' => [],
                'chapters' => [],
                'education_levels' => EducationCatalog::LEVELS,
                'grades_by_level' => EducationCatalog::GRADES_BY_LEVEL,
            ]);
        }

        return response()->json([
            'setup' => $setup,
            'subjects' => CurriculumSubject::query()
                ->where('is_active', true)
                ->orderBy('group_name')
                ->orderBy('name')
                ->get(['id', 'name', 'education_levels', 'grades']),
            'chapters' => CurriculumChapter::query()
                ->where('is_active', true)
                ->with('subject:id,name')
                ->orderBy('education_level')
                ->orderBy('grade')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (CurriculumChapter $chapter) => [
                    'id' => $chapter->id,
                    'curriculum_subject_id' => $chapter->curriculum_subject_id,
                    'subject_name' => $chapter->subject?->name,
                    'education_level' => $chapter->education_level,
                    'grade' => $chapter->grade,
                    'title' => $chapter->title,
                ]),
            'education_levels' => EducationCatalog::LEVELS,
            'grades_by_level' => EducationCatalog::GRADES_BY_LEVEL,
        ]);
    }

    public function index(CheapClassService $cheapClasses)
    {
        $setup = CheapClassSchema::status();
        if (! $setup['ready']) {
            return response()->json([
                'setup' => $setup,
                'classes' => [],
            ]);
        }

        $cheapClasses->refreshLifecycle();

        return response()->json([
            'setup' => $setup,
            'classes' => CheapClass::query()
                ->with(['template:id,template_code,recurrence_enabled,is_active,next_publish_at', 'teacher:id,name', 'sessions'])
                ->withCount([
                    'enrollments as participant_count' => fn ($items) => $items->whereIn('status', ['seat_held', 'payment_submitted', 'payment_rejected', 'confirmed']),
                    'enrollments as confirmed_participant_count' => fn ($items) => $items->where('status', 'confirmed'),
                    'enrollments as pending_payment_count' => fn ($items) => $items->whereIn('status', ['seat_held', 'payment_submitted', 'payment_rejected']),
                ])
                ->whereNotIn('status', ['cancelled', 'completed'])
                ->orderBy('starts_at')
                ->limit(100)
                ->get()
                ->map(fn (CheapClass $class) => $this->adminPackagePayload($class)),
        ]);
    }

    public function recurringTemplates(CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $cheapClasses->refreshLifecycle();

        $templates = CheapClassTemplate::query()
            ->where('recurrence_enabled', true)
            ->withCount([
                'classes as package_count',
                'classes as active_package_count' => fn ($classes) => $classes
                    ->whereNotIn('status', ['cancelled', 'completed']),
                'classes as history_package_count' => fn ($classes) => $classes
                    ->whereIn('status', ['cancelled', 'completed']),
            ])
            ->orderByDesc('is_active')
            ->orderByRaw('CASE WHEN next_publish_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_publish_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (CheapClassTemplate $template) => [
                ...$this->recurrencePayload($template),
                'subjects' => $this->templateSubjects($template),
                'subject_name' => $this->templateSubjectSummary($template),
                'education_level' => $template->education_level,
                'grade' => $template->grade,
                'chapter' => $this->templateChapterSummary($template),
                'session_count' => (int) $template->session_count,
                'recurrence_days' => collect($template->recurrence_days ?? [])->map(fn ($day) => (int) $day)->values(),
                'start_time' => $template->start_time,
                'price_per_student' => (float) $template->price_per_student,
                'minimum_participants' => (int) $template->minimum_participants,
                'maximum_participants' => (int) $template->maximum_participants,
                'package_count' => (int) $template->package_count,
                'active_package_count' => (int) $template->active_package_count,
                'history_package_count' => (int) $template->history_package_count,
            ])
            ->values();

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $result = $cheapClasses->createPackage($this->validatedPackage($request));
        /** @var CheapClass $class */
        $class = $result['class'];
        $teacher = $result['teacher'];

        $subjectCount = count($this->packageSubjects($class));
        $recurrenceMessage = $class->template?->recurrence_enabled
            ? ' Pengulangan mingguan aktif; setiap periode berikutnya menjadi paket baru.'
            : ' Paket ini hanya dibuat sekali.';

        return response()->json([
            'message' => $teacher
                ? "1 paket Kelas Kelompok dibuat dengan {$subjectCount} mapel dan {$class->session_count} sesi. Tutor {$teacher->name} dipilih otomatis.{$recurrenceMessage}"
                : "1 paket Kelas Kelompok dibuat dengan {$subjectCount} mapel dan {$class->session_count} sesi. Paket tersimpan dan sistem masih mencari tutor yang cocok.{$recurrenceMessage}",
            'data' => $this->adminPackagePayload($class),
        ], 201);
    }

    public function schedule(Request $request, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $cheapClasses->refreshLifecycle();
        $validated = $request->validate([
            'view' => ['nullable', Rule::in(['packages', 'sessions'])],
            'scope' => ['nullable', Rule::in(['active', 'history'])],
            'sort' => ['nullable', Rule::in(['asc', 'desc'])],
            'status' => ['nullable', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 15, 20])],
        ]);
        $view = $validated['view'] ?? 'packages';
        $status = $validated['status'] ?? null;
        $scope = $validated['scope'] ?? (in_array($status, ['cancelled', 'completed'], true) ? 'history' : 'active');
        $sort = $validated['sort'] ?? 'asc';
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);

        $classes = CheapClass::query()
            ->with([
                'template:id,template_code,recurrence_enabled,is_active,next_publish_at',
                'teacher:id,name',
                'sessions',
            ])
            ->withCount([
                'enrollments',
                'enrollments as participant_count' => fn ($items) => $items
                    ->whereIn('status', ['seat_held', 'payment_submitted', 'payment_rejected', 'confirmed']),
                'enrollments as confirmed_participant_count' => fn ($items) => $items->where('status', 'confirmed'),
                'enrollments as pending_payment_count' => fn ($items) => $items
                    ->whereIn('status', ['seat_held', 'payment_submitted', 'payment_rejected']),
            ])
            ->where(function ($query) use ($scope) {
                if ($scope === 'history') {
                    $query->whereIn('status', ['cancelled', 'completed']);

                    return;
                }

                $query->whereNotIn('status', ['cancelled', 'completed']);
            })
            ->when(($view === 'packages' ? ($validated['status'] ?? null) : null), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('starts_at')
            ->limit(500)
            ->get();

        $subjects = $classes
            ->flatMap(fn (CheapClass $class) => collect($this->packageSubjects($class))->pluck('subject_name'))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if (! empty($validated['subject'])) {
            $selectedSubject = mb_strtolower((string) $validated['subject']);
            $classes = $classes->filter(fn (CheapClass $class) => collect($this->packageSubjects($class))
                ->contains(fn ($item) => mb_strtolower((string) $item['subject_name']) === $selectedSubject))
                ->values();
        }

        $items = $view === 'sessions'
            ? $classes->flatMap(fn (CheapClass $class) => $class->sessions
                ->map(fn ($session) => [
                    'id' => $session->id,
                    'package_id' => $class->id,
                    'session_number' => $session->session_number,
                    'starts_at' => $session->starts_at,
                    'ends_at' => $session->ends_at,
                    'session_status' => $session->status,
                    'teacher_started_at' => $session->teacher_started_at,
                    'progress_updates' => $session->progress_updates ?? [],
                    'progress_notes' => $session->progress_notes,
                    'progress_recorded_at' => $session->progress_recorded_at,
                    'attended_participants_count' => $session->attended_participants_count,
                    'report_submitted_at' => $session->report_submitted_at,
                    'admin_review_notes' => $session->admin_review_notes,
                    'admin_reviewed_at' => $session->admin_reviewed_at,
                    'report_revision_count' => (int) ($session->report_revision_count ?? 0),
                    'subject_name' => $this->subjectSummary($class),
                    'subjects' => $this->packageSubjects($class),
                    'education_level' => $class->education_level,
                    'grade' => $class->grade,
                    'chapter' => $this->chapterSummary($class),
                    'package_status' => $class->status,
                    'cancellation_reason' => $class->cancellation_reason,
                    'package_session_count' => $class->session_count,
                    'teacher' => $class->teacher ? ['id' => $class->teacher->id, 'name' => $class->teacher->name] : null,
                    'participant_count' => (int) $class->participant_count,
                    'occupied_seat_count' => (int) $class->participant_count,
                    'confirmed_participant_count' => (int) $class->confirmed_participant_count,
                    'pending_payment_count' => (int) $class->pending_payment_count,
                    'maximum_participants' => $class->maximum_participants,
                ]))
                ->when(! empty($validated['status']), fn ($sessions) => $sessions->where('session_status', $validated['status'])->values())
            : $classes->map(function (CheapClass $class) use ($cheapClasses) {
                $orderedSessions = $class->sessions->sortBy('session_number')->values();
                $deletion = $cheapClasses->deletionEligibility($class);
                $cancellation = $cheapClasses->cancellationEligibility($class);

                return [
                    'id' => $class->id,
                    'package_code' => $class->package_code,
                    'template_id' => $class->cheap_class_template_id,
                    'template_code' => $class->template?->template_code,
                    'package_kind' => $class->template?->recurrence_enabled ? 'recurring' : 'one_time',
                    'recurrence_active' => (bool) ($class->template?->recurrence_enabled && $class->template?->is_active),
                    'next_publish_at' => $class->template?->next_publish_at,
                    'subject_name' => $this->subjectSummary($class),
                    'subjects' => $this->packageSubjects($class),
                    'education_level' => $class->education_level,
                    'grade' => $class->grade,
                    'chapter' => $this->chapterSummary($class),
                    'status' => $class->status,
                    'cancellation_reason' => $class->cancellation_reason,
                    'cancelled_at' => $class->cancelled_at,
                    'session_count' => $class->session_count,
                    'first_session_at' => $orderedSessions->first()?->starts_at,
                    'last_session_at' => $orderedSessions->last()?->starts_at,
                    'teacher' => $class->teacher ? ['id' => $class->teacher->id, 'name' => $class->teacher->name] : null,
                    'participant_count' => (int) $class->participant_count,
                    'occupied_seat_count' => (int) $class->participant_count,
                    'confirmed_participant_count' => (int) $class->confirmed_participant_count,
                    'pending_payment_count' => (int) $class->pending_payment_count,
                    'minimum_participants' => $class->minimum_participants,
                    'maximum_participants' => $class->maximum_participants,
                    'price_per_student' => (float) $class->price_per_student,
                    'price_per_session' => (float) $class->price_per_session,
                    'custom_price_per_student' => $class->custom_price_per_student !== null
                        ? (float) $class->custom_price_per_student
                        : null,
                    'topic' => $class->topic,
                    'registration_opens_at' => $class->registration_opens_at,
                    'registration_deadline' => $class->registration_deadline,
                    'teacher_status_message' => $this->teacherStatusMessage($class),
                    'can_delete' => $deletion['can_delete'],
                    'delete_block_reason' => $deletion['reason'],
                    'can_cancel' => $cancellation['can_cancel'],
                    'cancel_block_reason' => $cancellation['reason'],
                    'sessions' => $orderedSessions->map(fn ($session) => [
                        'id' => $session->id,
                        'session_number' => $session->session_number,
                        'starts_at' => $session->starts_at,
                        'ends_at' => $session->ends_at,
                        'status' => $session->status,
                        'teacher_started_at' => $session->teacher_started_at,
                        'progress_recorded_at' => $session->progress_recorded_at,
                        'attended_participants_count' => $session->attended_participants_count,
                        'report_submitted_at' => $session->report_submitted_at,
                        'admin_review_notes' => $session->admin_review_notes,
                        'admin_reviewed_at' => $session->admin_reviewed_at,
                        'report_revision_count' => (int) ($session->report_revision_count ?? 0),
                    ])->all(),
                ];
            });

        $dateKey = $view === 'sessions' ? 'starts_at' : 'first_session_at';
        $items = ($sort === 'desc' ? $items->sortByDesc($dateKey) : $items->sortBy($dateKey))->values();
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return response()->json([
            'data' => $items->forPage($page, $perPage)->values(),
            'meta' => [
                'view' => $view,
                'scope' => $scope,
                'sort' => $sort,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
            'filters' => ['subjects' => $subjects],
        ]);
    }

    public function verifySessionReport(Request $request, CheapClass $cheapClass, CheapClassSession $session, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        abort_unless((int) $session->cheap_class_id === (int) $cheapClass->id, 404);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($request, $cheapClass, $session, $cheapClasses, $data) {
            $class = CheapClass::query()->lockForUpdate()->findOrFail($cheapClass->id);
            $lockedSession = CheapClassSession::query()
                ->whereKey($session->id)
                ->where('cheap_class_id', $class->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($class->status === 'confirmed', 422, 'Paket Kelas Kelompok ini sudah tidak menerima verifikasi sesi baru.');
            abort_unless($lockedSession->status === 'awaiting_admin_verification', 422, 'Laporan sesi ini tidak sedang menunggu verifikasi admin.');
            abort_unless($lockedSession->report_submitted_at && $lockedSession->progress_recorded_at, 422, 'Laporan tutor belum lengkap.');
            abort_if(
                CheapClassSession::query()
                    ->where('cheap_class_id', $class->id)
                    ->where('session_number', '<', $lockedSession->session_number)
                    ->where('status', '!=', 'completed')
                    ->exists(),
                422,
                'Verifikasi sesi sebelumnya terlebih dahulu agar riwayat progress tetap berurutan.'
            );

            $subjects = $cheapClasses->classSubjects($class);
            $verifiedUpdates = [];
            foreach ($lockedSession->progress_updates ?? [] as $change) {
                $index = (int) ($change['subject_index'] ?? -1);
                abort_unless(array_key_exists($index, $subjects), 422, 'Bab pada laporan sudah tidak cocok dengan paket Kelas Kelompok.');

                $beforeStatus = (string) ($subjects[$index]['progress_status'] ?? 'not_started');
                $beforeNeedsReview = (bool) ($subjects[$index]['needs_review'] ?? false);
                $afterStatus = (string) ($change['status_after'] ?? $beforeStatus);
                $afterNeedsReview = $afterStatus === 'completed' ? (bool) ($change['needs_review_after'] ?? false) : false;
                $notes = filled($change['notes'] ?? null) ? trim((string) $change['notes']) : null;

                $verifiedUpdates[] = [
                    ...$change,
                    'status_before' => $beforeStatus,
                    'status_after' => $afterStatus,
                    'needs_review_before' => $beforeNeedsReview,
                    'needs_review_after' => $afterNeedsReview,
                    'notes' => $notes,
                ];

                $subjects[$index]['progress_status'] = $afterStatus;
                $subjects[$index]['needs_review'] = $afterNeedsReview;
                $subjects[$index]['progress_notes'] = $notes;
                $subjects[$index]['progress_updated_at'] = now()->toISOString();
            }

            $class->update(['subjects' => $subjects]);
            $lockedSession->update([
                'status' => 'completed',
                'progress_updates' => $verifiedUpdates,
                'admin_review_notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : $lockedSession->admin_review_notes,
                'admin_reviewed_at' => now(),
                'admin_reviewed_by' => $request->user()->id,
            ]);

            return [
                'class_id' => $class->id,
                'subject_name' => $cheapClasses->subjectSummary($class),
                'session_number' => (int) $lockedSession->session_number,
                'teacher_id' => (int) $class->teacher_id,
                'student_ids' => $class->enrollments()->where('status', 'confirmed')->pluck('student_id')->map(fn ($id) => (int) $id)->all(),
            ];
        }, 3);

        $cheapClasses->refreshLifecycle(false);
        $freshClass = CheapClass::query()->findOrFail($result['class_id']);

        if ($result['teacher_id']) {
            Notification::updateOrCreate(
                ['unique_key' => "cheap-class-session-verified:{$session->id}:teacher:{$result['teacher_id']}"],
                [
                    'user_id' => $result['teacher_id'],
                    'title' => $freshClass->status === 'completed' ? 'Kelas Kelompok selesai' : 'Laporan sesi diverifikasi',
                    'message' => $freshClass->status === 'completed'
                        ? "Seluruh sesi {$result['subject_name']} sudah terverifikasi admin. Kelas selesai dan masuk Riwayat."
                        : "Sesi {$result['session_number']} {$result['subject_name']} sudah dikonfirmasi admin.",
                    'type' => 'success',
                    'target_url' => '/guru/kelas-murah',
                    'is_read' => false,
                ]
            );
        }
        foreach ($result['student_ids'] as $studentId) {
            Notification::updateOrCreate(
                ['unique_key' => "cheap-class-session-verified:{$session->id}:student:{$studentId}"],
                [
                    'user_id' => $studentId,
                    'title' => $freshClass->status === 'completed' ? 'Kelas Kelompok selesai' : 'Progress Kelas Kelompok diperbarui',
                    'message' => $freshClass->status === 'completed'
                        ? "Seluruh pertemuan {$result['subject_name']} sudah diverifikasi. Kelas selesai; progress akhir tetap bisa kamu lihat di Riwayat."
                        : "Pertemuan {$result['session_number']} {$result['subject_name']} sudah diverifikasi. Lihat progress belajar terbarumu.",
                    'type' => 'success',
                    'target_url' => "/student/progress/cheap-class/{$result['class_id']}",
                    'is_read' => false,
                ]
            );
        }

        return response()->json([
            'message' => $freshClass->status === 'completed'
                ? 'Sesi dikonfirmasi. Seluruh pertemuan sudah terverifikasi dan paket Kelas Kelompok selesai.'
                : 'Sesi dikonfirmasi. Progress resmi murid sudah diperbarui.',
            'class_status' => $freshClass->status,
        ]);
    }

    public function requestSessionReportRevision(Request $request, CheapClass $cheapClass, CheapClassSession $session)
    {
        CheapClassSchema::ensureReady();
        abort_unless((int) $session->cheap_class_id === (int) $cheapClass->id, 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($request, $cheapClass, $session, $data) {
            $class = CheapClass::query()->lockForUpdate()->findOrFail($cheapClass->id);
            $lockedSession = CheapClassSession::query()
                ->whereKey($session->id)
                ->where('cheap_class_id', $class->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedSession->status === 'awaiting_admin_verification', 422, 'Laporan sesi ini tidak sedang menunggu verifikasi admin.');

            $lockedSession->update([
                'status' => 'revision_requested',
                'admin_review_notes' => trim((string) $data['reason']),
                'admin_reviewed_at' => now(),
                'admin_reviewed_by' => $request->user()->id,
                'report_revision_count' => (int) $lockedSession->report_revision_count + 1,
            ]);

            return [
                'teacher_id' => (int) $class->teacher_id,
                'subject_name' => (string) $class->subject_name,
                'session_number' => (int) $lockedSession->session_number,
            ];
        }, 3);

        if ($result['teacher_id']) {
            Notification::updateOrCreate(
                ['unique_key' => "cheap-class-session-revision:{$session->id}:teacher:{$result['teacher_id']}"],
                [
                    'user_id' => $result['teacher_id'],
                    'title' => 'Laporan sesi perlu diperbaiki',
                    'message' => "Sesi {$result['session_number']} {$result['subject_name']}: ".trim((string) $data['reason']),
                    'type' => 'warning',
                    'target_url' => '/guru/kelas-murah',
                    'is_read' => false,
                ]
            );
        }

        return response()->json(['message' => 'Catatan perbaikan dikirim ke tutor. Sesi tetap belum final sampai laporan dikirim ulang dan diverifikasi.']);
    }

    public function cancel(Request $request, CheapClass $cheapClass, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $eligibility = $cheapClasses->cancellationEligibility($cheapClass);
        abort_unless($eligibility['can_cancel'], 422, (string) $eligibility['reason']);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Paket dibatalkan admin.';
        }

        $cheapClasses->cancelClass($cheapClass, $reason);

        return response()->json([
            'message' => 'Paket Kelas Kelompok berhasil dibatalkan.',
            'data' => $this->adminPackagePayload($cheapClass->fresh(['teacher:id,name', 'sessions'])),
        ]);
    }

    public function destroy(CheapClass $cheapClass, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $result = $cheapClasses->deleteEmptyPackage($cheapClass);

        return response()->json([
            'message' => "Paket dan {$result['removed_sessions']} sesi berhasil dihapus permanen.",
            ...$result,
        ]);
    }

    public function retryTeacher(CheapClass $cheapClass, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        abort_if(in_array($cheapClass->status, ['confirmed', 'cancelled'], true), 422, 'Status paket tidak menerima pencarian tutor baru.');
        abort_unless($cheapClass->sessions()->where('ends_at', '>', now())->exists(), 422, 'Seluruh jadwal paket sudah berakhir.');

        $teacher = $cheapClasses->replaceTeacherIfNeeded($cheapClass);
        $class = $cheapClass->fresh(['teacher:id,name', 'sessions']);

        return response()->json([
            'message' => $teacher
                ? "Tutor {$teacher->name} berhasil dipilih untuk seluruh sesi."
                : 'Tutor belum ditemukan. Sistem hanya memilih guru aktif yang cocok dan tersedia pada seluruh sesi.',
            'data' => $this->adminPackagePayload($class),
        ]);
    }

    public function finalize(CheapClass $cheapClass, CheapClassService $cheapClasses)
    {
        CheapClassSchema::ensureReady();
        $cheapClasses->finalizeIfReady($cheapClass);

        return response()->json(['message' => 'Status Kelas Kelompok diperiksa ulang.', 'data' => $cheapClass->fresh()]);
    }

    public function updateRecurrence(
        Request $request,
        CheapClassTemplate $cheapClassTemplate,
        CheapClassService $cheapClasses
    ) {
        CheapClassSchema::ensureReady();
        $data = $request->validate([
            'active' => ['required', 'boolean'],
        ]);
        $template = $cheapClasses->setRecurrenceActive($cheapClassTemplate, (bool) $data['active']);

        return response()->json([
            'message' => $template->is_active
                ? 'Pengulangan mingguan diaktifkan. Minggu yang terlewat tidak dibuat ulang.'
                : 'Pengulangan mingguan dinonaktifkan. Paket yang sudah terbit tetap berjalan.',
            'data' => $this->recurrencePayload($template),
        ]);
    }

    private function validatedPackage(Request $request): array
    {
        $request->merge([
            'subject_name' => $request->filled('subject_name') ? trim((string) $request->input('subject_name')) : null,
            'topic' => $request->filled('topic') ? trim((string) $request->input('topic')) : null,
        ]);
        $data = $request->validate([
            // subjects mendukung 1–3 mapel per paket. Dua field legacy
            // tetap diterima agar request/test lama satu-mapel tidak langsung rusak.
            'subjects' => ['nullable', 'array', 'min:1', 'max:3'],
            'subjects.*.subject_name' => ['required_with:subjects', 'string', 'max:120'],
            'subjects.*.curriculum_chapter_id' => ['required_with:subjects', 'integer', 'exists:curriculum_chapters,id'],
            'subject_name' => ['nullable', 'required_without:subjects', 'string', 'max:120'],
            'curriculum_chapter_id' => ['nullable', 'required_without:subjects', 'integer', 'exists:curriculum_chapters,id'],
            'education_level' => ['required', Rule::in(EducationCatalog::LEVELS)],
            'grade' => ['required', 'string', 'max:50'],
            'topic' => ['nullable', 'string', 'max:1500'],
            // Request UI baru memakai tanggal/jam pembukaan. first_session_date
            // tetap diterima untuk kompatibilitas test dan klien lama.
            'registration_open_date' => ['nullable', 'date', 'after_or_equal:today'],
            'registration_open_time' => ['nullable', 'date_format:H:i'],
            'first_session_date' => ['nullable', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'in:60'],
            'session_count' => ['required', 'integer', Rule::in([1, 4, 8, 12])],
            'weekdays' => ['required', 'array', 'min:1', 'max:4'],
            'weekdays.*' => ['required', 'integer', 'distinct', 'between:1,7'],
            'price_per_session' => ['required', 'integer', 'min:1000', 'max:10000000'],
            'use_custom_price' => ['required', 'boolean'],
            'custom_price_per_student' => ['nullable', 'required_if:use_custom_price,true', 'integer', 'min:1000', 'max:10000000'],
            'minimum_participants' => ['required', 'integer', 'min:2', 'max:12'],
            'maximum_participants' => ['required', 'integer', 'min:2', 'max:12', 'gte:minimum_participants'],
            'registration_window_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'registration_closes_before_minutes' => ['required', 'integer', 'min:30', 'max:10080'],
            'payment_window_minutes' => ['required', 'integer', 'in:60'],
            'recurrence_enabled' => ['sometimes', 'boolean'],
        ]);

        abort_unless(EducationCatalog::supports($data['education_level'], $data['grade']), 422, 'Kelas tidak sesuai dengan jenjang.');
        abort_if(substr($data['start_time'], 3, 2) !== '00', 422, 'Jam mulai hanya boleh menggunakan menit 00.');
        abort_if($data['start_time'] >= '23:00', 422, 'Sesi harus selesai pada hari yang sama. Pilih jam mulai paling lambat 22.00.');
        $hasRegistrationDate = ! empty($data['registration_open_date']);
        $hasRegistrationTime = ! empty($data['registration_open_time']);
        abort_if($hasRegistrationDate !== $hasRegistrationTime, 422, 'Tanggal dan jam pembukaan pendaftaran harus diisi bersama.');
        abort_if(! $hasRegistrationDate && empty($data['first_session_date']), 422, 'Tanggal pembukaan pendaftaran wajib diisi.');
        if ($hasRegistrationTime) {
            abort_if(substr((string) $data['registration_open_time'], 3, 2) !== '00', 422, 'Jam pembukaan hanya boleh menggunakan menit 00.');
            abort_if((int) $data['registration_window_hours'] !== 24, 422, 'Pendaftaran paket baru berlangsung tetap selama 24 jam.');
        }

        $maximumWeekdays = min(4, (int) $data['session_count']);
        abort_if(
            count($data['weekdays']) > $maximumWeekdays,
            422,
            (int) $data['session_count'] === 1
                ? 'Paket 1 sesi hanya boleh memilih 1 hari belajar.'
                : "Paket {$data['session_count']} sesi maksimal memakai {$maximumWeekdays} hari belajar."
        );

        $requestedSubjects = $data['subjects'] ?? [[
            'subject_name' => $data['subject_name'],
            'curriculum_chapter_id' => $data['curriculum_chapter_id'],
        ]];

        $maximumSubjects = match ((int) $data['session_count']) {
            12 => 3,
            8 => 2,
            default => 1,
        };
        abort_if(
            count($requestedSubjects) > $maximumSubjects,
            422,
            "Paket {$data['session_count']} sesi maksimal memiliki {$maximumSubjects} mata pelajaran."
        );

        $normalizedSubjects = [];
        $seenSubjectIds = [];
        foreach ($requestedSubjects as $requestedSubject) {
            $subjectName = trim((string) ($requestedSubject['subject_name'] ?? ''));
            $subject = CurriculumSubject::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($subjectName)])
                ->first();
            abort_if(
                ! $subject
                    || ! in_array($data['education_level'], $subject->education_levels ?? [], true)
                    || ! in_array($data['grade'], $subject->grades ?? [], true),
                422,
                "Mata pelajaran {$subjectName} belum tersedia untuk jenjang dan kelas ini."
            );
            abort_if(in_array((int) $subject->id, $seenSubjectIds, true), 422, 'Mata pelajaran dalam satu paket tidak boleh duplikat.');

            $chapter = CurriculumChapter::query()
                ->whereKey((int) $requestedSubject['curriculum_chapter_id'])
                ->where('curriculum_subject_id', $subject->id)
                ->where('education_level', $data['education_level'])
                ->where('grade', $data['grade'])
                ->where('is_active', true)
                ->first();
            abort_unless($chapter, 422, "Bab untuk {$subject->name} tidak sesuai dengan jenjang atau kelas yang dipilih.");

            $seenSubjectIds[] = (int) $subject->id;
            $normalizedSubjects[] = [
                'curriculum_subject_id' => (int) $subject->id,
                'subject_name' => $subject->name,
                'curriculum_chapter_id' => (int) $chapter->id,
                'chapter' => $chapter->title,
            ];
        }

        abort_if(count($normalizedSubjects) < 1 || count($normalizedSubjects) > 3, 422, 'Pilih minimal 1 dan maksimal 3 mata pelajaran.');
        $primary = $normalizedSubjects[0];
        $data['subjects'] = $normalizedSubjects;
        // Kolom legacy menyimpan mapel pertama untuk kompatibilitas data lama.
        $data['subject_name'] = $primary['subject_name'];
        $data['curriculum_subject_id'] = $primary['curriculum_subject_id'];
        $data['curriculum_chapter_id'] = $primary['curriculum_chapter_id'];
        $data['chapter'] = $primary['chapter'];

        $data['recurrence_days'] = collect($data['weekdays'])
            ->map(fn ($day) => (int) $day)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($hasRegistrationDate) {
            $registrationOpensAt = Carbon::parse(
                $data['registration_open_date'].' '.$data['registration_open_time'],
                config('app.timezone', 'Asia/Jakarta')
            );
            abort_if($registrationOpensAt->lte(now()), 422, 'Pilih waktu pembukaan pendaftaran yang belum lewat.');
            $firstAllowedSessionAt = $registrationOpensAt->copy()
                ->addHours((int) $data['registration_window_hours'])
                ->addMinutes((int) $data['registration_closes_before_minutes']);
            $start = $this->nextLearningSlot(
                $firstAllowedSessionAt,
                $data['recurrence_days'],
                $data['start_time']
            );
            $data['first_session_date'] = $start->toDateString();
            $data['registration_opens_at'] = $registrationOpensAt->toDateTimeString();
        } else {
            $start = Carbon::parse(
                $data['first_session_date'].' '.$data['start_time'],
                config('app.timezone', 'Asia/Jakarta')
            );
            abort_if($start->lte(now()), 422, 'Pilih jam mulai yang belum lewat.');
            abort_unless(
                in_array($start->dayOfWeekIso, $data['recurrence_days'], true),
                422,
                'Tanggal sesi pertama harus sesuai dengan salah satu hari belajar yang dipilih.'
            );
            abort_if(
                $start->copy()->subMinutes((int) $data['registration_closes_before_minutes'])->lte(now()),
                422,
                'Jadwal terlalu dekat. Sisakan waktu sesuai batas penutupan pendaftaran sebelum kelas dimulai.'
            );
        }
        $useCustomPrice = (bool) $data['use_custom_price'];
        $data['custom_price_per_student'] = $useCustomPrice
            ? (int) $data['custom_price_per_student']
            : null;
        $data['price_per_student'] = $data['custom_price_per_student']
            ?? ((int) $data['price_per_session'] * (int) $data['session_count']);
        $data['teacher_id'] = null;
        $data['recurrence_enabled'] = (bool) ($data['recurrence_enabled'] ?? false);
        $data['is_active'] = $data['recurrence_enabled'];
        unset(
            $data['use_custom_price'],
            $data['weekdays'],
            $data['registration_open_date'],
            $data['registration_open_time']
        );

        return $data;
    }

    /** Menentukan sesi pertama pada hari pilihan terdekat setelah pendaftaran selesai. */
    private function nextLearningSlot(Carbon $minimum, array $weekdays, string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $cursor = $minimum->copy()->startOfDay();

        foreach (range(0, 7) as $offset) {
            $candidate = $cursor->copy()->addDays($offset)->setTime($hour, $minute);
            if (in_array($candidate->dayOfWeekIso, $weekdays, true) && $candidate->gte($minimum)) {
                return $candidate;
            }
        }

        abort(422, 'Sesi pertama tidak dapat dihitung dari hari belajar yang dipilih.');
    }

    private function adminPackagePayload(CheapClass $class): array
    {
        $class->loadMissing(['template:id,template_code,recurrence_enabled,is_active,next_publish_at', 'teacher:id,name', 'sessions']);

        return [
            'id' => $class->id,
            'package_code' => $class->package_code,
            'template_id' => $class->cheap_class_template_id,
            'template_code' => $class->template?->template_code,
            'package_kind' => $class->template?->recurrence_enabled ? 'recurring' : 'one_time',
            'recurrence_active' => (bool) ($class->template?->recurrence_enabled && $class->template?->is_active),
            'next_publish_at' => $class->template?->next_publish_at,
            'occurrence_week_start' => $class->occurrence_week_start,
            'generation_source' => $class->generation_source,
            'subject_name' => $this->subjectSummary($class),
            'subjects' => $this->packageSubjects($class),
            'education_level' => $class->education_level,
            'grade' => $class->grade,
            'chapter' => $this->chapterSummary($class),
            'topic' => $class->topic,
            'starts_at' => $class->starts_at,
            'ends_at' => $class->ends_at,
            'status' => $class->status,
            'session_count' => (int) $class->session_count,
            'sessions' => $class->sessions->values(),
            'price_per_student' => (float) $class->price_per_student,
            'price_per_session' => (float) $class->price_per_session,
            'custom_price_per_student' => $class->custom_price_per_student !== null
                ? (float) $class->custom_price_per_student
                : null,
            'participant_count' => (int) ($class->participant_count ?? 0),
            'occupied_seat_count' => (int) ($class->participant_count ?? 0),
            'confirmed_participant_count' => (int) ($class->confirmed_participant_count ?? 0),
            'pending_payment_count' => (int) ($class->pending_payment_count ?? 0),
            'minimum_participants' => (int) $class->minimum_participants,
            'maximum_participants' => (int) $class->maximum_participants,
            'registration_opens_at' => $class->registration_opens_at,
            'registration_deadline' => $class->registration_deadline,
            'teacher' => $class->teacher ? ['id' => $class->teacher->id, 'name' => $class->teacher->name] : null,
            'teacher_status_message' => $this->teacherStatusMessage($class),
        ];
    }

    private function recurrencePayload(CheapClassTemplate $template): array
    {
        return [
            'id' => $template->id,
            'template_code' => $template->template_code,
            'recurrence_enabled' => (bool) $template->recurrence_enabled,
            'active' => (bool) $template->is_active,
            'recurrence_anchor_at' => $template->recurrence_anchor_at,
            'next_publish_at' => $template->next_publish_at,
            'last_published_at' => $template->last_published_at,
            'last_skipped_at' => $template->last_skipped_at,
            'last_generation_failed_at' => $template->last_generation_failed_at,
            'last_generation_error' => $template->last_generation_error,
        ];
    }

    /** @return array<int, array{curriculum_subject_id:?int,subject_name:string,curriculum_chapter_id:?int,chapter:string}> */
    private function templateSubjects(CheapClassTemplate $template): array
    {
        $items = is_array($template->subjects) ? $template->subjects : [];
        $normalized = collect($items)
            ->filter(fn ($item) => is_array($item) && ! empty($item['subject_name']))
            ->take(4)
            ->map(fn ($item) => [
                'curriculum_subject_id' => isset($item['curriculum_subject_id']) ? (int) $item['curriculum_subject_id'] : null,
                'subject_name' => trim((string) $item['subject_name']),
                'curriculum_chapter_id' => isset($item['curriculum_chapter_id']) ? (int) $item['curriculum_chapter_id'] : null,
                'chapter' => trim((string) ($item['chapter'] ?? '')),
            ])->values()->all();

        return $normalized !== [] ? $normalized : [[
            'curriculum_subject_id' => $template->curriculum_subject_id ? (int) $template->curriculum_subject_id : null,
            'subject_name' => (string) $template->subject_name,
            'curriculum_chapter_id' => $template->curriculum_chapter_id ? (int) $template->curriculum_chapter_id : null,
            'chapter' => (string) $template->chapter,
        ]];
    }

    private function templateSubjectSummary(CheapClassTemplate $template): string
    {
        return collect($this->templateSubjects($template))->pluck('subject_name')->filter()->join(' + ');
    }

    private function templateChapterSummary(CheapClassTemplate $template): string
    {
        return collect($this->templateSubjects($template))
            ->map(fn ($item) => $item['chapter'] !== '' ? $item['subject_name'].': '.$item['chapter'] : $item['subject_name'])
            ->join(' · ');
    }

    /** @return array<int, array{curriculum_subject_id:?int,subject_name:string,curriculum_chapter_id:?int,chapter:string}> */
    private function packageSubjects(CheapClass $class): array
    {
        $items = is_array($class->subjects) ? $class->subjects : [];
        $normalized = collect($items)
            ->filter(fn ($item) => is_array($item) && ! empty($item['subject_name']))
            ->take(4)
            ->map(fn ($item) => [
                'curriculum_subject_id' => isset($item['curriculum_subject_id']) ? (int) $item['curriculum_subject_id'] : null,
                'subject_name' => trim((string) $item['subject_name']),
                'curriculum_chapter_id' => isset($item['curriculum_chapter_id']) ? (int) $item['curriculum_chapter_id'] : null,
                'chapter' => trim((string) ($item['chapter'] ?? '')),
            ])->values()->all();

        return $normalized !== [] ? $normalized : [[
            'curriculum_subject_id' => $class->curriculum_subject_id ? (int) $class->curriculum_subject_id : null,
            'subject_name' => (string) $class->subject_name,
            'curriculum_chapter_id' => $class->curriculum_chapter_id ? (int) $class->curriculum_chapter_id : null,
            'chapter' => (string) $class->chapter,
        ]];
    }

    private function subjectSummary(CheapClass $class): string
    {
        return collect($this->packageSubjects($class))->pluck('subject_name')->filter()->join(' + ');
    }

    private function chapterSummary(CheapClass $class): string
    {
        return collect($this->packageSubjects($class))
            ->map(fn ($item) => $item['chapter'] !== '' ? $item['subject_name'].': '.$item['chapter'] : $item['subject_name'])
            ->join(' · ');
    }

    private function teacherStatusMessage(CheapClass $class): string
    {
        if ($class->teacher) {
            return "Tutor {$class->teacher->name} sudah dipilih otomatis untuk seluruh sesi.";
        }

        return 'Tutor belum ditemukan. Sistem mencoba lagi otomatis setiap menit dan saat jadwal tutor berubah. Guru harus aktif, terverifikasi, mampu mengajar seluruh mapel paket pada jenjang ini, menerima kelas online, serta tersedia pada seluruh sesi.';
    }
}
