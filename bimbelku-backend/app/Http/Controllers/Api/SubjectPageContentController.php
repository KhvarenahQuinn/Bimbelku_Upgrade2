<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurriculumSubject;
use App\Models\SubjectPageContent;
use App\Models\TeacherSubject;
use App\Models\WebsiteTestimonial;
use App\Support\PublicMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SubjectPageContentController extends Controller
{
    public function show(CurriculumSubject $curriculumSubject)
    {
        abort_unless($curriculumSubject->is_active, 404);
        $content = SubjectPageContent::query()->where('curriculum_subject_id', $curriculumSubject->id)
            ->where('is_published', true)->first();
        $testimonials = WebsiteTestimonial::query()
            ->where('curriculum_subject_id', $curriculumSubject->id)
            ->where('is_visible', true)->whereNotNull('verified_at')->whereNotNull('consent_at')
            ->whereNotNull('rating_id')->whereNotNull('photo_path')
            ->orderByDesc('is_featured')->limit(6)->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'display_name' => $item->display_name,
                'quote' => $item->quote,
                'audience_role' => $item->audience_role,
                'photo_url' => PublicMedia::url($item->photo_path),
                'rating' => $item->rating?->rating,
            ]);

        $tutors = TeacherSubject::query()
            ->where('curriculum_subject_id', $curriculumSubject->id)
            ->where('is_active', true)
            ->where('is_private_active', true)
            ->whereHas('teacherProfile', fn ($query) => $query
                ->whereNotNull('verified_at')
                ->where('public_profile_enabled', true)
                ->whereNotNull('public_profile_consent_at')
                ->whereNotNull('public_directory_approved_at')
                ->whereHas('user', fn ($user) => $user->where('role', 'teacher')->where('status', 'active')))
            ->with(['teacherProfile.user'])
            ->orderBy('id')
            ->limit(6)
            ->get()
            ->map(function (TeacherSubject $subject): array {
                $profile = $subject->teacherProfile;

                return [
                    'id' => $profile->id,
                    'name' => $profile->public_display_name ?: $profile->user->name,
                    'degree' => $profile->public_degree,
                    'title' => $profile->title,
                    'credentials' => $profile->public_credentials,
                    'experience' => $profile->experience,
                    'photo_url' => PublicMedia::url($profile->photo),
                ];
            });

        return response()->json(['content' => $this->formatContent($content), 'testimonials' => $testimonials, 'tutors' => $tutors]);
    }

    public function adminShow(CurriculumSubject $curriculumSubject)
    {
        return response()->json([
            'subject' => $curriculumSubject->only(['id', 'name']),
            'content' => $this->formatContent(SubjectPageContent::query()->firstOrNew(['curriculum_subject_id' => $curriculumSubject->id])),
        ]);
    }

    public function updateHero(Request $request, CurriculumSubject $curriculumSubject)
    {
        $data = $request->validate([
            'hero_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144', 'dimensions:min_width=720,min_height=480'],
        ]);
        $content = SubjectPageContent::query()->firstOrCreate([
            'curriculum_subject_id' => $curriculumSubject->id,
        ]);
        $file = $data['hero_image'];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $newPath = $file->storeAs('website/subjects', 'hero_'.Str::uuid().'.'.$extension, 'public');
        $oldPath = $content->hero_image_path;
        $content->update(['hero_image_path' => $newPath]);
        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }
        $request->attributes->set('admin_audit_target_override', $content);

        return response()->json([
            'message' => 'Foto hero Mapel tersimpan.',
            'hero_image_url' => PublicMedia::url($newPath),
        ]);
    }

    public function removeHero(Request $request, CurriculumSubject $curriculumSubject)
    {
        $content = SubjectPageContent::query()->where('curriculum_subject_id', $curriculumSubject->id)->firstOrFail();
        $oldPath = $content->hero_image_path;
        $content->update(['hero_image_path' => null]);
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }
        $request->attributes->set('admin_audit_target_override', $content);

        return response()->json(['message' => 'Foto hero Mapel dihapus.']);
    }

    public function update(Request $request, CurriculumSubject $curriculumSubject)
    {
        $data = $request->validate([
            'hero_intro' => ['nullable', 'string', 'max:1000'],
            'learning_approach' => ['nullable', 'string', 'max:3000'],
            'facts' => ['nullable', 'array', 'max:20'],
            'facts.*.label' => ['required', 'string', 'max:100'],
            'facts.*.value' => ['required', 'string', 'max:200'],
            'learning_map' => ['nullable', 'array', 'max:30'],
            'learning_map.*.stage' => ['required', 'string', 'max:150'],
            'learning_map.*.coverage' => ['required', 'string', 'max:500'],
            'learning_map.*.difficulty' => ['nullable', 'string', 'max:500'],
            'learning_map.*.approach' => ['nullable', 'string', 'max:500'],
            'learning_journey' => ['nullable', 'array', 'max:12'],
            'learning_journey.*.period' => ['required', 'string', 'max:100'],
            'learning_journey.*.title' => ['required', 'string', 'max:150'],
            'learning_journey.*.description' => ['required', 'string', 'max:700'],
            'benefits' => ['nullable', 'array', 'max:12'],
            'benefits.*' => ['required', 'string', 'max:250'],
            'suitable_for' => ['nullable', 'array', 'max:12'],
            'suitable_for.*' => ['required', 'string', 'max:250'],
            'reasons' => ['nullable', 'array', 'max:12'],
            'reasons.*.title' => ['required', 'string', 'max:150'],
            'reasons.*.description' => ['required', 'string', 'max:1000'],
            'articles' => ['nullable', 'array', 'max:10'],
            'articles.*.title' => ['required', 'string', 'max:200'],
            'articles.*.body' => ['required', 'string', 'max:6000'],
            'faqs' => ['nullable', 'array', 'max:20'],
            'faqs.*.question' => ['required', 'string', 'max:250'],
            'faqs.*.answer' => ['required', 'string', 'max:1000'],
            'source_note' => ['nullable', 'string', 'max:500'],
            'reviewed_at' => ['nullable', 'date', 'before_or_equal:today'],
            'is_published' => ['required', 'boolean'],
        ]);

        if ($data['is_published'] && (blank($data['hero_intro'] ?? null) || blank($data['source_note'] ?? null) || blank($data['reviewed_at'] ?? null))) {
            return response()->json(['message' => 'Pengantar, sumber informasi, dan tanggal peninjauan wajib diisi sebelum publikasi.'], 422);
        }

        $content = SubjectPageContent::query()->updateOrCreate(
            ['curriculum_subject_id' => $curriculumSubject->id], $data
        );
        $request->attributes->set('admin_audit_target_override', $content);

        return response()->json(['message' => 'Konten halaman mapel tersimpan.', 'content' => $this->formatContent($content)]);
    }

    private function formatContent(?SubjectPageContent $content): ?array
    {
        if (! $content || ! $content->exists) {
            return $content ? $content->toArray() + ['hero_image_url' => null] : null;
        }
        $payload = $content->toArray();
        unset($payload['hero_image_path']);
        $payload['hero_image_url'] = $content->hero_image_path && Storage::disk('public')->exists($content->hero_image_path)
            ? PublicMedia::url($content->hero_image_path)
            : null;

        return $payload;
    }
}
