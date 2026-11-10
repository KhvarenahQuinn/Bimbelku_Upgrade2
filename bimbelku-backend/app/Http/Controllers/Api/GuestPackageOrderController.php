<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurriculumSubject;
use App\Models\GuestPackageOrder;
use App\Models\PackagePlan;
use App\Services\HourlyRateService;
use App\Services\LearningProgramSelection;
use App\Services\PackageCheckoutService;
use App\Services\PrivateParticipantPricing;
use App\Support\EducationCatalog;
use App\Support\LearningFocus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GuestPackageOrderController extends Controller
{
    public function store(Request $request, StudentPackageController $packages, HourlyRateService $rates, PackageCheckoutService $checkout, PrivateParticipantPricing $pricing)
    {
        $maximumParticipants = $pricing->maximumParticipants();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'min:8', 'max:30'],
            'package_plan_id' => ['required', 'integer', 'exists:package_plans,id'],
            'learning_program_id' => ['nullable', 'integer', 'exists:learning_programs,id'],
            'education_level' => ['required', Rule::in(EducationCatalog::LEVELS)],
            'grade' => ['required', 'string', 'max:50'],
            'learning_mode' => ['required', Rule::in(['online', 'offline'])],
            'duration_hours' => ['required', 'integer', Rule::in([1, 2])],
            'participant_count' => ['nullable', 'integer', 'between:1,'.$maximumParticipants],
            'purchaser_participates' => ['nullable', 'boolean'],
            'participant_details' => ['nullable', 'array', 'max:'.$maximumParticipants],
            'participant_details.*.full_name' => ['required', 'string', 'min:2', 'max:100'],
            'participant_details.*.nickname' => ['required', 'string', 'min:1', 'max:60'],
            'participant_details.*.birth_date' => ['required', 'date', 'before_or_equal:today'],
            'participant_details.*.gender' => ['required', Rule::in(['male', 'female'])],
            'participant_names' => ['nullable', 'array', 'max:'.$maximumParticipants],
            'participant_names.*' => ['required', 'string', 'min:2', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'maps_link' => ['nullable', 'url', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_consent' => ['nullable', 'boolean'],
            'subjects' => ['required', 'array', 'min:1', 'max:8'],
            'subjects.*.curriculum_subject_id' => ['required', 'integer', 'distinct', 'exists:curriculum_subjects,id'],
            'subjects.*.curriculum_chapter_ids' => ['nullable', 'array', 'max:8'],
            'subjects.*.curriculum_chapter_ids.*' => ['required', 'integer', 'distinct', 'exists:curriculum_chapters,id'],
            'subjects.*.learning_goal' => ['nullable', 'string', 'max:1500'],
            'subjects.*.weekdays' => ['required', 'array', 'min:1', 'max:4'],
            'subjects.*.weekdays.*' => ['required', 'integer', 'between:1,7'],
            'subjects.*.schedules' => ['required', 'array', 'min:1'],
            'subjects.*.schedules.*' => ['required', 'date'],
        ], [
            'subjects.min' => 'Pilih minimal satu mata pelajaran.',
            'subjects.*.weekdays.min' => 'Pilih minimal satu hari belajar untuk setiap mapel.',
            'subjects.*.schedules.min' => 'Atur minimal satu jadwal belajar untuk setiap mapel.',
            'subjects.*.learning_goal.string' => 'Target atau materi belajar harus berupa teks.',
            'subjects.*.learning_goal.max' => 'Target atau materi belajar maksimal 1500 karakter per mapel.',
        ]);
        $data['subjects'] = LearningFocus::normalizeSubjects($data['subjects'], ! empty($data['learning_program_id']), $data['education_level'], $data['grade']);
        $count = (int) ($data['participant_count'] ?? 1);
        $pricing->forCount($count);
        $purchaserParticipates = (bool) ($data['purchaser_participates'] ?? true);
        $participantDetails = array_values($data['participant_details'] ?? []);
        $expectedParticipantDetails = $count - ($purchaserParticipates ? 1 : 0);
        abort_if(count($participantDetails) !== $expectedParticipantDetails, 422, 'Lengkapi identitas setiap peserta di luar pemesan.');
        $participantNames = collect($participantDetails)->pluck('full_name')->values();
        if ($purchaserParticipates) {
            $participantNames->prepend(trim($data['name']));
        }
        $data['purchaser_participates'] = $purchaserParticipates;
        $data['participant_details'] = $participantDetails;
        $data['participant_names'] = $participantNames->all();
        abort_unless(EducationCatalog::supports($data['education_level'], $data['grade']), 422, 'Jenjang dan kelas tidak sesuai.');
        $plan = PackagePlan::query()->where('is_active', true)->findOrFail($data['package_plan_id']);
        if (! empty($data['learning_program_id'])) {
            app(LearningProgramSelection::class)->validate(
                (int) $data['learning_program_id'], (int) $plan->id, $data['education_level'], $data['grade'], $data['subjects']
            );
        }
        abort_if(count($data['subjects']) > $plan->maximum_subjects, 422, 'Jumlah mapel melebihi paket.');
        abort_if(collect($data['subjects'])->sum(fn ($item) => count($item['schedules'])) !== $plan->session_count, 422, 'Jumlah jadwal tidak sesuai sesi paket.');
        if ($data['learning_mode'] === 'offline') {
            abort_if(blank($data['address'] ?? null) || ! isset($data['latitude'], $data['longitude']) || ! ($data['location_consent'] ?? false), 422, 'Alamat, titik lokasi, dan persetujuan penyimpanan lokasi diperlukan untuk kelas tatap muka.');
        }

        $quoteRequest = Request::create('/api/guest/packages/quote', 'POST', [
            'package_plan_id' => $data['package_plan_id'],
            'education_level' => $data['education_level'],
            'learning_mode' => $data['learning_mode'],
            'duration_hours' => $data['duration_hours'],
            'participant_count' => $count,
            'subjects' => collect($data['subjects'])->map(fn ($item) => [
                'curriculum_subject_id' => $item['curriculum_subject_id'],
                'session_count' => count($item['schedules']),
            ])->all(),
        ]);
        $quotedAmount = (int) ($packages->previewPromotion($quoteRequest, $rates, $checkout, $pricing)->getData(true)['total_amount'] ?? 0);
        abort_if($quotedAmount < 1, 422, 'Harga paket belum tersedia.');

        $guest = GuestPackageOrder::query()->create([
            'code' => 'BKU-T-'.strtoupper(Str::random(16)),
            'email' => mb_strtolower(trim($data['email'])),
            'name' => trim($data['name']),
            'phone' => trim($data['phone']),
            'payload' => $data,
            'quoted_total_amount' => $quotedAmount,
            'expires_at' => now()->addHour(),
        ]);

        return response()->json([
            'message' => 'Draf pesanan tercatat. Masuk dengan email yang sama untuk membuat tagihan dan melanjutkan pembayaran.',
            'code' => $guest->code,
            'status' => 'pending_account',
            'quoted_total_amount' => $quotedAmount,
        ], 201);
    }

    public function show(string $code)
    {
        $guest = GuestPackageOrder::query()->where('code', $code)->firstOrFail();
        if ($guest->status === 'pending' && ! $guest->expires_at->isFuture()) {
            $guest->update(['status' => 'expired']);
        }
        $payload = is_array($guest->payload) ? $guest->payload : [];
        $subjectIds = collect($payload['subjects'] ?? [])->pluck('curriculum_subject_id')->filter()->unique()->values();
        $subjectNames = CurriculumSubject::query()->whereKey($subjectIds)->pluck('name', 'id');
        $planName = PackagePlan::query()->whereKey($payload['package_plan_id'] ?? null)->value('name');

        return response()->json([
            'code' => $guest->code,
            'status' => $guest->status,
            'quoted_total_amount' => (float) $guest->quoted_total_amount,
            'email_hint' => $this->maskedEmail($guest->email),
            'expires_at' => $guest->expires_at,
            'summary' => [
                'package_name' => $planName,
                'education_level' => $payload['education_level'] ?? null,
                'grade' => $payload['grade'] ?? null,
                'learning_mode' => $payload['learning_mode'] ?? null,
                'participant_count' => (int) ($payload['participant_count'] ?? 1),
                'session_count' => collect($payload['subjects'] ?? [])->sum(fn ($item) => count($item['schedules'] ?? [])),
                'subjects' => $subjectIds->map(fn ($id) => $subjectNames[$id] ?? null)->filter()->values(),
            ],
        ]);
    }

    public function pendingForUser(Request $request)
    {
        $student = $request->user();
        abort_unless($student?->role === 'student' && $student->status === 'active', 403, 'Gunakan akun murid aktif.');
        abort_unless($student->email_verified_at, 403, 'Verifikasi email akun terlebih dahulu.');

        GuestPackageOrder::query()
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $orders = GuestPackageOrder::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $student->email)])
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->limit(5)
            ->get(['id', 'code', 'quoted_total_amount', 'expires_at', 'created_at']);

        // Email sudah terverifikasi dan sama, jadi draf aman ditautkan ke akun.
        // Status tetap "pending" sampai murid menyelesaikan klaim.
        if ($orders->isNotEmpty()) {
            GuestPackageOrder::query()
                ->whereIn('id', $orders->pluck('id'))
                ->whereNull('student_id')
                ->update(['student_id' => $student->id]);
        }

        return response()->json([
            'data' => $orders->map(fn (GuestPackageOrder $order) => [
                'code' => $order->code,
                'quoted_total_amount' => $order->quoted_total_amount,
                'expires_at' => $order->expires_at,
                'created_at' => $order->created_at,
            ])->values(),
        ]);
    }

    public function claim(Request $request, string $code, StudentPackageController $packages, HourlyRateService $rates, PackageCheckoutService $checkout, PrivateParticipantPricing $pricing)
    {
        $student = $request->user();
        abort_unless($student?->role === 'student' && $student->status === 'active', 403, 'Gunakan akun murid aktif.');
        abort_unless($student->email_verified_at, 403, 'Verifikasi email akun sebelum menghubungkan pesanan.');

        return DB::transaction(function () use ($request, $code, $packages, $rates, $checkout, $pricing, $student) {
            $guest = GuestPackageOrder::query()->where('code', $code)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($guest->email, mb_strtolower((string) $student->email)), 403, 'Email akun tidak sama dengan email pesanan.');
            if ($guest->status === 'claimed' && $guest->student_id === $student->id && $guest->result_snapshot) {
                return response()->json($guest->result_snapshot);
            }
            abort_unless($guest->status === 'pending' && $guest->expires_at->isFuture(), 422, 'Draf pesanan sudah tidak aktif.');
            $payload = $guest->payload;
            if ($payload['learning_mode'] === 'offline') {
                abort_unless($payload['location_consent'] ?? false, 422, 'Persetujuan lokasi diperlukan.');
                $student->update([
                    'address' => $payload['address'],
                    'maps_link' => $payload['maps_link'] ?? null,
                    'latitude' => $payload['latitude'],
                    'longitude' => $payload['longitude'],
                    'location_consent_at' => now(),
                ]);
            }
            $quoteRequest = Request::create('/api/guest/packages/quote', 'POST', [
                'package_plan_id' => $payload['package_plan_id'],
                'education_level' => $payload['education_level'],
                'learning_mode' => $payload['learning_mode'],
                'duration_hours' => $payload['duration_hours'],
                'participant_count' => $payload['participant_count'] ?? 1,
                'subjects' => collect($payload['subjects'])->map(fn ($item) => [
                    'curriculum_subject_id' => $item['curriculum_subject_id'],
                    'session_count' => count($item['schedules']),
                ])->all(),
            ]);
            $currentAmount = (int) ($packages->previewPromotion($quoteRequest, $rates, $checkout, $pricing)->getData(true)['total_amount'] ?? 0);
            abort_unless($currentAmount === (int) $guest->quoted_total_amount, 409, 'Harga berubah sejak draf dibuat. Hubungi admin atau buat draf baru sebelum pembayaran.');

            $request->merge($payload);
            $request->request->remove('promotion_code');
            $request->request->remove('promotion_claim_id');
            $request->request->remove('renewal_of_id');
            $result = $packages->store($request, $rates, $checkout, $pricing)->getData(true);
            $guest->update([
                'status' => 'claimed',
                'student_id' => $student->id,
                'learning_package_id' => $result['data']['id'] ?? null,
                'result_snapshot' => $result,
                'claimed_at' => now(),
            ]);

            return response()->json($result);
        }, 3);
    }

    private function maskedEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2).'***@'.$domain;
    }
}
