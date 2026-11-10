<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherProfile;
use App\Models\WebsiteSection;
use App\Models\WebsiteSetting;
use App\Models\WebsiteTutorGallery;
use App\Support\PublicMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebsiteTutorGalleryController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        $entries = WebsiteTutorGallery::query()
            ->with('teacherProfile.user')
            ->where('is_visible', true)
            ->where('consent_source', '!=', '')
            ->where(function ($query) {
                $query->whereNull('teacher_profile_id')
                    ->orWhereHas('teacherProfile', fn ($profile) => $profile
                        ->whereNotNull('verified_at')
                        ->whereNotNull('public_directory_approved_at')
                        ->where('public_profile_enabled', true)
                        ->whereNotNull('public_profile_consent_at')
                        ->whereHas('user', fn ($user) => $user->where('role', 'teacher')->where('status', 'active')));
            })
            ->orderBy('sort_order')->orderBy('id')
            ->limit(20)->get();

        return response()->json($entries->map(fn (WebsiteTutorGallery $entry) => [
            'id' => $entry->id,
            'display_name' => $entry->display_name,
            'degree' => $entry->degree,
            'description' => $entry->description,
            'photo_url' => PublicMedia::url($entry->photo_path ?: $entry->teacherProfile?->photo),
        ])->values());
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20])],
        ]);
        $entries = WebsiteTutorGallery::query()
            ->with('teacherProfile.user')
            ->orderBy('sort_order')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return response()->json($entries->through(fn (WebsiteTutorGallery $entry) => $this->payload($entry)));
    }

    public function candidates(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = TeacherProfile::query()
            ->with('user:id,name,role,status')
            ->whereNotNull('verified_at')
            ->where('public_profile_enabled', true)
            ->whereNotNull('public_profile_consent_at')
            ->whereNotNull('public_directory_approved_at')
            ->whereHas('user', fn ($user) => $user->where('role', 'teacher')->where('status', 'active'));
        $search = trim((string) ($data['q'] ?? ''));
        if ($search !== '') {
            $query->where(fn ($matches) => $matches
                ->whereHas('user', fn ($user) => $user->where('name', 'like', '%'.$search.'%'))
                ->orWhere('public_display_name', 'like', '%'.$search.'%')
                ->orWhere('expertise', 'like', '%'.$search.'%')
                ->orWhere('public_degree', 'like', '%'.$search.'%')
                ->orWhere('public_credentials', 'like', '%'.$search.'%'));
        }

        return response()->json($query->orderBy('id')->limit(20)->get()
            ->map(fn (TeacherProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->public_display_name ?: $profile->user?->name,
                'degree' => $profile->public_degree,
                'subject' => $profile->expertise,
                'description' => $profile->public_credentials ?: $profile->title ?: $profile->expertise,
                'photo_url' => PublicMedia::url($profile->photo),
            ])->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $entry = new WebsiteTutorGallery;
        $this->fillEntry($entry, $data, $request);
        $entry->sort_order = (int) (WebsiteTutorGallery::query()->max('sort_order') ?? 0) + 1;
        $entry->save();

        return response()->json(['message' => 'Kartu tutor berhasil ditambahkan.', 'entry' => $this->payload($entry->load('teacherProfile'))], 201);
    }

    public function importLegacy(): JsonResponse
    {
        $settings = WebsiteSetting::query()->first();
        $content = WebsiteSection::query()->where('section_key', 'trust')->first()?->content ?? [];
        $titles = is_array($content['trust_media_titles'] ?? null) ? $content['trust_media_titles'] : [];
        $notes = is_array($content['trust_media_notes'] ?? null) ? $content['trust_media_notes'] : [];
        $created = 0;
        foreach (range(1, 6) as $number) {
            $path = $settings?->{'trust_image_'.$number.'_path'};
            if (blank($path) || WebsiteTutorGallery::query()->where('photo_path', $path)->exists()) {
                continue;
            }
            WebsiteTutorGallery::query()->create([
                'display_name' => trim((string) ($titles[$number - 1] ?? '')) ?: 'Tutor BimbelKu',
                'degree' => '',
                'description' => trim((string) ($notes[$number - 1] ?? '')) ?: null,
                'photo_path' => $path,
                'consent_source' => '',
                'is_visible' => false,
                'sort_order' => (int) (WebsiteTutorGallery::query()->max('sort_order') ?? 0) + 1,
            ]);
            $created++;
        }

        return response()->json([
            'message' => $created ? $created.' kartu lama diimpor sebagai draf. Isi gelar dan sumber izin sebelum menampilkannya.' : 'Tidak ada foto lama baru yang perlu diimpor.',
            'created' => $created,
        ]);
    }

    public function update(Request $request, WebsiteTutorGallery $entry): JsonResponse
    {
        $data = $this->validated($request);
        $this->fillEntry($entry, $data, $request);
        $entry->save();
        $request->attributes->set('admin_audit_target_override', $entry);

        return response()->json(['message' => 'Kartu tutor berhasil diperbarui.', 'entry' => $this->payload($entry->load('teacherProfile'))]);
    }

    public function destroy(Request $request, WebsiteTutorGallery $entry): JsonResponse
    {
        $entry->delete();
        $request->attributes->set('admin_audit_target_override', $entry);

        return response()->json(['message' => 'Kartu tutor diarsipkan dan tidak lagi tampil.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'teacher_profile_id' => ['nullable', 'integer', 'exists:teacher_profiles,id'],
            'display_name' => ['required', 'string', 'max:100'],
            'degree' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:180'],
            'consent_source' => ['required', 'string', 'max:255'],
            'consent_confirmed' => ['required', 'accepted'],
            'identity_confirmed' => ['required', 'accepted'],
            'is_visible' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);
    }

    private function fillEntry(WebsiteTutorGallery $entry, array $data, Request $request): void
    {
        $profileId = $data['teacher_profile_id'] ?? null;
        if ($profileId) {
            $eligible = TeacherProfile::query()
                ->whereKey($profileId)
                ->whereNotNull('verified_at')
                ->whereNotNull('public_directory_approved_at')
                ->where('public_profile_enabled', true)
                ->whereNotNull('public_profile_consent_at')
                ->whereHas('user', fn ($user) => $user->where('role', 'teacher')->where('status', 'active'))
                ->exists();
            abort_unless($eligible, 422, 'Akun tutor belum memenuhi izin dan persetujuan untuk galeri publik.');
        }
        $entry->teacher_profile_id = $profileId;
        $entry->display_name = trim($data['display_name']);
        $entry->degree = trim($data['degree']);
        $entry->description = trim((string) ($data['description'] ?? '')) ?: null;
        $entry->consent_source = trim($data['consent_source']);
        $entry->is_visible = (bool) $data['is_visible'];
        if (array_key_exists('sort_order', $data)) {
            $entry->sort_order = (int) $data['sort_order'];
        }
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $extension = strtolower($file->extension());
            $path = $file->storeAs('photos/website_tutors', 'gallery_'.Str::uuid().'.'.$extension, 'public');
            $entry->photo_path = $path;
        }
    }

    private function payload(WebsiteTutorGallery $entry): array
    {
        $profile = $entry->teacherProfile;

        return [
            'id' => $entry->id,
            'teacher_profile_id' => $entry->teacher_profile_id,
            'display_name' => $entry->display_name,
            'degree' => $entry->degree,
            'description' => $entry->description,
            'photo_url' => PublicMedia::url($entry->photo_path ?: $profile?->photo),
            'photo_from_account' => $entry->photo_path === null && $entry->teacher_profile_id !== null,
            'consent_source' => $entry->consent_source,
            'is_visible' => $entry->is_visible,
            'sort_order' => $entry->sort_order,
        ];
    }
}
