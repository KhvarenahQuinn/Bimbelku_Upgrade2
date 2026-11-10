<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurriculumSubject;
use App\Models\PackageSession;
use App\Models\Rating;
use App\Models\TeacherProfile;
use App\Models\WebsiteSection;
use App\Models\WebsiteSetting;
use App\Models\WebsiteTestimonial;
use App\Models\WebsiteTrustItem;
use App\Support\PublicMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebsiteContentController extends Controller
{
    private const CACHE_KEY = 'public.website_content.v1';

    private const MEDIA_FIELDS = [
        'logo' => 'logo_path',
        'logo_light' => 'logo_light_path',
        'logo_dark' => 'logo_dark_path',
        'favicon' => 'favicon_path',
        'social_share_image' => 'social_share_image_path',
        'hero_desktop_image' => 'hero_desktop_image_path',
        'hero_mobile_image' => 'hero_mobile_image_path',
        'trust_image_1' => 'trust_image_1_path',
        'trust_image_2' => 'trust_image_2_path',
        'trust_image_3' => 'trust_image_3_path',
        'trust_image_4' => 'trust_image_4_path',
        'trust_image_5' => 'trust_image_5_path',
        'trust_image_6' => 'trust_image_6_path',
    ];

    public function show(Request $request)
    {
        $payload = Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn () => $this->payload(false));
        $response = response()->json($payload);
        $response->setEtag(sha1((string) json_encode($payload)));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->headers->addCacheControlDirective('stale-while-revalidate', '60');
        $response->isNotModified($request);

        return $response;
    }

    public function adminShow()
    {
        return response()->json($this->payload(true));
    }

    public function media()
    {
        return response()->json(['media' => $this->mediaPayload()]);
    }

    public function updateMedia(Request $request)
    {
        $request->validate([
            'logo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'logo_light' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'logo_dark' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:512'],
            'social_share_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'hero_desktop_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'hero_mobile_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_1' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_2' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_3' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_4' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_5' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_6' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ]);

        abort_unless(collect(array_keys(self::MEDIA_FIELDS))->contains(fn (string $key) => $request->hasFile($key)),
            422, 'Pilih setidaknya satu gambar untuk disimpan.');

        $settings = WebsiteSetting::query()->firstOrCreate(['singleton_key' => 1]);
        $request->attributes->set('admin_audit_target_override', $settings);
        $newPaths = [];
        $oldPaths = [];

        try {
            foreach (self::MEDIA_FIELDS as $input => $column) {
                if (! $request->hasFile($input)) {
                    continue;
                }
                $file = $request->file($input);
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
                $newPaths[$column] = $file->storeAs('website', $input.'_'.Str::uuid().'.'.$extension, 'public');
            }

            DB::transaction(function () use ($settings, $newPaths, &$oldPaths): void {
                $locked = WebsiteSetting::query()->lockForUpdate()->findOrFail($settings->id);
                foreach ($newPaths as $column => $path) {
                    $oldPaths[] = $locked->{$column};
                }
                $locked->forceFill($newPaths)->save();
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete(array_values($newPaths));
            throw $exception;
        }

        Storage::disk('public')->delete(array_values(array_filter($oldPaths,
            fn ($path) => is_string($path) && str_starts_with($path, 'website/'))));
        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'message' => 'Gambar website berhasil diperbarui.',
            'media' => $this->mediaPayload(),
        ]);
    }

    private function mediaPayload(): array
    {
        $settings = WebsiteSetting::query()->firstOrCreate(['singleton_key' => 1]);

        return collect(self::MEDIA_FIELDS)->mapWithKeys(fn (string $column) => [
            str_replace('_path', '_url', $column) => $this->mediaUrl($settings->{$column}),
        ])->all();
    }

    public function testimonials()
    {
        return response()->json(
            $this->publicTestimonials()->paginate(12)->through(
                fn (WebsiteTestimonial $testimonial) => $this->testimonialPayload($testimonial, false)
            )
        );
    }

    public function tutors(Request $request)
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $profiles = TeacherProfile::query()
            ->with(['user:id,name,status', 'subjects' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->whereNotNull('verified_at')
            ->where('public_profile_enabled', true)
            ->whereNotNull('public_profile_consent_at')
            ->whereNotNull('public_directory_approved_at')
            ->whereHas('user', fn ($query) => $query->where('role', 'teacher')
                ->where('status', 'active')
                ->where(fn ($users) => $users->whereNull('policy_version')->orWhere('policy_version', '!=', 'demo-local')))
            ->orderByDesc('verified_at');
        if (isset($data['limit'])) {
            $profiles->limit((int) $data['limit']);
        }
        $profiles = $profiles->get();

        $ratingStats = Rating::query()
            ->whereIn('teacher_id', $profiles->pluck('user_id'))
            ->selectRaw('teacher_id, COUNT(*) as rating_count, AVG(rating) as rating_average')
            ->groupBy('teacher_id')
            ->get()
            ->keyBy('teacher_id');

        $catalogSubjects = CurriculumSubject::query()->where('is_active', true)
            ->get(['id', 'name', 'group_name']);
        $groupById = $catalogSubjects->mapWithKeys(fn (CurriculumSubject $subject) => [
            $subject->id => trim((string) $subject->group_name) ?: 'Bidang lainnya',
        ]);
        $groupByName = $catalogSubjects->mapWithKeys(fn (CurriculumSubject $subject) => [
            mb_strtolower(trim((string) $subject->name)) => trim((string) $subject->group_name) ?: 'Bidang lainnya',
        ]);

        return response()->json($profiles->map(function (TeacherProfile $profile) use ($ratingStats, $groupById, $groupByName) {
            $rating = $ratingStats->get($profile->user_id);
            $groups = $profile->subjects->map(function ($subject) use ($groupById, $groupByName) {
                return $groupById->get($subject->curriculum_subject_id)
                    ?? $groupByName->get(mb_strtolower(trim((string) $subject->name)))
                    ?? 'Bidang lainnya';
            })->unique()->values();

            return [
                'id' => $profile->id,
                'name' => $profile->public_display_name ?: $profile->user?->name,
                'degree' => $profile->public_degree,
                'title' => $profile->title,
                'credentials' => $profile->public_credentials,
                'experience' => $profile->experience,
                'bio' => $profile->bio,
                'photo_url' => $this->mediaUrl($profile->photo),
                'subjects' => $profile->subjects->pluck('name')->filter()->unique()->values(),
                'groups' => $groups->isNotEmpty() ? $groups : ['Bidang lainnya'],
                'rating_average' => $rating ? round((float) $rating->rating_average, 1) : null,
                'rating_count' => $rating ? (int) $rating->rating_count : 0,
            ];
        })->values());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:80'],
            'brand_description' => ['nullable', 'string', 'max:300'],
            'information_bar_enabled' => ['required', 'boolean'],
            'information_bar_text' => ['nullable', 'string', 'max:180'],
            'primary_cta_label' => ['required', 'string', 'max:60'],
            'primary_cta_url' => ['required', 'string', 'max:500', 'regex:#^/(?!/)#'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'whatsapp_number' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{8,15}$/'],
            'whatsapp_label' => ['required', 'string', 'max:60'],
            'whatsapp_hours' => ['nullable', 'string', 'max:120'],
            'whatsapp_default_message' => ['nullable', 'string', 'max:500'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'office_address' => ['nullable', 'string', 'max:1000'],
            'google_maps_url' => ['nullable', 'url:http,https', 'max:1000'],
            'animations_enabled' => ['required', 'boolean'],
            'navigation_items' => ['required', 'array', 'max:8'],
            'navigation_items.*.key' => ['required', 'string', 'max:40', 'distinct'],
            'navigation_items.*.label' => ['required', 'string', 'max:40'],
            'navigation_items.*.url' => ['required', 'string', 'max:500', 'regex:#^/(?!/)#'],
            'navigation_items.*.is_visible' => ['required', 'boolean'],
            'sections' => ['sometimes', 'array'],
            'sections.*.id' => ['required', 'integer', 'exists:website_sections,id'],
            'sections.*.eyebrow' => ['nullable', 'string', 'max:100'],
            'sections.*.title' => ['nullable', 'string', 'max:180'],
            'sections.*.description' => ['nullable', 'string', 'max:1000'],
            'sections.*.content' => ['nullable', 'array'],
            'sections.*.content.secondary_cta_label' => ['nullable', 'string', 'max:60'],
            'sections.*.content.search_placeholder' => ['nullable', 'string', 'max:120'],
            'sections.*.content.status_pending' => ['nullable', 'string', 'max:80'],
            'sections.*.content.status_found' => ['nullable', 'string', 'max:80'],
            'sections.*.content.trust_points' => ['nullable', 'array', 'max:3'],
            'sections.*.content.trust_points.*' => ['required', 'string', 'max:80'],
            'sections.*.content.trust_media_titles' => ['nullable', 'array', 'max:6'],
            'sections.*.content.trust_media_titles.*' => ['required', 'string', 'max:100'],
            'sections.*.content.trust_media_notes' => ['nullable', 'array', 'max:6'],
            'sections.*.content.trust_media_notes.*' => ['required', 'string', 'max:180'],
            'sections.*.is_visible' => ['required', 'boolean'],
            'sections.*.sort_order' => ['required', 'integer', 'min:0', 'max:100'],
            'trust_items' => ['sometimes', 'array', 'max:8'],
            'trust_items.*.id' => ['required', 'integer', 'exists:website_trust_items,id'],
            'trust_items.*.title' => ['required', 'string', 'max:100'],
            'trust_items.*.display_value' => ['nullable', 'string', 'max:80'],
            'trust_items.*.description' => ['nullable', 'string', 'max:180'],
            'trust_items.*.source_type' => ['required', Rule::in(['system', 'manual', 'commitment'])],
            'trust_items.*.source_key' => ['nullable', Rule::in(['active_programs', 'verified_tutors', 'completed_sessions', 'average_rating', 'service_area'])],
            'trust_items.*.source_note' => ['nullable', 'string', 'max:500'],
            'trust_items.*.source_updated_at' => ['nullable', 'date', 'before_or_equal:today'],
            'trust_items.*.is_visible' => ['required', 'boolean'],
            'trust_items.*.sort_order' => ['required', 'integer', 'min:0', 'max:100'],
            'testimonials' => ['sometimes', 'array'],
            'testimonials.*.id' => ['nullable', 'integer', 'exists:website_testimonials,id'],
            'testimonials.*.rating_id' => ['nullable', 'integer', 'exists:ratings,id'],
            'testimonials.*.curriculum_subject_id' => ['nullable', 'integer', 'exists:curriculum_subjects,id'],
            'testimonials.*.display_name' => ['required', 'string', 'max:100'],
            'testimonials.*.audience_role' => ['nullable', 'string', 'max:120'],
            'testimonials.*.quote' => ['required', 'string', 'max:1000'],
            'testimonials.*.program_name' => ['nullable', 'string', 'max:150'],
            'testimonials.*.outcome' => ['nullable', 'string', 'max:180'],
            'testimonials.*.institution' => ['nullable', 'string', 'max:150'],
            'testimonials.*.major' => ['nullable', 'string', 'max:150'],
            'testimonials.*.achievement_year' => ['nullable', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
            'testimonials.*.consent_confirmed' => ['required', 'boolean'],
            'testimonials.*.verified' => ['required', 'boolean'],
            'testimonials.*.is_featured' => ['required', 'boolean'],
            'testimonials.*.is_visible' => ['required', 'boolean'],
            'testimonials.*.sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'testimonials.*.photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'testimonials.*.proof' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp,pdf', 'max:5120'],
            'deleted_testimonial_ids' => ['sometimes', 'array'],
            'deleted_testimonial_ids.*' => ['integer', 'exists:website_testimonials,id'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'logo_light' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'logo_dark' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:512'],
            'social_share_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'hero_desktop_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'hero_mobile_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_1' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_2' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_3' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_4' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_5' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
            'trust_image_6' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ], [
            'primary_cta_url.regex' => 'Tujuan CTA harus berupa path internal yang dimulai dengan /.',
            'navigation_items.*.url.regex' => 'Tujuan menu harus berupa path internal yang dimulai dengan /.',
            'whatsapp_number.regex' => 'Nomor WhatsApp harus berisi 8–15 angka dan boleh diawali +.',
        ]);

        if (($data['whatsapp_enabled'] ?? false) && blank($data['whatsapp_number'] ?? null)) {
            return response()->json(['message' => 'Nomor WhatsApp wajib diisi ketika tombol konsultasi aktif.'], 422);
        }

        foreach ($data['trust_items'] ?? [] as $index => $item) {
            if ($item['source_type'] === 'system' && blank($item['source_key'] ?? null)) {
                return response()->json(['message' => 'Sumber sistem wajib dipilih untuk item kepercayaan ke-'.($index + 1).'.'], 422);
            }
            if ($item['source_type'] === 'manual' && (blank($item['source_note'] ?? null) || blank($item['source_updated_at'] ?? null))) {
                return response()->json(['message' => 'Catatan sumber dan tanggal pembaruan wajib diisi untuk data manual ke-'.($index + 1).'.'], 422);
            }
            if ($item['source_type'] === 'manual' && blank($item['display_value'] ?? null)) {
                return response()->json(['message' => 'Nilai publik wajib diisi untuk data manual ke-'.($index + 1).'.'], 422);
            }
        }

        foreach ($data['testimonials'] ?? [] as $index => $testimonial) {
            if (($testimonial['is_visible'] ?? false) && ! ($testimonial['consent_confirmed'] ?? false)) {
                return response()->json(['message' => 'Izin publikasi wajib dikonfirmasi untuk testimoni ke-'.($index + 1).'.'], 422);
            }
            if (($testimonial['is_visible'] ?? false) && ! ($testimonial['verified'] ?? false)) {
                return response()->json(['message' => 'Testimoni ke-'.($index + 1).' harus diverifikasi sebelum ditampilkan.'], 422);
            }
            if (($testimonial['is_visible'] ?? false) && blank($testimonial['rating_id'] ?? null)) {
                return response()->json(['message' => 'Pilih rating murid nyata untuk testimoni publik ke-'.($index + 1).'.'], 422);
            }
            if (($testimonial['is_visible'] ?? false)) {
                $rating = Rating::query()->whereKey($testimonial['rating_id'])
                    ->whereHas('student', fn ($query) => $query->where('role', 'student'))->first();
                if (! $rating || blank($rating->review) || trim((string) $testimonial['quote']) !== trim((string) $rating->review)) {
                    return response()->json(['message' => 'Kutipan testimoni ke-'.($index + 1).' harus berasal dari ulasan rating murid yang dipilih.'], 422);
                }
            }
            $storedTestimonial = filled($testimonial['id'] ?? null)
                ? WebsiteTestimonial::query()->find($testimonial['id'])
                : null;
            if (($testimonial['is_visible'] ?? false)
                && ! $request->hasFile("testimonials.$index.photo")
                && blank($storedTestimonial?->photo_path)) {
                return response()->json(['message' => 'Foto asli wajib diunggah untuk testimoni publik ke-'.($index + 1).'.'], 422);
            }
            if (($testimonial['is_visible'] ?? false)
                && ! $request->hasFile("testimonials.$index.proof")
                && blank($storedTestimonial?->proof_path)) {
                return response()->json(['message' => 'Bukti privat wajib diunggah sebelum testimoni ke-'.($index + 1).' ditampilkan.'], 422);
            }
        }

        $settings = WebsiteSetting::query()->firstOrCreate(['singleton_key' => 1]);
        $request->attributes->set('admin_audit_target_override', $settings);
        $newPaths = [];
        $oldPaths = [];
        $newPrivatePaths = [];
        $oldPrivatePaths = [];

        try {
            foreach (self::MEDIA_FIELDS as $input => $column) {
                if (! $request->hasFile($input)) {
                    continue;
                }
                $file = $request->file($input);
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
                $newPaths[$column] = $file->storeAs('website', $input.'_'.Str::uuid().'.'.$extension, 'public');
                $oldPaths[] = $settings->{$column};
            }

            DB::transaction(function () use ($data, $settings, &$newPaths, $request, &$oldPaths, &$newPrivatePaths, &$oldPrivatePaths) {
                $settings->newQuery()->whereKey($settings->id)->lockForUpdate()->firstOrFail();
                $settings->update(array_merge(collect($data)->except([
                    'sections', 'trust_items', ...array_keys(self::MEDIA_FIELDS),
                ])->all(), $newPaths));

                foreach ($data['sections'] ?? [] as $sectionData) {
                    $section = WebsiteSection::query()->lockForUpdate()->findOrFail($sectionData['id']);
                    $section->update([
                        'eyebrow' => array_key_exists('eyebrow', $sectionData) ? $sectionData['eyebrow'] : $section->eyebrow,
                        'title' => array_key_exists('title', $sectionData) ? $sectionData['title'] : $section->title,
                        'description' => array_key_exists('description', $sectionData) ? $sectionData['description'] : $section->description,
                        'content' => array_key_exists('content', $sectionData) ? $sectionData['content'] : $section->content,
                        'is_visible' => $sectionData['is_visible'],
                        'sort_order' => $section->order_locked ? $section->sort_order : $sectionData['sort_order'],
                    ]);
                }

                foreach ($data['trust_items'] ?? [] as $itemData) {
                    $item = WebsiteTrustItem::query()->lockForUpdate()->findOrFail($itemData['id']);
                    $item->update(collect($itemData)->except('id')->all());
                }

                foreach ($data['deleted_testimonial_ids'] ?? [] as $testimonialId) {
                    $testimonial = WebsiteTestimonial::query()->lockForUpdate()->findOrFail($testimonialId);
                    $oldPaths[] = $testimonial->photo_path;
                    if ($testimonial->proof_path) {
                        $oldPrivatePaths[] = $testimonial->proof_path;
                    }
                    $testimonial->delete();
                }

                foreach ($data['testimonials'] ?? [] as $index => $testimonialData) {
                    $testimonial = filled($testimonialData['id'] ?? null)
                        ? WebsiteTestimonial::query()->lockForUpdate()->findOrFail($testimonialData['id'])
                        : new WebsiteTestimonial;

                    $attributes = collect($testimonialData)->except([
                        'id', 'photo', 'proof', 'consent_confirmed', 'verified',
                    ])->all();
                    $attributes['consent_at'] = ($testimonialData['consent_confirmed'] ?? false)
                        ? ($testimonial->consent_at ?: now()) : null;
                    $attributes['verified_at'] = ($testimonialData['verified'] ?? false)
                        ? ($testimonial->verified_at ?: now()) : null;
                    $attributes['verified_by'] = ($testimonialData['verified'] ?? false)
                        ? ($testimonial->verified_by ?: $request->user()->id) : null;

                    if ($request->hasFile("testimonials.$index.photo")) {
                        $oldPaths[] = $testimonial->photo_path;
                        $file = $request->file("testimonials.$index.photo");
                        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
                        $attributes['photo_path'] = $file->storeAs('website/testimonials', 'photo_'.Str::uuid().'.'.$extension, 'public');
                        $newPaths[] = $attributes['photo_path'];
                    }
                    if ($request->hasFile("testimonials.$index.proof")) {
                        if ($testimonial->proof_path) {
                            $oldPrivatePaths[] = $testimonial->proof_path;
                        }
                        $file = $request->file("testimonials.$index.proof");
                        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
                        $attributes['proof_path'] = $file->storeAs('website-testimonial-proofs', 'proof_'.Str::uuid().'.'.$extension, 'local');
                        $newPrivatePaths[] = $attributes['proof_path'];
                    }

                    $testimonial->fill($attributes)->save();
                }
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete(array_values($newPaths));
            Storage::disk('local')->delete(array_values($newPrivatePaths));
            throw $exception;
        }

        Storage::disk('public')->delete(array_values(array_filter(
            $oldPaths,
            fn ($path) => is_string($path) && str_starts_with($path, 'website/')
        )));
        Storage::disk('local')->delete(array_values(array_filter(
            $oldPrivatePaths,
            fn ($path) => is_string($path) && str_starts_with($path, 'website-testimonial-proofs/')
        )));
        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'message' => 'Pengaturan website publik berhasil disimpan.',
            'data' => $this->payload(true),
        ]);
    }

    private function payload(bool $admin): array
    {
        $settings = WebsiteSetting::query()->firstOrCreate(['singleton_key' => 1]);
        $sectionsQuery = WebsiteSection::query()->orderBy('sort_order')->orderBy('id');
        $trustQuery = WebsiteTrustItem::query()->orderBy('sort_order')->orderBy('id');

        if (! $admin) {
            $sectionsQuery->where('is_visible', true);
            $trustQuery->where('is_visible', true);
        }

        $settingsPayload = collect($settings->only([
            'brand_name', 'brand_description', 'information_bar_enabled', 'information_bar_text',
            'navigation_items', 'primary_cta_label', 'primary_cta_url', 'whatsapp_enabled',
            'whatsapp_number', 'whatsapp_label', 'whatsapp_hours', 'whatsapp_default_message',
            'contact_email', 'office_address', 'google_maps_url', 'animations_enabled', 'updated_at',
        ]))->merge([
            'logo_url' => $this->mediaUrl($settings->logo_path),
            'logo_light_url' => $this->mediaUrl($settings->logo_light_path),
            'logo_dark_url' => $this->mediaUrl($settings->logo_dark_path),
            'favicon_url' => $this->mediaUrl($settings->favicon_path),
            'social_share_image_url' => $this->mediaUrl($settings->social_share_image_path),
            'hero_desktop_image_url' => $this->mediaUrl($settings->hero_desktop_image_path),
            'hero_mobile_image_url' => $this->mediaUrl($settings->hero_mobile_image_path),
            'trust_image_1_url' => $this->mediaUrl($settings->trust_image_1_path),
            'trust_image_2_url' => $this->mediaUrl($settings->trust_image_2_path),
            'trust_image_3_url' => $this->mediaUrl($settings->trust_image_3_path),
            'trust_image_4_url' => $this->mediaUrl($settings->trust_image_4_path),
            'trust_image_5_url' => $this->mediaUrl($settings->trust_image_5_path),
            'trust_image_6_url' => $this->mediaUrl($settings->trust_image_6_path),
            'theme' => [
                'name' => 'warm-clear-trusted',
                'primary' => '#F97316',
                'primary_button' => '#C2410C',
                'primary_hover' => '#9A3412',
                'cream' => '#FFFBF7',
                'heading' => '#14213D',
            ],
        ])->all();

        if (! $admin) {
            $settingsPayload['navigation_items'] = collect($settingsPayload['navigation_items'] ?? [])
                ->filter(fn (array $item) => ($item['is_visible'] ?? true) === true)
                ->values()
                ->all();
        }

        return [
            'settings' => $settingsPayload,
            'sections' => $sectionsQuery->get()->map(fn (WebsiteSection $section) => $section->only([
                'id', 'section_key', 'label', 'eyebrow', 'title', 'description', 'content',
                'is_visible', 'order_locked', 'sort_order',
            ]))->values(),
            'trust_items' => $trustQuery->get()->map(fn (WebsiteTrustItem $item) => $this->trustPayload($item, $admin))->values(),
            'rating_summary' => $this->ratingSummary(),
            'testimonials' => ($admin ? WebsiteTestimonial::query() : $this->publicTestimonials())
                ->orderByDesc('is_featured')->orderBy('sort_order')->orderByDesc('id')
                ->when(! $admin, fn ($query) => $query->limit(12))
                ->get()
                ->map(fn (WebsiteTestimonial $testimonial) => $this->testimonialPayload($testimonial, $admin))
                ->values(),
        ];
    }

    private function publicTestimonials()
    {
        return WebsiteTestimonial::query()
            ->with(['rating:id,rating', 'verifier:id,name'])
            ->where('is_visible', true)
            ->whereNotNull('consent_at')
            ->whereNotNull('verified_at')
            ->whereNotNull('verified_by')
            ->whereNotNull('rating_id')
            ->whereNotNull('photo_path')
            ->whereNotNull('proof_path')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }

    private function testimonialPayload(WebsiteTestimonial $testimonial, bool $admin): array
    {
        $payload = $testimonial->only([
            'id', 'rating_id', 'curriculum_subject_id', 'display_name', 'audience_role', 'quote', 'program_name',
            'outcome', 'institution', 'major', 'achievement_year', 'is_featured',
            'is_visible', 'sort_order', 'created_at',
        ]);
        $payload['photo_url'] = $this->mediaUrl($testimonial->photo_path);
        $payload['rating'] = $testimonial->rating?->rating;
        $payload['is_verified'] = $testimonial->verified_at !== null;
        $payload['consent_at'] = $testimonial->consent_at?->toDateString();
        $payload['verified_at'] = $testimonial->verified_at?->toDateString();
        $payload['verified_by_name'] = $testimonial->verifier?->name;

        if ($admin) {
            $payload['consent_confirmed'] = $testimonial->consent_at !== null;
            $payload['has_proof'] = filled($testimonial->proof_path);
        }

        return $payload;
    }

    private function ratingSummary(): ?array
    {
        $ratings = Rating::query()->select('rating')->get();
        if ($ratings->count() < 3) {
            return null;
        }

        $distribution = collect(range(1, 5))->mapWithKeys(
            fn (int $score) => [(string) $score => $ratings->where('rating', $score)->count()]
        );

        return [
            'average' => round((float) $ratings->avg('rating'), 1),
            'count' => $ratings->count(),
            'distribution' => $distribution,
        ];
    }

    private function trustPayload(WebsiteTrustItem $item, bool $admin): array
    {
        $payload = $item->only([
            'id', 'title', 'display_value', 'description', 'icon_key', 'source_type',
            'source_key', 'source_note', 'source_updated_at', 'is_visible', 'sort_order',
        ]);
        $payload['resolved_value'] = $item->source_type === 'system'
            ? $this->systemValue($item->source_key)
            : $item->display_value;

        if (! $admin) {
            unset($payload['source_note']);
        }

        return $payload;
    }

    private function systemValue(?string $key): ?string
    {
        return match ($key) {
            'active_programs' => $this->formattedCount(CurriculumSubject::query()->where('is_active', true)->count()),
            'verified_tutors' => $this->formattedCount(TeacherProfile::query()
                ->whereNotNull('verified_at')
                ->whereHas('user', fn ($users) => $users->where('role', 'teacher')
                    ->where('status', 'active')
                    ->where(fn ($query) => $query->whereNull('policy_version')->orWhere('policy_version', '!=', 'demo-local')))
                ->count()),
            'completed_sessions' => $this->formattedCount(PackageSession::query()->where('status', 'completed')->count()),
            'average_rating' => $this->ratingValue(),
            'service_area' => 'Yogyakarta',
            default => null,
        };
    }

    private function formattedCount(int $count): ?string
    {
        return $count > 0 ? number_format($count, 0, ',', '.').'+' : null;
    }

    private function ratingValue(): ?string
    {
        $count = Rating::query()->count();
        if ($count < 3) {
            return null;
        }

        return number_format((float) Rating::query()->avg('rating'), 1, ',', '.').'/5';
    }

    private function mediaUrl(?string $path): ?string
    {
        return $path && Storage::disk('public')->exists($path) ? PublicMedia::url($path) : null;
    }
}
