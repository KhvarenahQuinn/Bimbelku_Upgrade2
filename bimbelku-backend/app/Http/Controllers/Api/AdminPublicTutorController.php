<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurriculumSubject;
use App\Models\TeacherProfile;
use App\Services\UploadSecurityService;
use App\Support\PublicMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminPublicTutorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'visible', 'awaiting_review', 'not_visible', 'no_consent', 'not_verified'])],
            'group' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20])],
        ]);
        $status = $filters['status'] ?? 'all';
        $perPage = (int) ($filters['per_page'] ?? 20);
        $query = TeacherProfile::query()
            ->with(['user:id,name,role,status', 'subjects' => fn ($subjects) => $subjects->where('is_active', true)->orderBy('name')])
            ->whereHas('user', fn ($user) => $user->where('role', 'teacher'));

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($matches) use ($search) {
                $like = '%'.$search.'%';
                $matches->whereHas('user', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhere('public_display_name', 'like', $like)
                    ->orWhere('public_degree', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('public_credentials', 'like', $like)
                    ->orWhereHas('subjects', fn ($subjects) => $subjects->where('name', 'like', $like));
            });
        }

        $group = trim((string) ($filters['group'] ?? ''));
        if ($group !== '') {
            $subjectIds = CurriculumSubject::query()->where('is_active', true)
                ->where('group_name', $group)->pluck('id');
            $query->whereHas('subjects', fn ($subjects) => $subjects->where('is_active', true)
                ->whereIn('curriculum_subject_id', $subjectIds));
        }

        $eligible = fn ($profiles) => $profiles
            ->whereNotNull('verified_at')
            ->where('public_profile_enabled', true)
            ->whereNotNull('public_profile_consent_at')
            ->whereHas('user', fn ($user) => $user->where('status', 'active'));
        if ($status === 'visible') {
            $eligible($query)->whereNotNull('public_directory_approved_at');
        } elseif ($status === 'awaiting_review') {
            $eligible($query)->whereNull('public_directory_approved_at');
        } elseif ($status === 'no_consent') {
            $query->where(fn ($profiles) => $profiles->where('public_profile_enabled', false)
                ->orWhereNull('public_profile_consent_at'));
        } elseif ($status === 'not_verified') {
            $query->where(fn ($profiles) => $profiles->whereNull('verified_at')
                ->orWhereHas('user', fn ($user) => $user->where('status', '!=', 'active')));
        } elseif ($status === 'not_visible') {
            $query->where(fn ($profiles) => $profiles->whereNull('public_directory_approved_at')
                ->orWhereNull('verified_at')
                ->orWhere('public_profile_enabled', false)
                ->orWhereNull('public_profile_consent_at')
                ->orWhereHas('user', fn ($user) => $user->where('status', '!=', 'active')));
        }

        $profiles = $query->orderByDesc('public_directory_approved_at')
            ->orderByDesc('verified_at')->orderByDesc('id')->paginate($perPage);

        $payload = $profiles->through(fn (TeacherProfile $profile) => [
            'id' => $profile->id,
            'name' => $profile->user?->name,
            'public_display_name' => $profile->public_display_name,
            'public_degree' => $profile->public_degree,
            'account_status' => $profile->user?->status,
            'verified' => $profile->verified_at !== null,
            'consented' => $profile->public_profile_enabled && $profile->public_profile_consent_at !== null,
            'approved' => $profile->public_directory_approved_at !== null,
            'title' => $profile->title,
            'credentials' => $profile->public_credentials,
            'photo_url' => PublicMedia::url($profile->photo),
            'subjects' => $profile->subjects->pluck('name')->filter()->unique()->values(),
        ])->toArray();
        $payload['groups'] = CurriculumSubject::query()->where('is_active', true)
            ->whereNotNull('group_name')->distinct()->orderBy('group_name')->pluck('group_name')
            ->filter()->values();

        return response()->json($payload);
    }

    public function update(Request $request, TeacherProfile $teacherProfile): JsonResponse
    {
        $data = $request->validate(['approved' => ['required', 'boolean']]);
        $approved = (bool) $data['approved'];

        DB::transaction(function () use ($teacherProfile, $approved): void {
            $profile = TeacherProfile::query()->lockForUpdate()->findOrFail($teacherProfile->id);
            $profile->load('user');
            abort_unless($profile->user?->role === 'teacher', 404);
            if ($approved) {
                abort_unless($profile->user->status === 'active' && $profile->verified_at !== null, 422,
                    'Akun tutor harus aktif dan sudah terverifikasi sebelum profil ditayangkan.');
                abort_unless($profile->public_profile_enabled && $profile->public_profile_consent_at !== null, 422,
                    'Tutor belum menyetujui publikasi profil. Minta tutor mengaktifkan izin di profilnya.');
            }
            $profile->public_directory_approved_at = $approved ? now() : null;
            $profile->save();
        });

        return response()->json([
            'message' => $approved ? 'Tutor ditambahkan ke halaman Kenali Tutor.' : 'Tutor disembunyikan dari halaman Kenali Tutor.',
            'approved' => $approved,
        ]);
    }

    public function updatePhoto(Request $request, TeacherProfile $teacherProfile, UploadSecurityService $uploads): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'photo_consent_confirmed' => ['required', 'accepted'],
        ]);

        $profile = TeacherProfile::query()->with('user')->findOrFail($teacherProfile->id);
        abort_unless($profile->user?->role === 'teacher', 404);
        abort_unless($profile->public_profile_enabled && $profile->public_profile_consent_at,
            422, 'Tutor harus memberi izin profil publik sebelum foto katalog dapat diunggah.');

        $file = $request->file('photo');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $newPath = $uploads->store($file, 'photos/public_tutors', 'public');
        $oldPath = null;
        try {
            DB::transaction(function () use ($teacherProfile, $newPath, &$oldPath): void {
                $locked = TeacherProfile::query()->lockForUpdate()->findOrFail($teacherProfile->id);
                abort_unless($locked->public_profile_enabled && $locked->public_profile_consent_at,
                    422, 'Izin profil publik tutor sudah dicabut. Foto tidak disimpan.');
                $oldPath = $locked->photo;
                $locked->photo = $newPath;
                $locked->save();
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($newPath);
            throw $exception;
        }

        if (is_string($oldPath) && str_starts_with($oldPath, 'photos/public_tutors/')) {
            Storage::disk('public')->delete($oldPath);
        }
        $request->attributes->set('admin_audit_target_override', $profile);

        return response()->json([
            'message' => 'Foto tutor publik berhasil diperbarui.',
            'photo_url' => PublicMedia::url($newPath),
        ]);
    }

    public function updateIdentity(Request $request, TeacherProfile $teacherProfile): JsonResponse
    {
        $data = $request->validate([
            'public_display_name' => ['nullable', 'string', 'max:100'],
            'public_degree' => ['nullable', 'string', 'max:120'],
            'identity_confirmed' => ['required', 'accepted'],
        ]);

        $profile = TeacherProfile::query()->with('user')->findOrFail($teacherProfile->id);
        abort_unless($profile->user?->role === 'teacher', 404);
        abort_unless($profile->public_profile_enabled && $profile->public_profile_consent_at,
            422, 'Tutor harus memberi izin profil publik sebelum nama dan gelar ditampilkan.');

        $profile->public_display_name = trim((string) ($data['public_display_name'] ?? '')) ?: null;
        $profile->public_degree = trim((string) ($data['public_degree'] ?? '')) ?: null;
        $profile->save();
        $request->attributes->set('admin_audit_target_override', $profile);

        return response()->json([
            'message' => 'Nama dan gelar publik tutor berhasil disimpan.',
            'public_display_name' => $profile->public_display_name,
            'public_degree' => $profile->public_degree,
        ]);
    }
}
