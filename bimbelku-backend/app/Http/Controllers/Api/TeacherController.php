<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CurriculumSubject;
use App\Models\Notification;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\TeacherPayoutRequest;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\BankAccountNameVerifier;
use App\Services\TeacherMatchingService;
use App\Services\TeacherOfferReleaseService;
use App\Support\EducationCatalog;
use App\Support\PublicMedia;
use App\Support\XenditPayoutChannelCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    // ==========================================
    // 1. DATA PROFIL GURU (DASHBOARD)
    // ==========================================
    public function getProfile(Request $request)
    {
        $user = Auth::user()->fresh();

        $profile = $user->teacherProfile;

        if (! $profile) {
            $profile = TeacherProfile::firstOrCreate(['user_id' => $user->id]);
        }

        $profile->load('subjects');

        $profileData = $profile->toArray();
        $inferredChannel = $profile->payout_channel_code ?: XenditPayoutChannelCatalog::inferCode($profile->bank_name);
        if (blank($profile->payout_channel_code) && filled($inferredChannel)) {
            $profile->forceFill(['payout_channel_code' => $inferredChannel])->save();
        }
        $profileData['whatsapp_number'] = $profile->whatsapp_number;
        $profileData['latitude'] = $profile->latitude;
        $profileData['longitude'] = $profile->longitude;
        $profileData['bank_name'] = $profile->bank_name;
        $profileData['payout_channel_code'] = $inferredChannel;
        $profileData['payout_channel_options'] = XenditPayoutChannelCatalog::options();
        $profileData['account_number'] = $profile->account_number;
        $profileData['account_name'] = $profile->account_name;
        $profileData['bank_account_changed_at'] = $profile->bank_account_changed_at;
        $profileData['payout_hold_until'] = $profile->payout_hold_until;
        $profileData['photo_url'] = PublicMedia::url($profile->photo);
        $profileData['cv_url'] = $profile->cv_file ? "/teachers/{$user->id}/documents/cv_file" : null;
        $profileData['identity_document_url'] = $profile->identity_document ? "/teachers/{$user->id}/documents/identity_document" : null;
        $profileData['live_selfie_url'] = $profile->live_selfie ? "/teachers/{$user->id}/documents/live_selfie" : null;
        $profileData['qualification_document_url'] = $profile->qualification_document ? "/teachers/{$user->id}/documents/qualification_document" : null;
        $profileData['certification_document_url'] = $profile->certification_document ? "/teachers/{$user->id}/documents/certification_document" : null;
        $profileData['profile_cover_url'] = $user->profile_cover_use_default
            ? null
            : PublicMedia::url($user->profile_cover);

        $user->subjects = $profile->subjects;

        return response()->json([
            'user' => $user,
            'profile' => $profileData,
        ]);
    }

    public function updateProfileCover(Request $request)
    {
        $request->validate([
            'profile_cover' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $path = $request->file('profile_cover')->store('profile_covers', 'public');
        try {
            $oldPath = DB::transaction(function () use ($user, $path) {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                $oldPath = $lockedUser->profile_cover;
                $lockedUser->profile_cover = $path;
                $lockedUser->profile_cover_use_default = false;
                $lockedUser->save();

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
            'message' => 'Sampul profil berhasil diperbarui.',
            'profile_cover_url' => PublicMedia::url($path),
        ]);
    }

    public function updateProfile(
        Request $request,
        TeacherOfferReleaseService $offerReleaseService,
        TeacherMatchingService $matchingService
    ) {
        $request->validate([
            'name' => ['nullable', 'string', 'max:255', 'regex:/\pL/u', 'not_regex:/\d/u'],
            'title' => ['nullable', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:255', 'regex:/\pL/u'],
            'experience' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:3000'],
            'whatsapp_number' => ['required', 'string', 'max:16', 'regex:/^\+?[0-9]{8,15}$/'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'max_travel_km' => ['nullable', 'integer', 'min:1', 'max:12'],
            'is_accepting_requests' => ['nullable', 'boolean'],
            'public_profile_enabled' => ['nullable', 'boolean'],
            'public_credentials' => ['nullable', 'string', 'max:300'],
            'public_degree' => ['nullable', 'string', 'max:120'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'profile_cover' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'cv_file' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
            'identity_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'live_selfie' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'qualification_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'certification_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'name.regex' => 'Nama lengkap wajib mengandung huruf.',
            'name.not_regex' => 'Nama lengkap tidak boleh memuat angka.',
            'location.regex' => 'Kota atau wilayah wajib mengandung huruf.',
            'whatsapp_number.regex' => 'Nomor WhatsApp/telepon harus berisi 8–15 angka.',
        ]);

        $user = Auth::user();
        $profile = $user->teacherProfile;

        if (! $profile) {
            $profile = new TeacherProfile;
            $profile->user_id = $user->id;
        }

        $requestedLatitude = $request->filled('latitude') ? (float) $request->latitude : null;
        $requestedLongitude = $request->filled('longitude') ? (float) $request->longitude : null;
        $matchingProfileChanged = false;
        $reverificationRequired = false;

        $profile->title = $request->title;
        $profile->location = $request->location;
        $profile->experience = $request->experience;
        $profile->bio = $request->bio;
        $profile->whatsapp_number = $request->whatsapp_number;
        if ($request->has('latitude')) {
            $profile->latitude = $request->filled('latitude') ? $request->latitude : null;
        }
        if ($request->has('longitude')) {
            $profile->longitude = $request->filled('longitude') ? $request->longitude : null;
        }
        if ($request->has('max_travel_km')) {
            $profile->max_travel_km = max(1, min(12, (int) $request->max_travel_km));
        }
        if ($request->has('is_accepting_requests')) {
            $profile->is_accepting_requests = filter_var($request->is_accepting_requests, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('public_credentials')) {
            $profile->public_credentials = trim((string) $request->public_credentials) ?: null;
        }
        if ($request->has('public_degree')) {
            $profile->public_degree = trim((string) $request->public_degree) ?: null;
        }
        if ($request->has('public_profile_enabled')) {
            $publicEnabled = filter_var($request->public_profile_enabled, FILTER_VALIDATE_BOOLEAN);
            if ($publicEnabled && ! $profile->public_profile_enabled) {
                $profile->public_profile_consent_at = now();
            }
            if (! $publicEnabled) {
                $profile->public_directory_approved_at = null;
            }
            $profile->public_profile_enabled = $publicEnabled;
        }

        $verificationDocuments = [
            'identity_document' => 'teacher_identity',
            'live_selfie' => 'teacher_selfies',
            'qualification_document' => 'teacher_qualifications',
            'certification_document' => 'teacher_certifications',
        ];
        $oldFiles = [];
        $newFiles = [];

        try {
            if ($request->hasFile('photo')) {
                $oldFiles['photo'] = ['public', $profile->photo];
                $newFiles['photo'] = ['public', $request->file('photo')->store('photos', 'public')];
                $profile->photo = $newFiles['photo'][1];
            }
            if ($request->hasFile('profile_cover')) {
                $oldFiles['profile_cover'] = ['public', $user->profile_cover];
                $newFiles['profile_cover'] = [
                    'public',
                    $request->file('profile_cover')->store('profile_covers', 'public'),
                ];
            }
            if ($request->hasFile('cv_file')) {
                $oldFiles['cv_file'] = ['local', $profile->cv_file];
                $newFiles['cv_file'] = ['local', $request->file('cv_file')->store('cvs', 'local')];
                $profile->cv_file = $newFiles['cv_file'][1];
            }
            foreach ($verificationDocuments as $field => $directory) {
                if (! $request->hasFile($field)) {
                    continue;
                }

                $oldFiles[$field] = ['local', $profile->{$field}];
                $newFiles[$field] = ['local', $request->file($field)->store($directory, 'local')];
                $profile->{$field} = $newFiles[$field][1];
            }

            $verificationChanged = collect($verificationDocuments)
                ->keys()
                ->contains(fn (string $field) => array_key_exists($field, $newFiles))
                || array_key_exists('cv_file', $newFiles);
            DB::transaction(function () use (
                $request,
                &$user,
                &$profile,
                $verificationChanged,
                $requestedLatitude,
                $requestedLongitude,
                $newFiles,
                &$reverificationRequired,
                &$matchingProfileChanged
            ) {
                $lockedUser = User::query()
                    ->lockForUpdate()
                    ->findOrFail($user->id);
                $lockedProfile = TeacherProfile::query()
                    ->where('user_id', $lockedUser->id)
                    ->lockForUpdate()
                    ->first() ?? new TeacherProfile(['user_id' => $lockedUser->id]);

                $identityChanged = $request->filled('name')
                    && trim((string) $request->name) !== trim((string) $lockedUser->name);
                $reverificationRequired = ($verificationChanged || $identityChanged)
                    && $lockedUser->status === 'active';
                $matchingProfileChanged = (
                    $request->has('latitude')
                    && $requestedLatitude !== $lockedProfile->latitude
                ) || (
                    $request->has('longitude')
                    && $requestedLongitude !== $lockedProfile->longitude
                ) || (
                    $request->has('max_travel_km')
                    && (int) $request->max_travel_km !== (int) $lockedProfile->max_travel_km
                ) || (
                    $request->has('is_accepting_requests')
                    && filter_var($request->is_accepting_requests, FILTER_VALIDATE_BOOLEAN)
                        !== (bool) $lockedProfile->is_accepting_requests
                );

                $profileChanges = $profile->getDirty();
                $lockedProfile->forceFill($profileChanges);
                if (array_intersect(
                    array_keys($profileChanges),
                    ['title', 'experience', 'bio', 'public_credentials', 'public_degree', 'photo']
                )) {
                    $lockedProfile->public_directory_approved_at = null;
                }
                if ($reverificationRequired) {
                    $lockedProfile->verified_at = null;
                    $lockedProfile->verified_by = null;
                    $lockedProfile->public_directory_approved_at = null;
                    $lockedProfile->is_accepting_requests = false;
                    $lockedUser->status = 'pending';
                }

                $lockedProfile->save();
                if ($request->filled('name')) {
                    $lockedUser->name = $request->name;
                }
                $lockedUser->phone = trim((string) $request->whatsapp_number);
                if (isset($newFiles['profile_cover'])) {
                    $lockedUser->profile_cover = $newFiles['profile_cover'][1];
                    $lockedUser->profile_cover_use_default = false;
                }
                $lockedUser->save();

                if ($reverificationRequired) {
                    $lockedUser->tokens()->delete();
                }

                $user = $lockedUser;
                $profile = $lockedProfile;
            }, 3);
        } catch (\Throwable $exception) {
            foreach ($newFiles as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }

        foreach ($oldFiles as [$disk, $path]) {
            if (! $path) {
                continue;
            }
            Storage::disk($disk)->delete($path);
            if ($disk === 'local') {
                Storage::disk('public')->delete($path);
            }
        }

        if ($reverificationRequired || $matchingProfileChanged) {
            $offerReleaseService
                ->releaseForTeacher(
                    (int) $user->id,
                    $reverificationRequired
                        ? 'Profil tutor masuk pemeriksaan ulang'
                        : 'Kelayakan lokasi atau penerimaan tutor berubah'
                )
                ->each(fn ($bookingRequest) => $matchingService->dispatchNextOffer($bookingRequest));
        }

        return response()->json([
            'message' => $reverificationRequired
                ? 'Identitas atau dokumen diperbarui. Akun masuk pemeriksaan ulang dan sesi login ditutup.'
                : 'Profil berhasil disimpan.',
            'data' => $profile,
            'reverification_required' => $reverificationRequired,
        ]);
    }

    public function syncSubjects(
        Request $request,
        TeacherOfferReleaseService $offerReleaseService,
        TeacherMatchingService $matchingService
    ) {
        $validated = $request->validate([
            'subjects' => ['required', 'array', 'size:1'],
            'subjects.*.name' => ['required', 'string', 'max:120'],
            'subjects.*.levels' => ['required', 'array', 'min:1'],
            'subjects.*.levels.*' => ['required', Rule::in(EducationCatalog::LEVELS)],
            'subjects.*.is_online' => ['required', 'boolean'],
            'subjects.*.is_offline' => ['required', 'boolean'],
            'subjects.*.is_private_active' => ['sometimes', 'boolean'],
            'subjects.*.is_group_active' => ['sometimes', 'boolean'],
        ]);

        $normalizedSubjects = [];
        $seenCatalogIds = [];
        foreach ($validated['subjects'] as $index => $item) {
            $name = trim((string) $item['name']);
            $levels = array_values(array_unique($item['levels']));
            sort($levels);

            if (! $item['is_online'] && ! $item['is_offline']) {
                return response()->json([
                    'message' => 'Setiap mata pelajaran harus memiliki sedikitnya satu mode mengajar aktif.',
                    'errors' => ["subjects.{$index}.is_online" => ['Aktifkan mode online atau offline.']],
                ], 422);
            }

            $catalogSubject = CurriculumSubject::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();
            if (! $catalogSubject) {
                return response()->json([
                    'message' => 'Pilih seluruh mata pelajaran dari katalog aktif.',
                    'errors' => ["subjects.{$index}.name" => ['Mata pelajaran tidak tersedia pada katalog aktif.']],
                ], 422);
            }
            if (array_diff($levels, $catalogSubject->education_levels ?? [])) {
                return response()->json([
                    'message' => 'Jenjang yang dipilih tidak tersedia pada salah satu mata pelajaran.',
                    'errors' => ["subjects.{$index}.levels" => ['Jenjang tidak cocok dengan mata pelajaran.']],
                ], 422);
            }
            if (isset($seenCatalogIds[$catalogSubject->id])) {
                return response()->json([
                    'message' => 'Mata pelajaran tutor tidak boleh duplikat.',
                    'errors' => ['subjects' => ['Pilih tepat 1 mata pelajaran.']],
                ], 422);
            }
            $seenCatalogIds[$catalogSubject->id] = true;

            $normalizedSubjects[] = [
                'name' => $catalogSubject->name,
                'curriculum_subject_id' => (int) $catalogSubject->id,
                'levels' => $levels,
                'is_online' => (bool) $item['is_online'],
                'is_offline' => (bool) $item['is_offline'],
                'is_private_active' => (bool) ($item['is_private_active'] ?? true),
                'is_group_active' => (bool) ($item['is_group_active'] ?? false),
            ];
        }

        $user = Auth::user();
        $profile = $user->teacherProfile ?: TeacherProfile::firstOrCreate(['user_id' => $user->id]);
        $reverificationRequired = false;
        $matchingEligibilityChanged = false;

        DB::transaction(function () use (
            $user,
            $profile,
            $normalizedSubjects,
            &$reverificationRequired,
            &$matchingEligibilityChanged
        ) {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);
            $lockedProfile = TeacherProfile::query()
                ->lockForUpdate()
                ->findOrFail($profile->id);

            $existing = $lockedProfile->subjects()
                ->lockForUpdate()
                ->get()
                ->map(function (TeacherSubject $subject) {
                    $levels = $subject->levels ?? [];
                    sort($levels);

                    return [
                        'curriculum_subject_id' => (int) ($subject->curriculum_subject_id ?? 0),
                        'name' => mb_strtolower(trim((string) $subject->name)),
                        'levels' => array_values($levels),
                        'is_online' => (bool) $subject->is_online,
                        'is_offline' => (bool) $subject->is_offline,
                        'is_private_active' => (bool) $subject->is_private_active,
                        'is_group_active' => (bool) ($subject->is_group_active ?? false),
                    ];
                })
                ->sortBy(fn ($subject) => sprintf('%010d:%s', $subject['curriculum_subject_id'], $subject['name']))
                ->values()
                ->all();

            $incoming = collect($normalizedSubjects)
                ->map(fn ($subject) => [
                    'curriculum_subject_id' => (int) $subject['curriculum_subject_id'],
                    'name' => mb_strtolower(trim((string) $subject['name'])),
                    'levels' => array_values($subject['levels']),
                    'is_online' => (bool) $subject['is_online'],
                    'is_offline' => (bool) $subject['is_offline'],
                    'is_private_active' => (bool) $subject['is_private_active'],
                    'is_group_active' => (bool) $subject['is_group_active'],
                ])
                ->sortBy(fn ($subject) => sprintf('%010d:%s', $subject['curriculum_subject_id'], $subject['name']))
                ->values()
                ->all();

            $existingCompetency = array_map(fn ($subject) => [
                'curriculum_subject_id' => $subject['curriculum_subject_id'],
                'name' => $subject['name'],
                'levels' => $subject['levels'],
            ], $existing);
            $incomingCompetency = array_map(fn ($subject) => [
                'curriculum_subject_id' => $subject['curriculum_subject_id'],
                'name' => $subject['name'],
                'levels' => $subject['levels'],
            ], $incoming);

            $competencyChanged = $existingCompetency !== $incomingCompetency;
            $matchingEligibilityChanged = $competencyChanged || $existing !== $incoming;
            $reverificationRequired = $lockedUser->status === 'active' && $competencyChanged;

            TeacherSubject::where('teacher_profile_id', $lockedProfile->id)->delete();
            foreach ($normalizedSubjects as $subject) {
                TeacherSubject::create([
                    'teacher_profile_id' => $lockedProfile->id,
                    'name' => $subject['name'],
                    'curriculum_subject_id' => $subject['curriculum_subject_id'],
                    'levels' => $subject['levels'],
                    'is_active' => true,
                    'is_online' => $subject['is_online'],
                    'is_offline' => $subject['is_offline'],
                    'is_private_active' => $subject['is_private_active'],
                    'is_group_active' => $subject['is_group_active'],
                ]);
            }

            $expertise = mb_substr(implode(', ', array_column($normalizedSubjects, 'name')), 0, 255);
            $lockedProfile->update([
                'expertise' => $expertise,
                'public_directory_approved_at' => $competencyChanged
                    ? null
                    : $lockedProfile->public_directory_approved_at,
                'is_accepting_requests' => $reverificationRequired
                    ? false
                    : $lockedProfile->is_accepting_requests,
                'verified_at' => $reverificationRequired ? null : $lockedProfile->verified_at,
                'verified_by' => $reverificationRequired ? null : $lockedProfile->verified_by,
            ]);

            if ($reverificationRequired) {
                $lockedUser->update(['status' => 'pending']);
                $lockedUser->tokens()->delete();
            }
        }, 3);

        Cache::forget('learning_catalog.payload');

        if ($matchingEligibilityChanged) {
            $offerReleaseService
                ->releaseForTeacher(
                    (int) $user->id,
                    'Kompetensi atau mode mengajar tutor berubah'
                )
                ->each(fn ($bookingRequest) => $matchingService->dispatchNextOffer($bookingRequest));
        }

        return response()->json([
            'message' => $reverificationRequired
                ? 'Kompetensi mengajar diperbarui. Akun masuk pemeriksaan ulang dan sesi login ditutup.'
                : 'Satu mata pelajaran utama dan jenjang berhasil disimpan. Harga ditentukan oleh admin.',
            'reverification_required' => $reverificationRequired,
        ]);
    }

    public function updateBank(Request $request)
    {
        $validated = $request->validate([
            'payout_channel_code' => ['nullable', 'required_without:bank_name', 'string', Rule::in(XenditPayoutChannelCatalog::codes())],
            'bank_name' => ['nullable', 'required_without:payout_channel_code', 'string', 'max:100', 'regex:/\pL/u'],
            'account_number' => ['required', 'string', 'min:8', 'max:20', 'regex:/^[0-9]+$/'],
            'account_name' => ['required', 'string', 'max:150', 'regex:/\pL/u', 'not_regex:/\d/u'],
            'current_password' => ['required', 'string', 'max:200'],
        ], [
            'account_number.min' => 'Nomor rekening atau e-wallet minimal 8 digit.',
            'account_number.max' => 'Nomor rekening atau e-wallet maksimal 20 digit.',
            'account_number.regex' => 'Nomor rekening atau e-wallet hanya boleh berisi angka.',
            'account_name.regex' => 'Nama pemilik rekening wajib mengandung huruf.',
            'account_name.not_regex' => 'Nama pemilik rekening tidak boleh memuat angka.',
        ]);

        $user = Auth::user();
        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Kata sandi akun tidak sesuai.'], 422);
        }
        $normalizeName = static fn (string $name): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
        if ($normalizeName($validated['account_name']) !== $normalizeName((string) $user->name)) {
            return response()->json(['message' => 'Nama pemilik rekening harus sama dengan nama akun tutor. Pastikan nama akun sesuai KTP sebelum melanjutkan.'], 422);
        }

        $holdHours = min(
            72,
            max(1, (int) (Setting::where('key', 'bank_change_hold_hours')->value('value') ?? 24))
        );
        $normalizedAccount = preg_replace('/\D+/', '', $validated['account_number']) ?? '';
        $channelCode = $validated['payout_channel_code'] ?? XenditPayoutChannelCatalog::inferCode($validated['bank_name'] ?? null);
        abort_unless($channelCode && ($channel = XenditPayoutChannelCatalog::get($channelCode)), 422, 'Bank belum mendukung pencairan otomatis. Pilih bank dari daftar yang tersedia.');
        $holdUntil = now()->addHours($holdHours);

        $result = DB::transaction(function () use (
            $user,
            $validated,
            $normalizedAccount,
            $holdUntil, $channel,
            $channelCode
        ) {
            $lockedUser = $user->newQuery()->lockForUpdate()->findOrFail($user->id);
            if (! Hash::check($validated['current_password'], $lockedUser->password)) {
                abort(422, 'Kata sandi akun tidak sesuai.');
            }

            $profile = TeacherProfile::query()
                ->firstOrCreate(['user_id' => $lockedUser->id]);
            $lockedProfile = TeacherProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $fingerprint = hash_hmac('sha256', implode('|', [
                $channelCode,
                $normalizedAccount,
                mb_strtolower(trim($validated['account_name'])),
            ]), (string) config('app.key'));
            $changed = ! hash_equals(
                (string) ($lockedProfile->bank_account_fingerprint ?? ''),
                $fingerprint
            );

            if ($changed) {
                $lockedProfile->forceFill([
                    'bank_name' => $channel['label'],
                    'payout_channel_code' => $channelCode,
                    'account_number' => trim($validated['account_number']),
                    'account_name' => trim($validated['account_name']),
                    'bank_account_fingerprint' => $fingerprint,
                    'bank_account_changed_at' => now(),
                    'payout_hold_until' => $holdUntil,
                    'bank_details_version' => (int) $lockedProfile->bank_details_version + 1,
                ])->save();
            }

            return [
                'changed' => $changed,
                'payout_hold_until' => $changed
                    ? $holdUntil
                    : $lockedProfile->payout_hold_until,
            ];
        }, 3);

        if ($result['changed']) {
            Notification::create([
                'user_id' => $user->id,
                'title' => 'Rekening pencairan diubah',
                'message' => 'Pencairan ditahan sementara untuk melindungi saldo setelah perubahan rekening.',
                'type' => 'warning',
                'target_url' => '/guru/rekening',
            ]);
            User::query()
                ->where('role', 'admin')
                ->where('status', 'active')
                ->pluck('id')
                ->each(fn ($adminId) => Notification::create([
                    'user_id' => $adminId,
                    'title' => 'Perubahan rekening tutor',
                    'message' => "Rekening pencairan {$user->name} berubah. Pencairan ditahan sementara.",
                    'type' => 'warning',
                    'target_url' => '/admin/finance?tab=payouts',
                ]));
        }

        return response()->json([
            'message' => $result['changed']
                ? 'Rekening disimpan. Pencairan ditahan sementara demi keamanan.'
                : 'Data rekening tidak berubah.',
            'payout_hold_until' => $result['payout_hold_until'],
        ]);
    }

    // ==========================================
    // 3. KEUANGAN & GAJI
    // ==========================================
    public function getSalaryData(Request $request)
    {
        $user = $request->user();
        Carbon::setLocale('id');
        $currentAdminFee = Setting::where('key', 'admin_fee')->value('value') ?? 20;
        $profile = TeacherProfile::query()->where('user_id', $user->id)->first();
        $completedBookings = Booking::query()
            ->where('teacher_id', $user->id)
            ->where('status', 'completed')
            ->whereDoesntHave('disputes', fn ($query) => $query->whereNull('resolved_at'));
        $balanceRows = (clone $completedBookings)
            ->selectRaw("COALESCE(SUM(CASE WHEN payout_status = 'locked' THEN teacher_net_amount ELSE 0 END), 0) AS held")
            ->selectRaw('COALESCE(SUM(CASE WHEN teacher_net_amount - teacher_paid_amount - teacher_reserved_amount > 0 THEN teacher_net_amount - teacher_paid_amount - teacher_reserved_amount ELSE 0 END), 0) AS available')
            ->selectRaw('COALESCE(SUM(teacher_reserved_amount), 0) AS requested')
            ->selectRaw('COALESCE(SUM(teacher_paid_amount), 0) AS paid')
            ->selectRaw('COALESCE(SUM(gross_amount), 0) AS total_gross')
            ->selectRaw('COALESCE(SUM(gross_amount - teacher_net_amount), 0) AS total_commission')
            ->first();
        $paidPayouts = Payout::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->latest()
            ->limit(100)
            ->get();
        $totalWithdrawn = Payout::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('amount');
        $readySessionCount = (clone $completedBookings)
            ->whereRaw('(teacher_net_amount - teacher_paid_amount - teacher_reserved_amount) > 0')
            ->count();
        $readyBookings = (clone $completedBookings)
            ->whereRaw('(teacher_net_amount - teacher_paid_amount - teacher_reserved_amount) > 0')
            ->with('bookingRequest:id,subject_name')
            ->oldest('completed_at')
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'subject' => $booking->bookingRequest?->subject_name ?? 'Bimbingan',
                'completed_at' => $booking->completed_at,
                'gross_amount' => (float) $booking->gross_amount,
                'commission_amount' => max(0, (float) $booking->gross_amount - (float) $booking->teacher_net_amount),
                'net_amount' => round(max(0, (float) $booking->teacher_net_amount - (float) $booking->teacher_paid_amount - (float) $booking->teacher_reserved_amount), 2),
                'earned_amount' => (float) $booking->teacher_net_amount,
            ]);
        $newStudentsWeek = Booking::query()
            ->where('teacher_id', $user->id)
            ->where('start_at', '>=', now()->startOfWeek())
            ->withCount('participants')
            ->get()
            ->sum('participants_count');
        $totalStudents = DB::table('booking_participants')
            ->join('bookings', 'booking_participants.booking_id', '=', 'bookings.id')
            ->where('bookings.teacher_id', $user->id)
            ->whereIn('booking_participants.status', ['paid', 'approved', 'no_show_confirmed'])
            ->distinct('booking_participants.student_id')
            ->count('booking_participants.student_id');

        $formattedHistory = $paidPayouts->map(fn ($payout) => [
            'id' => $payout->id,
            'period' => $payout->period,
            'amount' => $payout->amount,
            'transfer_date' => $payout->created_at->translatedFormat('d M Y'),
            'total_students' => $payout->total_classes,
            'status' => 'Lunas',
            'proof_url' => $payout->proof_url ? "payouts/{$payout->id}/proof" : null,
        ]);

        return response()->json([
            'current_period' => Carbon::now()->translatedFormat('F Y'),
            'balance' => round((float) ($balanceRows?->available ?? 0)),
            'pending_amount' => round((float) ($balanceRows?->available ?? 0)),
            'balances' => [
                'held' => round((float) ($balanceRows?->held ?? 0)),
                'available' => round((float) ($balanceRows?->available ?? 0)),
                'requested' => round((float) ($balanceRows?->requested ?? 0)),
                'paid' => round((float) ($balanceRows?->paid ?? 0)),
            ],
            'totals' => [
                'gross' => round((float) ($balanceRows?->total_gross ?? 0)),
                'commission' => round((float) ($balanceRows?->total_commission ?? 0)),
                'net' => round((float) ($balanceRows?->total_gross ?? 0) - (float) ($balanceRows?->total_commission ?? 0)),
            ],
            'new_students_week' => $newStudentsWeek,
            'student_count_week' => $newStudentsWeek,
            'total_students' => $totalStudents,
            'total_income' => round((float) ($balanceRows?->total_gross ?? 0) - (float) ($balanceRows?->total_commission ?? 0)),
            'total_withdrawn' => round($totalWithdrawn),
            'history' => $formattedHistory,
            'share_percent' => 100 - $currentAdminFee,
            'withholding_tax_percent' => min(100, max(0, (float) (Setting::query()->where('key', 'teacher_withholding_tax_percent')->value('value') ?? 0))),
            'bank_name_validation_available' => app(BankAccountNameVerifier::class)->available(),
            'ready_sessions' => $readySessionCount,
            'ready_bookings' => $readyBookings,
            'bank' => [
                'is_complete' => filled($profile?->bank_name)
                    && filled($profile?->account_number)
                    && filled($profile?->account_name),
                'bank_name' => $profile?->bank_name,
                'account_name' => $profile?->account_name,
                'account_number_masked' => $profile?->account_number
                    ? str_repeat('•', max(0, strlen(preg_replace('/\D+/', '', $profile->account_number)) - 4))
                        .substr(preg_replace('/\D+/', '', $profile->account_number), -4)
                    : null,
                'payout_hold_until' => $profile?->payout_hold_until,
            ],
            'payout_requests' => TeacherPayoutRequest::query()
                ->where('teacher_id', $user->id)
                ->latest('requested_at')
                ->limit(50)
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'net_amount' => (float) $item->net_amount,
                    'gross_amount' => (float) $item->gross_amount,
                    'commission_amount' => (float) $item->commission_amount,
                    'requested_amount' => (float) ($item->requested_amount ?? $item->net_amount),
                    'tax_amount' => (float) ($item->tax_amount ?? 0),
                    'status' => $item->status,
                    'requested_at' => $item->requested_at,
                    'processed_at' => $item->processed_at,
                    'review_notes' => $item->review_notes,
                ]),
        ]);
    }
}
