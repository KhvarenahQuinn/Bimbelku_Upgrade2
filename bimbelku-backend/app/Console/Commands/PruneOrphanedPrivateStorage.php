<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PruneOrphanedPrivateStorage extends Command
{
    protected $signature = 'storage:prune-orphaned-private
        {--hours=24 : Minimum file age in hours before pruning}
        {--force : Permanently delete eligible orphaned files (dry-run by default)}
        {--delete : Alias for --force}';

    protected $description = 'Scan private (local) storage for unreferenced orphaned files, with dry-run default';

    /**
     * Private disk columns referenced across active application models.
     *
     * @var array<string, list<string>>
     */
    private const REFERENCED_COLUMNS = [
        'teacher_profiles' => [
            'cv_file',
            'identity_document',
            'live_selfie',
            'qualification_document',
            'certification_document',
        ],
        'orders' => ['payment_proof'],
        'classroom_messages' => ['attachment_path'],
        'teacher_replacement_requests' => ['evidence_path'],
        'session_reports' => ['evidence'],
        'booking_disputes' => ['evidence'],
        'payouts' => ['proof_url'],
        'refunds' => ['proof'],
        'website_testimonials' => ['proof_path'],
    ];

    public function handle(): int
    {
        $hours = max(0, (int) $this->option('hours'));
        $deleteMode = (bool) $this->option('force') || (bool) $this->option('delete');
        $cutoffTimestamp = now()->subHours($hours)->getTimestamp();

        $referenced = $this->collectReferencedPaths();
        $disk = Storage::disk('local');
        $files = $disk->allFiles();

        $scanned = 0;
        $referencedCount = 0;
        $tooRecentCount = 0;
        $orphanedPaths = [];

        foreach ($files as $rawPath) {
            if (str_starts_with(basename($rawPath), '.')) {
                continue;
            }

            $scanned++;
            $normalized = $this->normalizePath($rawPath);
            if ($normalized !== null && isset($referenced[$normalized])) {
                $referencedCount++;
                continue;
            }

            if ($hours > 0 && $disk->lastModified($rawPath) > $cutoffTimestamp) {
                $tooRecentCount++;
                continue;
            }

            $orphanedPaths[] = $rawPath;
        }

        $deletedCount = 0;
        if ($deleteMode) {
            foreach ($orphanedPaths as $path) {
                if ($disk->delete($path)) {
                    $deletedCount++;
                }
            }
        }

        $modeLabel = $deleteMode ? 'delete' : 'dry-run';
        $this->info(sprintf(
            'Private storage sweep [%s] (hours=%d): scanned=%d referenced=%d too_recent=%d orphaned=%d deleted=%d',
            $modeLabel,
            $hours,
            $scanned,
            $referencedCount,
            $tooRecentCount,
            count($orphanedPaths),
            $deletedCount,
        ));

        foreach ($orphanedPaths as $path) {
            $this->line(sprintf(' - [%s] %s', $deleteMode ? 'deleted' : 'would-delete', $path));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, true>
     */
    private function collectReferencedPaths(): array
    {
        $paths = [];

        foreach (self::REFERENCED_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $values = DB::table($table)
                    ->whereNotNull($column)
                    ->pluck($column);

                foreach ($values as $value) {
                    if (! is_string($value)) {
                        continue;
                    }
                    $normalized = $this->normalizePath($value);
                    if ($normalized !== null) {
                        $paths[$normalized] = true;
                    }
                }
            }
        }

        return $paths;
    }

    private function normalizePath(string $value): ?string
    {
        $path = trim(str_replace('\\', '/', $value));
        if ($path === '' || preg_match('#^https?://#i', $path) === 1) {
            return null;
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/app/private/')) {
            $path = substr($path, strlen('storage/app/private/'));
        }

        return $path !== '' ? $path : null;
    }
}
