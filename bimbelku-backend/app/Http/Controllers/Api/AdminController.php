<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingDispute;
use App\Models\BookingRequest;
use App\Models\CheapClass;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\SessionReport;
use App\Models\Setting;
use App\Models\SocialMedia;
use App\Models\TeacherPayoutRequest;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\CheapClassService;
use App\Services\PackageCheckoutService;
use App\Services\PaymentReconciliationService;
use App\Services\TeacherMatchingService;
use App\Services\TeacherOfferReleaseService;
use App\Services\XenditMoneyMovementService;
use App\Support\AdminPermissionCatalog;
use App\Support\PublicMedia;
use App\Support\XenditPayoutChannelCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    // =========================================================================
    // 1. MANAJEMEN GURU (VERIFIKASI & HISTORY)
    // =========================================================================

    public function getPendingTeachers()
    {
        $teachers = User::where('role', 'teacher')
            ->where('status', 'pending')
            ->with(['teacherProfile', 'subjects'])
            ->orderBy('created_at', 'desc')
            ->limit(300)
            ->get();

        return response()->json($teachers->map(fn (User $teacher) => $this->formatTeacherVerification($teacher)));
    }

    public function getHistoryTeachers()
    {
        $teachers = User::where('role', 'teacher')
            ->whereIn('status', ['active', 'rejected', 'banned']) // Tambahkan banned jika ada
            ->with(['teacherProfile', 'subjects'])
            ->orderBy('updated_at', 'desc')
            ->limit(500)
            ->get();

        return response()->json($teachers->map(fn (User $teacher) => $this->formatTeacherVerification($teacher)));
    }

    public function updateTeacherVerificationStage(Request $request, User $teacher)
    {
        abort_unless($teacher->role === 'teacher' && $teacher->status === 'pending', 422, 'Tahap hanya dapat diubah untuk kandidat tutor yang masih menunggu.');
        $validated = $request->validate([
            'stage' => ['required', Rule::in(['documents_checked', 'whatsapp_scheduled', 'whatsapp_passed'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $teacher, $validated) {
            $profile = TeacherProfile::query()->where('user_id', $teacher->id)->lockForUpdate()->firstOrFail();
            $teacher->load('subjects');
            $current = $profile->verification_stage ?: 'submitted';
            $transitions = [
                'submitted' => 'documents_checked',
                'documents_checked' => 'whatsapp_scheduled',
                'whatsapp_scheduled' => 'whatsapp_passed',
            ];
            abort_unless(($transitions[$current] ?? null) === $validated['stage'], 422, 'Tahap seleksi harus diselesaikan secara berurutan.');

            if ($validated['stage'] === 'documents_checked') {
                $requiredDocuments = [$profile->identity_document, $profile->live_selfie, $profile->qualification_document];
                $subject = $teacher->subjects->first();
                $documentsIncomplete = collect($requiredDocuments)->contains(fn ($path) => blank($path) || ! Storage::disk('local')->exists($path));
                $subjectIncomplete = $teacher->subjects->count() !== 1 || ! $subject?->is_active
                    || ! is_array($subject?->levels) || count($subject->levels) === 0
                    || (! $subject->is_online && ! $subject->is_offline);
                abort_if($documentsIncomplete || $subjectIncomplete, 422, 'Dokumen wajib, satu mapel aktif, jenjang, dan mode mengajar harus lengkap.');
            }

            if ($validated['stage'] === 'whatsapp_passed') {
                abort_if(mb_strlen(trim((string) ($validated['notes'] ?? ''))) < 10, 422, 'Ringkasan hasil tes WhatsApp minimal 10 karakter.');
            }

            $updates = ['verification_stage' => $validated['stage'], 'verified_by' => $request->user()->id];
            if ($validated['stage'] === 'documents_checked') {
                $updates['documents_checked_at'] = now();
            }
            if ($validated['stage'] === 'whatsapp_scheduled') {
                $updates['whatsapp_test_scheduled_at'] = now();
            }
            if ($validated['stage'] === 'whatsapp_passed') {
                $updates['whatsapp_test_passed_at'] = now();
                $updates['verification_notes'] = trim((string) $validated['notes']);
            }
            $profile->update($updates);
        }, 3);

        return response()->json(['message' => 'Tahap verifikasi tutor diperbarui.']);
    }

    public function verifyTeacher(
        Request $request,
        TeacherOfferReleaseService $offerReleaseService,
        TeacherMatchingService $matchingService
    ) {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'status' => 'required|in:active,rejected',
            'notes' => 'nullable|string|max:2000',
            'screening_passed' => 'nullable|boolean',
        ]);

        if ($validated['status'] === 'active') {
            $stage = TeacherProfile::query()->where('user_id', $validated['user_id'])->value('verification_stage');
            abort_unless($stage === 'whatsapp_passed', 422, 'Selesaikan pemeriksaan dokumen dan tes WhatsApp secara berurutan sebelum mengaktifkan tutor.');
        }
        if (
            $validated['status'] === 'active'
            && (
                ! $request->boolean('screening_passed')
                || mb_strlen(trim((string) ($validated['notes'] ?? ''))) < 10
            )
        ) {
            return response()->json([
                'message' => 'Kelulusan seleksi dan ringkasan hasil tes WhatsApp wajib dicatat sebelum tutor diaktifkan.',
            ], 422);
        }

        if (
            $validated['status'] === 'rejected'
            && mb_strlen(trim((string) ($validated['notes'] ?? ''))) < 10
        ) {
            return response()->json(['message' => 'Alasan penolakan wajib diisi dengan jelas.'], 422);
        }

        DB::transaction(function () use ($request, $validated) {
            $user = User::query()
                ->where('role', 'teacher')
                ->lockForUpdate()
                ->findOrFail($validated['user_id']);
            $profile = TeacherProfile::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $profile) {
                abort(422, 'Profil tutor tidak ditemukan.');
            }

            $user->load('subjects');
            if ($validated['status'] === 'active') {
                $requiredDocuments = [
                    $profile->identity_document,
                    $profile->live_selfie,
                    $profile->qualification_document,
                ];
                $subject = $user->subjects->first();
                $documentsIncomplete = collect($requiredDocuments)->contains(
                    fn ($path) => blank($path) || ! Storage::disk('local')->exists($path)
                );
                $subjectIncomplete = $user->subjects->count() !== 1
                    || ! $subject?->is_active
                    || ! is_array($subject?->levels)
                    || count($subject->levels) === 0
                    || (! $subject->is_online && ! $subject->is_offline);
                if ($documentsIncomplete || $subjectIncomplete) {
                    abort(
                        422,
                        'Dokumen yang dapat dibuka, satu mata pelajaran aktif, jenjang, mode mengajar wajib terpenuhi.'
                    );
                }
            }

            $alreadyApplied = $user->status === $validated['status']
                && (
                    $validated['status'] !== 'active'
                    || $profile->verified_at !== null
                );
            if ($alreadyApplied) {
                abort(422, 'Keputusan verifikasi ini sudah tersimpan.');
            }

            $user->update(['status' => $validated['status']]);
            $profile->update([
                'verification_notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
                'verified_at' => $validated['status'] === 'active' ? now() : null,
                'verified_by' => $request->user()->id,
                'is_accepting_requests' => $validated['status'] === 'active',
                'verification_stage' => $validated['status'] === 'active' ? 'approved' : 'rejected',
            ]);

            if ($validated['status'] === 'rejected') {
                $user->tokens()->delete();
            }

            Notification::create([
                'user_id' => $user->id,
                'title' => $validated['status'] === 'active' ? 'Akun tutor disetujui' : 'Verifikasi tutor ditolak',
                'message' => $validated['status'] === 'active'
                    ? 'Akun Anda telah diverifikasi dan dapat menerima permintaan belajar.'
                    : 'Verifikasi belum disetujui. Alasan: '.trim((string) ($validated['notes'] ?? '')),
                'type' => $validated['status'] === 'active' ? 'success' : 'warning',
                'target_url' => '/guru/saya',
            ]);
        }, 3);

        if ($validated['status'] === 'rejected') {
            $offerReleaseService
                ->releaseForTeacher(
                    (int) $validated['user_id'],
                    'Verifikasi tutor ditolak admin'
                )
                ->each(fn ($bookingRequest) => $matchingService->dispatchNextOffer($bookingRequest));
        }

        return response()->json(['message' => 'Status tutor berhasil diperbarui.']);
    }

    private function formatTeacherVerification(User $teacher): array
    {
        $profile = $teacher->teacherProfile;
        $documentUrl = static fn (?string $path, string $field) => $path
            ? "/teachers/{$teacher->id}/documents/{$field}"
            : null;

        return [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'email' => $teacher->email,
            'status' => $teacher->status,
            'created_at' => $teacher->created_at,
            'updated_at' => $teacher->updated_at,
            'subject' => $teacher->subjects->first()?->name,
            'levels' => $teacher->subjects->first()?->levels ?? [],
            'teaching_method' => $profile?->teaching_method,
            'about' => $profile?->bio,
            'phone' => $profile?->phone ?? $profile?->whatsapp_number,
            'verification_notes' => $profile?->verification_notes,
            'verification_stage' => $profile?->verification_stage ?? 'submitted',
            'photo_url' => PublicMedia::url($profile?->photo),
            'identity_document_url' => $documentUrl($profile?->identity_document, 'identity_document'),
            'live_selfie_url' => $documentUrl($profile?->live_selfie, 'live_selfie'),
            'qualification_document_url' => $documentUrl($profile?->qualification_document, 'qualification_document'),
            'certification_document_url' => $documentUrl($profile?->certification_document, 'certification_document'),
        ];
    }

    // =========================================================================
    // 2. MANAJEMEN USER (SISWA / GURU)
    // =========================================================================

    public function getUsers(Request $request)
    {
        $validated = $request->validate([
            'role' => 'nullable|in:student,teacher',
            'subject' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);
        $role = $validated['role'] ?? 'student';
        $subjectFilter = trim((string) ($validated['subject'] ?? ''));
        $search = trim((string) ($validated['q'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = User::query()
            ->where('role', $role)
            ->where('status', '!=', 'pending')
            ->with(['teacherProfile', 'subjects'])
            ->when($subjectFilter !== '' && $role === 'teacher', fn ($builder) => $builder->whereHas('subjects', fn ($subjects) => $subjects->where('name', $subjectFilter)->where('is_active', true)
            )
            )
            ->when($search !== '', function ($builder) use ($search, $role) {
                $builder->where(function ($searchQuery) use ($search, $role) {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                    if ($role === 'teacher') {
                        $searchQuery->orWhereHas('subjects', fn ($subjects) => $subjects->where('name', 'like', "%{$search}%")->where('is_active', true)
                        );
                    }
                });
            })
            ->orderBy('created_at', 'desc');

        $users = $query->paginate($perPage);
        $users->getCollection()->transform(function (User $u) {
            if ($u->role === 'student') {
                $u->setAttribute('student_birth_date', $u->date_of_birth?->toDateString());
                $u->setAttribute('guardian', $u->guardian_consent_at ? [
                    'name' => $u->guardian_name,
                    'phone' => $u->guardian_phone,
                    'relationship' => $u->guardian_relationship,
                    'consent_at' => $u->guardian_consent_at?->toIso8601String(),
                ] : null);
            }
            if ($u->role === 'teacher') {
                $activeSubjects = $u->subjects->where('is_active', true);
                $u->setAttribute('subject_name', $activeSubjects->pluck('name')->first());
                $u->setAttribute('subject_count', $activeSubjects->count());
                $u->setAttribute('subject_data_warning', $activeSubjects->count() > 1 ? 'Tutor memiliki lebih dari satu mapel aktif dan perlu ditinjau.' : null);
                $u->setAttribute('profile_cover_url', $u->profile_cover_use_default
                    ? null
                    : PublicMedia::url($u->profile_cover));
                $u->setAttribute('personal_profile_cover_url', PublicMedia::url($u->profile_cover));
            }
            if ($u->role === 'teacher' && $u->teacherProfile) {
                $u->photo_url = PublicMedia::url($u->teacherProfile->photo);
                $u->teacherProfile->setAttribute('cv_url', $u->teacherProfile->cv_file
                    ? "/teachers/{$u->id}/documents/cv_file"
                    : null);
            }

            return $u;
        });

        return response()->json([
            'data' => $users->items(),
            'subject_options' => $role === 'teacher'
                ? TeacherSubject::query()->where('is_active', true)->whereNotNull('name')
                    ->distinct()->orderBy('name')->pluck('name')->values()
                : [],
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function updateTeacherProfileCover(Request $request, int $id)
    {
        $request->validate([
            'profile_cover' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);
        $teacher = User::query()->where('role', 'teacher')->findOrFail($id);
        $path = $request->file('profile_cover')->store('profile_covers', 'public');
        try {
            $oldPath = DB::transaction(function () use ($teacher, $path) {
                $lockedTeacher = User::query()->where('role', 'teacher')->lockForUpdate()->findOrFail($teacher->id);
                $oldPath = $lockedTeacher->profile_cover;
                $lockedTeacher->profile_cover = $path;
                $lockedTeacher->profile_cover_use_default = false;
                $lockedTeacher->save();

                return $oldPath;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Sampul tutor berhasil diperbarui.',
            'profile_cover_url' => PublicMedia::url($path),
        ]);
    }

    public function resetTeacherProfileCover(int $id)
    {
        $teacher = User::query()->where('role', 'teacher')->findOrFail($id);
        $teacher->profile_cover_use_default = true;
        $teacher->save();

        return response()->json([
            'message' => 'Tutor memakai sampul default. Sampul pribadi tetap tersimpan.',
            'profile_cover_url' => null,
        ]);
    }

    public function restoreTeacherProfileCover(int $id)
    {
        $teacher = User::query()->where('role', 'teacher')->findOrFail($id);
        if (! $teacher->profile_cover || ! Storage::disk('public')->exists($teacher->profile_cover)) {
            return response()->json(['message' => 'Tutor belum mempunyai sampul pribadi yang dapat dipulihkan.'], 422);
        }
        $teacher->profile_cover_use_default = false;
        $teacher->save();

        return response()->json([
            'message' => 'Sampul pribadi tutor kembali dipakai.',
            'profile_cover_url' => PublicMedia::url($teacher->profile_cover),
        ]);
    }

    public function updateUserStatus(
        Request $request,
        TeacherOfferReleaseService $offerReleaseService,
        TeacherMatchingService $matchingService
    ) {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'status' => 'required|in:active,banned',
        ]);

        $affectedRole = null;
        DB::transaction(function () use ($request, &$affectedRole) {
            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($request->user_id);
            $profile = $user->role === 'teacher'
                ? TeacherProfile::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($user->role === 'admin') {
                abort(403, 'Akun admin tidak dapat diblokir dari menu pengguna.');
            }
            $affectedRole = $user->role;
            if (
                $request->status === 'active'
                && $user->role === 'teacher'
                && (! $profile?->verified_at)
            ) {
                abort(422, 'Tutor hanya dapat diaktifkan jika verifikasi masih berlaku.');
            }
            if ($user->status === $request->status) {
                abort(422, 'Status akun tersebut sudah tersimpan.');
            }

            $user->update(['status' => $request->status]);
            if ($profile) {
                $profile->update([
                    'is_accepting_requests' => $request->status === 'active',
                ]);
            }

            if ($request->status === 'banned') {
                $user->tokens()->delete();
            }
        }, 3);

        if ($request->status === 'banned' && $affectedRole === 'teacher') {
            $offerReleaseService
                ->releaseForTeacher((int) $request->user_id, 'Akun tutor diblokir admin')
                ->each(fn ($bookingRequest) => $matchingService->dispatchNextOffer($bookingRequest));
        }

        return response()->json([
            'message' => $request->status === 'active'
                ? 'Akun berhasil diaktifkan kembali.'
                : 'Akun berhasil diblokir dan seluruh sesi loginnya ditutup.',
            'new_status' => $request->status,
        ]);
    }

    // =========================================================================
    // 3. MANAJEMEN PEMBAYARAN MURID (ORDER)
    // =========================================================================

    public function getOrders()
    {
        $orders = Order::with(['user', 'classroom', 'booking.teacher', 'refund'])
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get();

        return response()->json($orders);
    }

    public function getPendingPayments()
    {
        $orders = Order::where('status', 'submitted')
            ->whereNotNull('payment_proof')
            ->where('payment_proof', '!=', '')
            ->with('user')
            ->orderBy('updated_at', 'desc')
            ->limit(100)
            ->get();

        $formatted = $orders->map(function ($order) {
            $details = $this->orderDetails($order);

            $subject = $details['subject'] ?? 'Kelas';
            $teacher = $details['teacher_name'] ?? 'Tutor';
            $student = $details['student_name'] ?? $order->user->name ?? 'Murid';

            $title = '';
            if (($details['type'] ?? '') === 'Privat') {
                $title = "{$subject} - {$teacher} ({$student})";
            } else {
                $title = "{$subject} - {$teacher} (Grup)";
            }

            return [
                'id' => $order->id,
                'order_id' => $order->order_id,
                'user_name' => $order->user->name ?? 'Unknown',
                'amount' => $order->amount,
                'payment_proof' => $order->payment_proof_url,
                'bank_name' => $order->bank_name,
                'sender_name' => $order->sender_name,
                'sender_account_number' => $order->sender_account_number,
                'status' => 'Menunggu Verifikasi',
                'created_at' => $order->created_at,
                'scheduled_at' => $details['start_at'] ?? null,
                'subject' => $subject,
                'teacher_name' => $teacher,
                'type' => $details['type'] ?? 'Privat',
                'title' => $title,
                'classroom' => [
                    'title' => $title,
                    'subject' => $subject,
                ],
            ];
        });

        return response()->json($formatted);
    }

    // --- [VERIFIKASI PEMBAYARAN (JADWAL KOMPLEKS)] ---
    public function verifyPayment(
        Request $request,
        PackageCheckoutService $packageCheckoutService,
        CheapClassService $cheapClassService,
        PaymentReconciliationService $reconciliation
    ) {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'status' => 'required|in:paid,rejected',
            'reason' => 'nullable|string|max:500',
            'actual_received_amount' => 'required_if:status,paid|nullable|numeric|min:1|max:999999999999.99',
        ]);

        $order = Order::findOrFail($validated['order_id']);
        if ($order->status !== 'submitted') {
            $message = match ($order->status) {
                'cancelled' => 'Tagihan ini sudah dibatalkan murid. Tidak ada bukti aktif yang perlu diproses lagi.',
                'rejected' => 'Bukti pembayaran ini sudah pernah ditolak. Muat ulang daftar pembayaran untuk melihat status terbaru.',
                'paid' => 'Pembayaran ini sudah diterima dan tidak dapat diproses ulang.',
                'refund_pending' => 'Pembayaran ini sudah masuk proses refund dan tidak dapat diverifikasi ulang.',
                'refunded' => 'Pembayaran ini sudah direfund dan tidak dapat diverifikasi ulang.',
                'expired' => 'Tagihan ini sudah kedaluwarsa dan tidak dapat diproses.',
                'pending' => 'Tagihan ini belum memiliki bukti pembayaran yang sedang menunggu pemeriksaan.',
                default => 'Status pembayaran sudah berubah. Muat ulang daftar pembayaran sebelum melanjutkan.',
            };

            return response()->json([
                'message' => $message,
                'current_status' => $order->status,
            ], 409);
        }

        if (! $order->payment_proof) {
            return response()->json(['message' => 'Bukti pembayaran belum diunggah.'], 422);
        }

        $details = $this->orderDetails($order);
        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($validated['status'] === 'rejected' && $reason === '') {
            return response()->json([
                'message' => 'Alasan penolakan pembayaran wajib diisi.',
            ], 422);
        }

        if ($validated['status'] === 'rejected') {
            if ($reconciliation->rejectCurrentSubmission($order, $reason, $request->user())) {
                return response()->json([
                    'message' => 'Bukti tambahan ditolak. Dana sebelumnya tetap tercatat dan murid dapat mengunggah bukti top-up baru.',
                    'reconciliation' => 'underpaid',
                ]);
            }
        } else {
            $paymentResult = $reconciliation->verify(
                $order,
                (float) $validated['actual_received_amount'],
                $request->user()
            );
            if ($paymentResult['result'] === 'underpaid') {
                return response()->json([
                    'message' => 'Pembayaran tercatat kurang Rp'.number_format($paymentResult['outstanding'], 0, ',', '.').'. Murid diminta melakukan top-up.',
                    'reconciliation' => 'underpaid',
                    'received_amount' => $paymentResult['received'],
                    'outstanding_amount' => $paymentResult['outstanding'],
                    'top_up_due_at' => $order->fresh()->top_up_due_at,
                ]);
            }
        }

        if ($order->learning_package_id) {
            if ($validated['status'] === 'rejected') {
                $packageCheckoutService->rejectPackagePayment($order, $reason, $request->user());

                return response()->json(['message' => 'Pembayaran paket ditolak.']);
            }
            $result = $packageCheckoutService->activatePaidPackage($order, $request->user());
            $surplus = $reconciliation->creditSurplusToWallet($order->fresh(), $request->user());
            if ($surplus > 0) {
                Notification::create([
                    'user_id' => $order->user_id,
                    'title' => 'Kelebihan pembayaran masuk ke saldo',
                    'message' => 'Kelebihan Rp'.number_format($surplus, 0, ',', '.').' telah masuk ke Saldo BimbelKu.',
                    'type' => 'success',
                    'target_url' => '/student/history',
                ]);
            }

            return response()->json([
                'message' => $result === 'refund_pending'
                    ? 'Pembayaran tercatat, tetapi sesi pertama sudah dimulai. Refund penuh masuk antrean admin.'
                    : ($result === 'no_teacher'
                        ? 'Pembayaran diterima, tetapi belum ada tutor yang tersedia.'
                        : 'Pembayaran diterima dan pencarian tutor dimulai.')
                    .($surplus > 0 ? ' Kelebihan Rp'.number_format($surplus, 0, ',', '.').' masuk ke Saldo BimbelKu.' : ''),
                'reconciliation' => $order->fresh()->payment_reconciliation_status,
                'surplus_amount' => $surplus,
            ]);
        }

        if ($order->cheap_class_enrollment_id) {
            $result = $cheapClassService->verifyPayment(
                $order,
                $validated['status'],
                $reason,
                $request->user()
            );
            if (! empty($result['class_id'])) {
                $cheapClass = CheapClass::find($result['class_id']);
                if ($cheapClass) {
                    $cheapClassService->finalizeIfReady($cheapClass);
                }
            }
            $surplus = $validated['status'] === 'paid'
                ? $reconciliation->creditSurplusToWallet($order->fresh(), $request->user())
                : 0.0;
            if ($surplus > 0) {
                Notification::create([
                    'user_id' => $order->user_id,
                    'title' => 'Kelebihan pembayaran masuk ke saldo',
                    'message' => 'Kelebihan Rp'.number_format($surplus, 0, ',', '.').' telah masuk ke Saldo BimbelKu.',
                    'type' => 'success',
                    'target_url' => '/student/history',
                ]);
            }

            return response()->json([
                'message' => $result['message'].($surplus > 0 ? ' Kelebihan Rp'.number_format($surplus, 0, ',', '.').' masuk ke Saldo BimbelKu.' : ''),
                'reconciliation' => $order->fresh()->payment_reconciliation_status,
                'surplus_amount' => $surplus,
            ]);
        }

        return response()->json([
            'message' => 'Pesanan langsung lama sudah dipensiunkan. Hanya Paket Belajar dan Kelas Kelompok yang dapat diverifikasi.',
        ], 410);
    }

    public function getPaymentSettings()
    {
        $settings = PaymentSetting::firstOrCreate(['singleton_key' => 1], [
            'merchant_name' => 'BimbelKu Official',
            'bank_name' => '',
            'account_number' => '',
            'account_name' => '',
        ]);

        return response()->json($this->paymentSettingsPayload($settings));
    }

    public function qrisImage()
    {
        $settings = PaymentSetting::query()->where('singleton_key', 1)->first();
        $path = $settings?->qris_image;

        abort_if(
            blank($path) || ! Storage::disk('public')->exists($path),
            404,
            'QRIS pembayaran belum tersedia.'
        );

        $contents = Storage::disk('public')->get($path);
        $mimeType = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="qris-bimbelku.'.pathinfo($path, PATHINFO_EXTENSION).'"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updatePaymentSettings(Request $request)
    {
        $request->validate([
            'merchant_name' => ['required', 'string', 'max:100', 'regex:/\pL/u'],
            'bank_name' => ['required', 'string', 'max:100', 'regex:/\pL/u'],
            'account_number' => ['required', 'string', 'max:50', 'regex:/^[0-9]{6,50}$/'],
            'account_name' => ['required', 'string', 'max:150', 'regex:/\pL/u', 'not_regex:/\d/u'],
            'qris_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'merchant_name.regex' => 'Nama merchant wajib mengandung huruf.',
            'bank_name.regex' => 'Nama bank atau e-wallet wajib mengandung huruf.',
            'account_number.regex' => 'Nomor rekening harus berisi 6–50 angka.',
            'account_name.regex' => 'Nama pemilik rekening wajib mengandung huruf.',
            'account_name.not_regex' => 'Nama pemilik rekening tidak boleh memuat angka.',
        ]);

        $settings = PaymentSetting::firstOrCreate(['singleton_key' => 1], [
            'merchant_name' => 'BimbelKu Official',
            'bank_name' => '',
            'account_number' => '',
            'account_name' => '',
        ]);
        $newQrisImage = $request->hasFile('qris_image')
            ? $request->file('qris_image')->store('payment_settings', 'public')
            : null;
        $oldQrisImage = null;

        try {
            DB::transaction(function () use (
                $request,
                $settings,
                $newQrisImage,
                &$oldQrisImage
            ) {
                $lockedSettings = PaymentSetting::query()
                    ->lockForUpdate()
                    ->findOrFail($settings->id);
                $paymentDestinationIsConfigured = filled($lockedSettings->bank_name)
                    && filled($lockedSettings->account_number)
                    && filled($lockedSettings->account_name);
                $bankDestinationChanged = $paymentDestinationIsConfigured && (
                    $lockedSettings->merchant_name !== $request->merchant_name
                    || $lockedSettings->bank_name !== $request->bank_name
                    || $lockedSettings->account_number !== $request->account_number
                    || $lockedSettings->account_name !== $request->account_name
                );
                $hasOpenPayments = Order::query()
                    ->where(function ($query) {
                        $query->where('status', 'submitted')
                            ->orWhere(function ($open) {
                                $open->whereIn('status', ['pending', 'rejected'])
                                    ->where(function ($sources) {
                                        $sources
                                            ->whereHas('booking', fn ($booking) => $booking
                                                ->whereNotNull('payment_due_at')
                                                ->where('payment_due_at', '>', now()))
                                            ->orWhereHas('learningPackage', fn ($package) => $package
                                                ->whereNotNull('payment_due_at')
                                                ->where('payment_due_at', '>', now()));
                                    });
                            });
                    })
                    ->exists();

                if ($bankDestinationChanged && $hasOpenPayments) {
                    abort(422, 'Rekening tidak dapat diubah saat masih ada tagihan aktif atau bukti pembayaran yang belum diperiksa. QRIS tetap dapat diunggah tanpa mengubah data rekening.');
                }

                $oldQrisImage = $lockedSettings->qris_image;
                $lockedSettings->merchant_name = $request->merchant_name;
                $lockedSettings->bank_name = $request->bank_name;
                $lockedSettings->account_number = $request->account_number;
                $lockedSettings->account_name = $request->account_name;
                if ($newQrisImage) {
                    $lockedSettings->qris_image = $newQrisImage;
                }
                $lockedSettings->save();
            }, 3);
        } catch (\Throwable $exception) {
            if ($newQrisImage) {
                Storage::disk('public')->delete($newQrisImage);
            }
            throw $exception;
        }

        if ($newQrisImage && $oldQrisImage && $oldQrisImage !== $newQrisImage) {
            Storage::disk('public')->delete($oldQrisImage);
        }

        $settings = $settings->fresh();

        return response()->json([
            'message' => 'Pengaturan pembayaran berhasil disimpan.',
            'data' => $this->paymentSettingsPayload($settings),
        ]);
    }

    private function paymentSettingsPayload(PaymentSetting $settings): array
    {
        $qrisAvailable = filled($settings->qris_image)
            && Storage::disk('public')->exists($settings->qris_image);

        return [
            'id' => $settings->id,
            'provider' => 'xendit',
            'xendit_configured' => (bool) config('xendit.enabled')
                && filled(config('xendit.secret_key'))
                && filled(config('xendit.webhook_token')),
            'merchant_name' => $settings->merchant_name,
            'bank_name' => $settings->bank_name,
            'account_number' => $settings->account_number,
            'account_name' => $settings->account_name,
            'qris_available' => $qrisAvailable,
            'qris_endpoint' => $qrisAvailable
                ? '/payment-settings/qris?v='.optional($settings->updated_at)->timestamp
                : null,
            'updated_at' => $settings->updated_at,
        ];
    }

    // =========================================================================
    // 5. MANAJEMEN KEUANGAN & GAJI GURU (DINAMIS % FEE)
    // =========================================================================

    public function getCommissionSetting()
    {
        return response()->json(['admin_fee' => 20, 'fixed' => true]);
    }

    public function updateCommissionSetting(Request $request)
    {
        $request->validate(['admin_fee' => ['required', 'numeric', 'in:20']]);
        Setting::query()->updateOrCreate(['key' => 'admin_fee'], ['value' => '20']);

        return response()->json(['message' => 'Komisi tetap 20%; hak tutor tetap 80%.']);
    }

    public function getFinanceData(Request $request)
    {
        Carbon::setLocale('id');
        $currentGlobalFee = 20.0;
        $history = Payout::with('user')->latest()->limit(200)->get()->map(function ($p) {
            return [
                'id' => $p->id,
                'name' => $p->user?->name ?? 'Tutor',
                'period' => $p->period,
                'grossAmount' => (float) ($p->gross_amount ?? 0),
                'commissionAmount' => (float) ($p->commission_amount ?? 0),
                'netAmount' => (float) $p->amount,
                'transferDate' => ($p->processed_at ?? $p->created_at)->format('d M Y H:i'),
                'proof_url' => $p->proof_url ? "payouts/{$p->id}/proof" : null,
                'bookingIds' => $p->booking_ids ?? ($p->booking_id ? [$p->booking_id] : []),
                'status' => match ($p->status) {
                    'completed' => 'Berhasil',
                    'processing' => 'Sedang diproses',
                    'reversed' => 'Dikembalikan bank',
                    default => 'Gagal',
                },
            ];
        });

        $pending = Payout::query()
            ->with('user:id,name')
            ->where('status', 'processing')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (Payout $payout) => [
                'queueKey' => 'payout:'.$payout->id,
                'id' => $payout->id,
                'teacherId' => $payout->user_id,
                'name' => $payout->user?->name ?? 'Tutor',
                'period' => $payout->period,
                'netAmount' => (float) $payout->amount,
                'requestedAmount' => (float) ($payout->requested_amount ?? $payout->amount),
                'taxAmount' => (float) ($payout->tax_amount ?? 0),
                'status' => 'Sedang diproses',
                'gatewayStatus' => $payout->gateway_status,
                'createdAt' => $payout->created_at,
            ]);

        $sevenDaysAgo = Carbon::now()->subDays(7);
        $paidPayouts7Days = Payout::query()
            ->where('status', 'completed')
            ->where('processed_at', '>=', $sevenDaysAgo);
        $readyAmount = (float) Booking::query()
            ->where('status', 'completed')
            ->selectRaw('COALESCE(SUM(CASE WHEN teacher_net_amount - teacher_paid_amount - teacher_reserved_amount > 0 THEN teacher_net_amount - teacher_paid_amount - teacher_reserved_amount ELSE 0 END), 0) AS amount')
            ->value('amount');
        $requestedAmount = (float) Booking::query()
            ->where('status', 'completed')
            ->sum('teacher_reserved_amount');

        return response()->json([
            'pending' => $pending,
            'history' => $history,
            'stats' => [
                'ready_amount' => round($readyAmount, 2),
                'requested_amount' => round($requestedAmount, 2),
                'paid_7days' => round((float) (clone $paidPayouts7Days)->sum('amount'), 2),
                'paid_count_7days' => (clone $paidPayouts7Days)->count(),
                'admin_fee_percent' => $currentGlobalFee,
            ],
        ]);
    }

    public function processPayout(Request $request, XenditMoneyMovementService $xendit)
    {
        $validated = $request->validate([
            'teacher_id' => 'required|exists:users,id',
            'booking_ids' => 'required|array|min:1|max:200',
            'booking_ids.*' => 'integer|distinct|exists:bookings,id',
        ]);

        $teacher = User::query()
            ->where('role', 'teacher')
            ->with('teacherProfile')
            ->find($validated['teacher_id']);
        $channelCode = $teacher?->teacherProfile?->payout_channel_code
            ?: XenditPayoutChannelCatalog::inferCode($teacher?->teacherProfile?->bank_name);
        if (
            ! $teacher
            || blank($channelCode)
            || blank($teacher->teacherProfile?->account_number)
            || blank($teacher->teacherProfile?->account_name)
            || blank($teacher->address)
        ) {
            return response()->json([
                'message' => 'Rekening atau alamat tutor belum lengkap. Minta tutor memperbarui profil sebelum pencairan.',
            ], 422);
        }
        if ($teacher->teacherProfile?->payout_hold_until?->isFuture()) {
            return response()->json([
                'message' => 'Pencairan ditahan sampai '.$teacher->teacherProfile->payout_hold_until
                    ->translatedFormat('d M Y, H:i').' WIB setelah perubahan rekening.',
            ], 422);
        }

        try {
            $payout = DB::transaction(function () use (
                $request,
                $validated,
                $channelCode
            ) {
                $profile = TeacherProfile::query()
                    ->where('user_id', $validated['teacher_id'])
                    ->lockForUpdate()
                    ->first();
                if (
                    ! $profile
                    || blank($channelCode)
                    || blank($profile->account_number)
                    || blank($profile->account_name)
                ) {
                    abort(422, 'Rekening tutor belum lengkap atau berubah. Muat ulang data sebelum mencairkan.');
                }
                if ($profile->payout_hold_until?->isFuture()) {
                    abort(
                        422,
                        'Pencairan masih ditahan sampai '
                        .$profile->payout_hold_until->translatedFormat('d M Y, H:i')
                        .' WIB.'
                    );
                }
                if (blank($profile->payout_channel_code)) {
                    $profile->forceFill(['payout_channel_code' => $channelCode])->save();
                }

                $bookings = Booking::query()
                    ->where('teacher_id', $validated['teacher_id'])
                    ->whereIn('id', $validated['booking_ids'])
                    ->where('status', 'completed')
                    ->whereIn('payout_status', ['ready', 'requested'])
                    ->lockForUpdate()
                    ->get();

                if ($bookings->count() !== count(array_unique($validated['booking_ids']))) {
                    abort(422, 'Sebagian sesi sudah dicairkan atau belum siap dicairkan.');
                }

                $gross = (float) $bookings->sum('gross_amount');
                $net = (float) $bookings->sum('teacher_net_amount');
                $commission = $gross - $net;
                $bookingIds = $bookings->pluck('id')->sort()->values()->all();
                $payoutRequestIds = $bookings->pluck('payout_request_id')->filter()->unique()->values();
                if ($payoutRequestIds->count() > 1) {
                    abort(422, 'Sesi berasal dari beberapa pengajuan pencairan. Proses setiap pengajuan secara terpisah.');
                }
                $teacherPayoutRequest = $payoutRequestIds->isNotEmpty()
                    ? TeacherPayoutRequest::query()->lockForUpdate()->findOrFail($payoutRequestIds->first())
                    : null;
                if ($teacherPayoutRequest) {
                    $requestBookingIds = collect($teacherPayoutRequest->booking_ids)->map(fn ($id) => (int) $id)->sort()->values()->all();
                    if (
                        $teacherPayoutRequest->status !== 'pending'
                        || (int) $teacherPayoutRequest->teacher_id !== (int) $validated['teacher_id']
                        || $requestBookingIds !== array_map('intval', $bookingIds)
                    ) {
                        abort(422, 'Data pengajuan pencairan telah berubah. Muat ulang halaman keuangan.');
                    }
                }
                $record = Payout::create([
                    'user_id' => $validated['teacher_id'],
                    'amount' => $net,
                    'period' => $bookings->min('completed_at')?->format('d M Y').' - '.now()->format('d M Y'),
                    'total_classes' => $bookings->count(),
                    'proof_url' => null,
                    'status' => 'processing',
                    'payment_provider' => 'xendit',
                    'gross_amount' => $gross,
                    'commission_amount' => $commission,
                    'processed_by' => $request->user()->id,
                    'processed_at' => now(),
                    'booking_ids' => $bookingIds,
                    'bank_name' => $profile->bank_name,
                    'account_number' => $profile->account_number,
                    'account_name' => $profile->account_name,
                    'payout_channel_code' => $channelCode,
                ]);

                $bookings->each->update(['payout_status' => 'requested']);

                $teacherPayoutRequest?->update([
                    'status' => 'processing',
                    'payout_id' => $record->id,
                ]);

                Notification::create([
                    'user_id' => $validated['teacher_id'],
                    'title' => 'Pencairan sedang diproses',
                    'message' => 'Pencairan Rp'.number_format($net, 0, ',', '.').' sedang diproses otomatis.',
                    'type' => 'info',
                    'target_url' => '/guru/gaji',
                    'unique_key' => "payout-processing:{$record->id}",
                ]);

                return $record;
            });
            $xendit->createPayout($payout, $teacher);
        } catch (\RuntimeException $exception) {
            if (isset($payout)) {
                DB::transaction(function () use ($payout, $exception) {
                    $payout->forceFill(['status' => 'failed', 'gateway_status' => 'FAILED', 'gateway_failure_code' => 'CREATE_REQUEST_FAILED'])->save();
                    Booking::query()->whereIn('id', $payout->booking_ids ?? [])->update(['payout_status' => 'ready', 'payout_request_id' => null]);
                    TeacherPayoutRequest::query()->where('payout_id', $payout->id)->update(['status' => 'failed', 'processed_at' => now(), 'review_notes' => $exception->getMessage()]);
                });
            }

            return response()->json(['message' => $exception->getMessage()], 502);
        }

        $payload = $payout->toArray();

        return response()->json(['message' => 'Pencairan diterima dan sedang diproses.', 'data' => $payload], 202);
    }

    public function getDashboardStats(Request $request)
    {
        $admin = $request->user();
        $can = fn (string $permission): bool => $admin->hasAdminPermission($permission);
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfYear = Carbon::now()->startOfYear();

        $canPayments = $can(AdminPermissionCatalog::FINANCE_PAYMENTS);
        $canMatching = $can(AdminPermissionCatalog::MATCHING_MANAGE);
        $canCases = $can(AdminPermissionCatalog::CASES_MANAGE);
        $canRefunds = $can(AdminPermissionCatalog::FINANCE_REFUNDS);
        $canPayouts = $can(AdminPermissionCatalog::FINANCE_PAYOUTS);
        $canTeachers = $can(AdminPermissionCatalog::TEACHERS_MANAGE);
        $canUsers = $can(AdminPermissionCatalog::USERS_MANAGE);
        $canClasses = $can(AdminPermissionCatalog::CLASSES_MANAGE);

        $revenueToday = $canPayments
            ? Order::where('status', 'paid')->whereDate('verified_at', $today)->sum('amount')
            : 0;
        $revenueMonth = $canPayments
            ? Order::where('status', 'paid')->where('verified_at', '>=', $startOfMonth)->sum('amount')
            : 0;
        $revenueYear = $canPayments
            ? Order::where('status', 'paid')->where('verified_at', '>=', $startOfYear)->sum('amount')
            : 0;

        $pendingTeachers = $canTeachers
            ? User::where('role', 'teacher')->where('status', 'pending')->count()
            : 0;
        $pendingOrders = $canPayments
            ? Order::where('status', 'submitted')->whereNotNull('payment_proof')->count()
            : 0;
        $totalUsers = $canUsers ? User::where('status', 'active')->count() : 0;
        $activeMatching = $canMatching
            ? BookingRequest::query()
                ->whereIn('status', ['matching', 'teacher_pending'])
                ->matchingAnchors()
                ->count()
            : 0;
        $matchingNeedsAttention = $canMatching
            ? BookingRequest::query()
                ->whereNotNull('package_subject_id')
                ->matchingAnchors()
                ->matchingNeedsAttention()
                ->count()
            : 0;
        $pendingCases = $canCases
            ? SessionReport::where('status', 'pending')->count()
                + BookingDispute::where('status', 'pending')->count()
                + Booking::where('status', 'admin_review_required')->count()
            : 0;
        $monitoringNeedsAttention = $canClasses
            ? Booking::query()
                ->where('class_type', 'private')
                ->whereIn('status', [
                    'disputed',
                    'absence_review',
                    'admin_review_required',
                    'emergency_refund_pending',
                    'refund_pending',
                ])
                ->count()
            : 0;
        $pendingRefunds = $canRefunds ? Refund::query()
            ->whereIn('status', ['pending', 'failed'])
            ->whereNotNull('destination_selected_at')
            ->where(function ($refunds) {
                $refunds->whereIn('destination_method', ['bank_transfer', 'bimbelku_balance'])
                    ->orWhereIn('gateway_status', ['FAILED', 'REJECTED'])
                    ->orWhere(function ($stale) {
                        $stale->where('destination_method', 'xendit_original')
                            ->where('created_at', '<=', now()->subDay());
                    });
            })
            ->count() : 0;
        $pendingPayouts = $canPayouts ? TeacherPayoutRequest::where('status', 'pending')->count() : 0;

        $matchingPreview = $canMatching
            ? BookingRequest::query()
                ->whereIn('status', ['matching', 'teacher_pending', 'no_teacher'])
                ->matchingAnchors()
                ->whereDate('scheduled_date', '>=', $today)
                ->with(['student:id,name', 'matchedTeacher:id,name'])
                ->orderByRaw("CASE status WHEN 'no_teacher' THEN 0 WHEN 'teacher_pending' THEN 1 ELSE 2 END")
                ->orderByRaw('CASE WHEN teacher_response_deadline IS NOT NULL AND teacher_response_deadline <= ? THEN 0 ELSE 1 END', [now()])
                ->orderBy('search_started_at')
                ->limit(5)
                ->get()
                ->map(function (BookingRequest $bookingRequest) {
                    $isOverdue = $bookingRequest->status === 'teacher_pending'
                        && $bookingRequest->teacher_response_deadline?->isPast();
                    $needsAttention = $bookingRequest->status === 'no_teacher' || $isOverdue;

                    return [
                        'id' => $bookingRequest->id,
                        'student_name' => $bookingRequest->student?->name ?? 'Murid',
                        'subject_name' => $bookingRequest->subject_name,
                        'status' => $bookingRequest->status,
                        'status_label' => match ($bookingRequest->status) {
                            'matching' => 'Mencari tutor',
                            'teacher_pending' => $isOverdue ? 'Jawaban tutor terlambat' : 'Menunggu tutor',
                            'no_teacher' => 'Tutor belum ditemukan',
                            default => $bookingRequest->status,
                        },
                        'teacher_name' => $bookingRequest->matchedTeacher?->name,
                        'learning_mode' => $bookingRequest->learning_mode,
                        'search_radius_km' => $bookingRequest->learning_mode === 'offline' ? (int) $bookingRequest->search_radius_km : null,
                        'scheduled_at' => Carbon::parse(
                            $bookingRequest->scheduled_date->format('Y-m-d').' '.$bookingRequest->start_time,
                            config('app.timezone', 'Asia/Jakarta')
                        )->toIso8601String(),
                        'needs_attention' => $needsAttention,
                    ];
                })
                ->values()
            : collect();

        $workQueue = collect([
            $canPayments ? [
                'key' => 'payments',
                'label' => 'Pembayaran murid',
                'description' => 'Bukti pembayaran menunggu pemeriksaan.',
                'count' => $pendingOrders,
                'href' => '/admin/pembayaran',
                'tone' => $pendingOrders > 0 ? 'urgent' : 'normal',
            ] : null,
            $canMatching ? [
                'key' => 'matching',
                'label' => 'Pencarian tutor',
                'description' => 'Permintaan yang perlu dipantau atau disinkronkan.',
                'count' => $matchingNeedsAttention,
                'href' => '/admin/tutor-searches?status=attention',
                'tone' => $matchingNeedsAttention > 0 ? 'urgent' : 'normal',
            ] : null,
            $canCases ? [
                'key' => 'cases',
                'label' => 'Kasus dan keberatan',
                'description' => 'Kasus operasional menunggu keputusan admin.',
                'count' => $pendingCases,
                'href' => '/admin/cases',
                'tone' => $pendingCases > 0 ? 'warning' : 'normal',
            ] : null,
            $canClasses ? [
                'key' => 'monitoring',
                'label' => 'Monitoring kelas',
                'description' => 'Sesi aktif yang memerlukan tindak lanjut operasional.',
                'count' => $monitoringNeedsAttention,
                'href' => '/admin/classes?scope=attention',
                'tone' => $monitoringNeedsAttention > 0 ? 'urgent' : 'normal',
            ] : null,
            $canRefunds ? [
                'key' => 'refunds',
                'label' => 'Refund perlu diperiksa',
                'description' => 'Refund gagal, terlambat, atau manual yang memerlukan tindakan.',
                'count' => $pendingRefunds,
                'href' => '/admin/finance?tab=refunds',
                'tone' => $pendingRefunds > 0 ? 'warning' : 'normal',
            ] : null,
            $canTeachers ? [
                'key' => 'teachers',
                'label' => 'Verifikasi tutor',
                'description' => 'Akun tutor baru menunggu pemeriksaan.',
                'count' => $pendingTeachers,
                'href' => '/admin/guru',
                'tone' => $pendingTeachers > 0 ? 'warning' : 'normal',
            ] : null,
        ])->filter()
            ->filter(fn (array $item) => $item['count'] > 0 && filled($item['href']))
            ->sortByDesc(fn (array $item) => ($item['tone'] === 'urgent' ? 2000 : ($item['tone'] === 'warning' ? 1000 : 0)) + $item['count'])
            ->values();

        $chartStart = Carbon::today()->subDays(6);
        $chartRows = collect(range(0, 6))->map(function (int $offset) use ($chartStart, $canClasses, $canPayments) {
            $date = $chartStart->copy()->addDays($offset);
            $sessions = $canClasses ? Booking::query()->whereDate('start_at', $date)->count() : 0;
            $orders = $canPayments ? Order::query()->whereDate('created_at', $date)->count() : 0;
            $completed = $canClasses ? Booking::query()->whereDate('start_at', $date)->whereIn('status', ['completed', 'student_confirmed'])->count() : 0;
            $inProgress = $canClasses ? Booking::query()->whereDate('start_at', $date)->whereIn('status', ['in_progress', 'teacher_ready', 'student_ready'])->count() : 0;
            $confirmed = $canClasses ? Booking::query()->whereDate('start_at', $date)->whereIn('status', ['confirmed', 'scheduled'])->count() : 0;
            $attention = $canClasses ? Booking::query()->whereDate('start_at', $date)->whereIn('status', ['disputed', 'absence_review', 'admin_review_required', 'refund_pending', 'emergency_refund_pending'])->count() : 0;

            return ['date' => $date->toDateString(), 'label' => $date->isoFormat('dd D'), 'sessions' => $sessions, 'orders' => $orders, 'confirmed' => $confirmed, 'in_progress' => $inProgress, 'completed' => $completed, 'attention' => $attention];
        })->values();

        return response()->json([
            'revenue' => [
                'today' => (float) $revenueToday,
                'month' => (float) $revenueMonth,
                'year' => (float) $revenueYear,
            ],
            'counts' => [
                'teachers' => $pendingTeachers,
                'orders' => $pendingOrders,
                'users' => $totalUsers,
                'matching_active' => $activeMatching,
                'matching_attention' => $matchingNeedsAttention,
                'cases' => $pendingCases,
                'monitoring_attention' => $monitoringNeedsAttention,
                'refunds' => $pendingRefunds,
                'payouts' => $pendingPayouts,
            ],
            'visible_sections' => [
                'payments' => $canPayments,
                'matching' => $canMatching,
                'cases' => $canCases,
                'classes' => $canClasses,
                'refunds' => $canRefunds,
                'payouts' => $canPayouts,
                'teachers' => $canTeachers,
                'users' => $canUsers,
            ],
            'work_queue' => $workQueue,
            'matching_preview' => $matchingPreview,
            'operational_chart' => $chartRows,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    // =========================================================================
    // 6. FOOTER SETTINGS (ALAMAT & KONTAK SAJA)
    // =========================================================================

    public function updateFooterSettings(Request $request)
    {
        $data = $request->validate([
            'footer_address' => 'required|string|max:1000',
            'footer_phone' => ['required', 'string', 'max:16', 'regex:/^\+?[0-9]{8,15}$/'],
            'footer_email' => 'required|email|max:255',
        ], [
            'footer_phone.regex' => 'Nomor telepon atau WhatsApp harus berisi 8–15 angka.',
        ]);

        DB::transaction(function () use ($data) {
            Setting::query()
                ->whereIn('key', array_keys($data))
                ->orderBy('key')
                ->lockForUpdate()
                ->get();
            foreach ($data as $key => $value) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => $value]
                );
            }
        }, 3);
        Cache::forget('public.footer_settings.v1');

        return response()->json(['message' => 'Pengaturan footer berhasil disimpan.']);
    }

    // =========================================================================
    // 7. SOCIAL MEDIA (TABEL TERPISAH)
    // =========================================================================

    public function getSocials(Request $request)
    {
        $socials = Cache::remember('public.socials.v1', now()->addMinutes(10), fn () => SocialMedia::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (SocialMedia $item) => $this->socialPayload($item, false))->values()->all()
        );
        $response = response()->json($socials);
        $response->setEtag(sha1((string) json_encode($socials)));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->headers->addCacheControlDirective('stale-while-revalidate', '60');
        $response->isNotModified($request);

        return $response;
    }

    public function getAdminSocials()
    {
        return response()->json(SocialMedia::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (SocialMedia $item) => $this->socialPayload($item, true))->values());
    }

    public function storeSocial(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'link' => ['required', 'url:http,https', 'max:1000'],
            'icon' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'icon_url' => ['nullable', 'url:http,https', 'max:500'],
            'icon_key' => ['nullable', Rule::in($this->socialCatalogKeys()), 'required_without_all:icon,icon_url'],
        ]);

        $path = $this->resolveSocialIcon($request);
        try {
            $social = SocialMedia::create([
                'name' => trim((string) $request->name),
                'link' => trim((string) $request->link),
                'icon' => $path,
                'is_active' => true,
                'sort_order' => ((int) SocialMedia::max('sort_order')) + 1,
            ]);
        } catch (\Throwable $exception) {
            $this->deleteSocialIcon($path);
            throw $exception;
        }
        Cache::forget('public.socials.v1');

        return response()->json(['message' => 'Sosmed berhasil ditambah!', 'data' => $this->socialPayload($social, true)]);
    }

    public function updateSocial(Request $request, $id)
    {
        $social = SocialMedia::findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'link' => ['required', 'url:http,https', 'max:1000'],
            'icon' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'icon_url' => ['nullable', 'url:http,https', 'max:500'],
            'icon_key' => ['nullable', Rule::in($this->socialCatalogKeys())],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        $oldIcon = $social->icon;
        $newIcon = $this->resolveSocialIcon($request, false);
        $social->name = trim((string) $data['name']);
        $social->link = trim((string) $data['link']);
        if ($newIcon !== null) {
            $social->icon = $newIcon;
        }
        if (array_key_exists('is_active', $data)) {
            $social->is_active = (bool) $data['is_active'];
        }
        if (array_key_exists('sort_order', $data)) {
            $social->sort_order = (int) $data['sort_order'];
        }
        try {
            $social->save();
        } catch (\Throwable $exception) {
            $this->deleteSocialIcon($newIcon);
            throw $exception;
        }
        if ($newIcon !== null && $oldIcon !== $newIcon) {
            $this->deleteSocialIcon($oldIcon);
        }
        Cache::forget('public.socials.v1');

        return response()->json(['message' => 'Sosmed berhasil diperbarui.', 'data' => $this->socialPayload($social->fresh(), true)]);
    }

    public function reorderSocials(Request $request)
    {
        $data = $request->validate(['items' => ['required', 'array', 'max:100'], 'items.*.id' => ['required', 'integer', 'exists:social_medias,id'], 'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:1000']]);
        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $item) {
                SocialMedia::whereKey($item['id'])->update(['sort_order' => $item['sort_order']]);
            }
        });
        Cache::forget('public.socials.v1');

        return response()->json(['message' => 'Urutan sosial media disimpan.']);
    }

    private function socialCatalogKeys(): array
    {
        return ['instagram', 'facebook', 'youtube', 'tiktok', 'whatsapp', 'linkedin', 'x'];
    }

    private function resolveSocialIcon(Request $request, bool $required = true): ?string
    {
        if ($request->filled('icon_key')) {
            return 'catalog:'.strtolower(trim((string) $request->input('icon_key')));
        }
        if ($request->filled('icon_url')) {
            return trim((string) $request->input('icon_url'));
        }
        if ($request->hasFile('icon')) {
            return $request->file('icon')->store('social_icons', 'public');
        }

        return $required ? null : null;
    }

    private function deleteSocialIcon(?string $icon): void
    {
        if ($icon && ! str_starts_with((string) $icon, 'catalog:') && ! preg_match('#^https?://#i', (string) $icon)) {
            Storage::disk('public')->delete($icon);
        }
    }

    private function socialPayload(SocialMedia $item, bool $admin): array
    {
        $iconKey = str_starts_with((string) $item->icon, 'catalog:') ? substr((string) $item->icon, 8) : null;
        if ($iconKey === 'twitter') {
            $iconKey = 'x';
        }
        $payload = [
            'id' => $item->id,
            'name' => $item->name,
            'link' => $item->link,
            'icon_key' => $iconKey,
            'icon_url' => $this->socialIconUrl($item->icon),
        ];
        if ($admin) {
            $payload['is_active'] = (bool) $item->is_active;
            $payload['sort_order'] = (int) $item->sort_order;
        }

        return $payload;
    }

    private function socialIconUrl(?string $icon): ?string
    {
        if (blank($icon)) {
            return null;
        }
        if (str_starts_with($icon, 'catalog:')) {
            $key = strtolower(substr($icon, 8));
            if ($key === 'twitter') {
                $key = 'x';
            }
            $colors = [
                'instagram' => 'E4405F',
                'facebook' => '1877F2',
                'youtube' => 'FF0000',
                'tiktok' => '000000',
                'whatsapp' => '25D366',
                'linkedin' => '0A66C2',
                'x' => '000000',
            ];

            return 'https://cdn.simpleicons.org/'.rawurlencode($key).'/'.($colors[$key] ?? '111827');
        }

        return PublicMedia::url($icon);
    }

    public function deleteSocial($id)
    {
        $social = SocialMedia::find($id);
        if ($social) {
            $icon = $social->icon;
            $social->delete();
            $this->deleteSocialIcon($icon);
            Cache::forget('public.socials.v1');
        }

        return response()->json(['message' => 'Sosmed dihapus.']);
    }

    private function orderDetails(Order $order): array
    {
        if (is_array($order->class_details_snapshot)) {
            return $order->class_details_snapshot;
        }

        if (! $order->class_details_snapshot) {
            return [];
        }

        return json_decode((string) $order->class_details_snapshot, true) ?: [];
    }
}
