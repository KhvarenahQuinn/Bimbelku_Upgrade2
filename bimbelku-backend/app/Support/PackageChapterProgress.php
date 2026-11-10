<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class PackageChapterProgress
{
    /**
     * Package material is stored natively at Bab level. Each PackageChapter is
     * one progress row; there is no Subbab aggregation in the production flow.
     */
    public static function chapters(Collection $chapters): Collection
    {
        return $chapters
            ->sortBy(fn ($chapter) => sprintf('%06d-%010d', (int) ($chapter->sort_order ?? 0), (int) ($chapter->id ?? 0)))
            ->values()
            ->map(fn ($chapter) => [
                'id' => (int) $chapter->id,
                'chapter' => self::chapterName($chapter->title ?? null),
                'curriculum_chapter_id' => $chapter->curriculum_chapter_id ? (int) $chapter->curriculum_chapter_id : null,
                'status' => (string) ($chapter->status ?: 'not_started'),
                'needs_review' => (bool) $chapter->needs_review,
                'started_at' => $chapter->started_at,
                'completed_at' => $chapter->completed_at,
            ]);
    }

    public static function summary(Collection $chapters): array
    {
        $rows = self::chapters($chapters);
        $completed = $rows->where('status', 'completed')->count();
        $inProgress = $rows->where('status', 'in_progress')->count();
        $total = $rows->count();

        return [
            'total_chapters' => $total,
            'completed_chapters' => $completed,
            'in_progress_chapters' => $inProgress,
            'progress_percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
        ];
    }

    public static function chapterLogs(Collection $logs): Collection
    {
        return $logs
            ->sortBy('id')
            ->values()
            ->map(fn ($log) => [
                'chapter' => self::chapterName($log->chapter?->title),
                'activity_type' => (string) ($log->activity_type ?: 'taught'),
                'status_before' => (string) ($log->status_before ?: 'not_started'),
                'status_after' => (string) ($log->status_after ?: 'not_started'),
                'needs_review' => (bool) $log->needs_review_after,
                'notes' => filled($log->notes) ? (string) $log->notes : null,
            ]);
    }

    private static function chapterName(?string $chapter): string
    {
        $value = trim((string) ($chapter ?: 'Materi belajar'));

        return $value !== '' ? $value : 'Materi belajar';
    }
}
