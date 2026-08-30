<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StudentsPurgeNoEmail extends Command
{
    protected $signature = 'students:purge-no-email {--dry-run : Show what would be deleted without deleting} {--notify : Email a summary if any were deleted}';
    protected $description = 'Delete students that have neither email nor acuity_client_id (historical placeholders).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = (int) DB::table('students')->count();
        $toDelete = (int) DB::table('students')
            ->where(function ($q) {
                $q->whereNull('email')->orWhereRaw("TRIM(COALESCE(email,'')) = ''");
            })
            ->where(function ($q) {
                $q->whereNull('acuity_client_id')->orWhereRaw("TRIM(COALESCE(acuity_client_id,'')) = ''");
            })
            ->count();

        $this->info("Students total: {$total}");
        $this->info("Candidates (no email and no acuity_client_id): {$toDelete}");

        if ($toDelete === 0) {
            $this->info('Nothing to delete.');
            return self::SUCCESS;
        }

        if ($dry) {
            $this->info('Dry run complete. Use without --dry-run to delete.');
            return self::SUCCESS;
        }

        // Delete in chunks to avoid locks
        $deleted = 0;
        do {
            $chunk = DB::table('students')
                ->select('id')
                ->where(function ($q) {
                    $q->whereNull('email')->orWhereRaw("TRIM(COALESCE(email,'')) = ''");
                })
                ->where(function ($q) {
                    $q->whereNull('acuity_client_id')->orWhereRaw("TRIM(COALESCE(acuity_client_id,'')) = ''");
                })
                ->limit(500)
                ->pluck('id');

            if ($chunk->isEmpty()) break;

            DB::table('students')->whereIn('id', $chunk)->delete();
            $deleted += $chunk->count();
            $this->line("Deleted {$deleted}/{$toDelete}...");
        } while (true);

        $this->info("Deleted {$deleted} placeholder students.");
        if ($deleted > 0 && $this->option('notify')) {
            try {
                (new \App\Services\EmailNotifier())->send('Students purge summary', "Deleted {$deleted} placeholder students.");
            } catch (\Throwable $e) {}
        }
        return self::SUCCESS;
    }
}
