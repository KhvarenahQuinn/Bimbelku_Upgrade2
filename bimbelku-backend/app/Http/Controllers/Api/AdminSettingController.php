<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Support\PublicMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminSettingController extends Controller
{
    // 1. UPDATE SAMPUL (Hanya Admin)
    public function updateTeacherCover(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $extension = $request->file('image')->extension();
        $path = $request->file('image')->storeAs(
            'settings',
            'global_teacher_cover_'.Str::uuid().".{$extension}",
            'public'
        );
        $oldPath = null;

        try {
            Setting::firstOrCreate(['key' => 'teacher_cover_path'], ['value' => null]);
            DB::transaction(function () use ($path, &$oldPath) {
                $setting = Setting::query()
                    ->where('key', 'teacher_cover_path')
                    ->lockForUpdate()
                    ->firstOrFail();
                $oldPath = $setting->value;
                $setting->update(['value' => $path]);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }
        Cache::forget('public.teacher_cover_path.v1');

        return response()->json([
            'message' => 'Sampul tutor berhasil diperbarui.',
            'url' => PublicMedia::url($path).'?t='.time(),
        ]);
    }

    // 2. GET SAMPUL (Public / Siapa saja)
    public function getTeacherCover(Request $request)
    {
        $path = Cache::remember('public.teacher_cover_path.v1', now()->addMinutes(10), function () {
            $configured = Setting::where('key', 'teacher_cover_path')->value('value');
            if ($configured && Storage::disk('public')->exists($configured)) {
                return $configured;
            }

            return collect(Storage::disk('public')->files('settings'))
                ->filter(fn (string $candidate) => $this->isTeacherCover($candidate))
                ->sortByDesc(fn (string $candidate) => Storage::disk('public')->lastModified($candidate))
                ->first();
        });
        $payload = ['url' => PublicMedia::url($path)];
        $response = response()->json($payload);
        $response->setEtag(sha1((string) json_encode($payload)));
        $response->headers->set('Cache-Control', 'no-store');
        $response->isNotModified($request);

        return $response;
    }

    public function teacherCoverStatus()
    {
        $teachers = User::query()->where('role', 'teacher');

        return response()->json([
            'teacher_count' => (clone $teachers)->count(),
            'custom_cover_count' => (clone $teachers)
                ->whereNotNull('profile_cover')
                ->where('profile_cover_use_default', false)
                ->count(),
        ]);
    }

    public function applyDefaultTeacherCover()
    {
        $configured = Setting::query()->where('key', 'teacher_cover_path')->value('value');
        if (! $configured || ! Storage::disk('public')->exists($configured)) {
            return response()->json(['message' => 'Unggah sampul default terlebih dahulu.'], 422);
        }

        $updated = User::query()->where('role', 'teacher')
            ->whereNotNull('profile_cover')
            ->where('profile_cover_use_default', false)
            ->update(['profile_cover_use_default' => true]);

        return response()->json([
            'message' => 'Sampul default sekarang dipakai tutor yang sebelumnya memakai sampul pribadi. File pribadi tetap tersimpan.',
            'updated_count' => $updated,
        ]);
    }

    private function isTeacherCover(string $path): bool
    {
        $name = basename($path);

        return str_starts_with($name, 'global_teacher_cover.')
            || str_starts_with($name, 'global_teacher_cover_');
    }
}
