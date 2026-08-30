<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class CleanStudentPhotos extends Command
{
    protected $signature = 'student-photos:clean {--dry-run : List orphaned files without deleting them}';

    protected $description = 'Remove student photo files that are no longer attached to any student record.';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $directory = 'student-photos';

        if (! $disk->directoryExists($directory)) {
            $this->info('No student photos directory found.');
            return self::SUCCESS;
        }

        $referenced = $this->getReferencedPhotoPaths();
        $files = collect($disk->allFiles($directory));

        if ($files->isEmpty()) {
            $this->info('No files found under student-photos.');
            return self::SUCCESS;
        }

        $orphans = $files->reject(fn (string $path) => $referenced->contains($path));

        if ($orphans->isEmpty()) {
            $this->info('No orphaned student photos detected.');
            return self::SUCCESS;
        }

        $totalSize = $orphans->sum(fn (string $path) => $disk->size($path));
        $humanSize = $this->formatBytes($totalSize);
        $this->warn("Found {$orphans->count()} orphaned file(s) totalling {$humanSize}.");

        if ($this->option('dry-run')) {
            $this->line('Dry run enabled; no files deleted. Sample listing:');
            $this->renderSample($disk, $orphans);
            return self::SUCCESS;
        }

        $orphans->each(fn (string $path) => $disk->delete($path));
        $this->info('Deleted ' . $orphans->count() . ' orphaned file(s).');

        return self::SUCCESS;
    }

    protected function getReferencedPhotoPaths(): Collection
    {
        return Student::query()
            ->whereNotNull('photo_path')
            ->pluck('photo_path')
            ->map(fn (?string $path) => $path ? ltrim($path, '/') : null)
            ->filter()
            ->values();
    }

    protected function renderSample($disk, Collection $paths): void
    {
        $rows = $paths->take(10)->map(fn (string $path) => [
            'path' => $path,
            'size' => $this->formatBytes($disk->size($path)),
            'modified' => $this->formatTimestamp($disk->lastModified($path)),
        ])->all();

        $this->table(['Path', 'Size', 'Last Modified'], $rows);
    }

    protected function formatTimestamp(?int $timestamp): string
    {
        if (! $timestamp) {
            return 'n/a';
        }

        return Carbon::createFromTimestamp($timestamp)
            ->timezone(config('app.timezone'))
            ->toDateTimeString();
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $bytes = $bytes / 1024;
        foreach ($units as $unit) {
            if ($bytes < 1024) {
                return number_format($bytes, 2) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return number_format($bytes, 2) . ' PB';
    }
}
