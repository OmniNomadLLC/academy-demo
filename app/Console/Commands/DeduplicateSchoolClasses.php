<?php

namespace App\Console\Commands;

use App\Support\AcuitySchoolClassSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DeduplicateSchoolClasses extends Command
{
    private const DEFAULT_CHUNK = 5000;
    private const MIN_CHUNK = 250;
    private const MAX_STALLED_CHUNKS = 3;
    private const WATCHDOG_SECONDS = 120;
    private const DELETE_BATCH_SIZE = 1000;

    protected $signature = 'school-classes:deduplicate
        {--chunk=' . self::DEFAULT_CHUNK . ' : Number of rows per fetch}
        {--memory-limit=512M : PHP memory_limit override}
        {--force : Apply rewrites, relink sessions, and delete duplicates}
        {--skip-relink : Skip updating class_sessions before deleting duplicates}';

    protected $description = 'Safely rewrite external IDs and delete duplicate school_classes without hanging.';

    private bool $signatureIndexAvailable = false;
    private int $cursorPadLength = 8;
    private string $cursorSqlExpression = '';
    private ?array $pendingBucket = null;

    public function handle(AcuitySchoolClassSynchronizer $synchronizer): int
    {
        $chunkSize = max(self::MIN_CHUNK, (int) $this->option('chunk'));
        $shouldForce = (bool) $this->option('force');
        $shouldRelink = ! (bool) $this->option('skip-relink');
        $memoryLimit = (string) $this->option('memory-limit');

        if ($memoryLimit !== '') {
            @ini_set('memory_limit', $memoryLimit);
        }

        $this->signatureIndexAvailable = $this->detectSignatureIndex();
        $this->cursorPadLength = $this->determineCursorPadLength();
        $this->cursorSqlExpression = sprintf(
            "CONCAT(IFNULL(external_source, 'acuity'), '|', IFNULL(external_id, CONCAT('legacy-', id)), '|', LPAD(id, %d, '0'))",
            $this->cursorPadLength
        );
        $this->pendingBucket = null;

        $this->info(sprintf(
            'Starting dedupe (%s mode, chunk=%s, signature-index=%s)',
            $shouldForce ? 'force' : 'dry-run',
            $chunkSize,
            $this->signatureIndexAvailable ? 'yes' : 'no'
        ));

        $summary = [
            'rewritten' => 0,
            'duplicate_groups' => 0,
            'duplicate_rows' => 0,
            'sessions_relinked' => 0,
            'deleted' => 0,
        ];

        $start = microtime(true);

        $this->rewriteIdentifiers($chunkSize, $synchronizer, $shouldForce, $summary);
        $this->dedupeSignatures($chunkSize, $shouldForce, $shouldRelink, $summary);

        $elapsed = round(microtime(true) - $start, 2);
        $this->info(sprintf(
            'Finished in %ss — rewritten=%d, groups=%d, duplicates=%d, relinked=%d, deleted=%d',
            $elapsed,
            $summary['rewritten'],
            $summary['duplicate_groups'],
            $summary['duplicate_rows'],
            $summary['sessions_relinked'],
            $summary['deleted']
        ));

        if (! $shouldForce) {
            $this->warn('Dry-run complete. Re-run with --force to apply deletions.');
        }

        return Command::SUCCESS;
    }

    private function rewriteIdentifiers(
        int $chunkSize,
        AcuitySchoolClassSynchronizer $synchronizer,
        bool $shouldForce,
        array &$summary
    ): void {
        $batch = 0;

        DB::table('school_classes')
            ->select('id', 'external_source', 'external_id', 'name', 'language', 'level', 'location', 'duration_minutes', 'max_students', 'acuity_appointment_type_id')
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $rows) use (&$batch, $synchronizer, $shouldForce, &$summary) {
                $batch++;
                $chunkStart = microtime(true);
                $updated = 0;

                foreach ($rows as $row) {
                    $desiredSource = $row->external_source ?: 'acuity';
                    $desiredId = $synchronizer->identifierFromRow((array) $row);

                    if ($row->external_source === $desiredSource && $row->external_id === $desiredId) {
                        continue;
                    }

                    $updated++;
                    if ($shouldForce) {
                        DB::table('school_classes')
                            ->where('id', $row->id)
                            ->update([
                                'external_source' => $desiredSource,
                                'external_id' => $desiredId,
                            ]);
                    }
                }

                $summary['rewritten'] += $updated;

                $this->line(sprintf(
                    '[rewrite %d] rows=%d rewritten=%d time=%.2fs',
                    $batch,
                    $rows->count(),
                    $updated,
                    microtime(true) - $chunkStart
                ));
            });
    }

    private function dedupeSignatures(
        int $chunkSize,
        bool $shouldForce,
        bool $shouldRelink,
        array &$summary
    ): void {
        $clockLastProgress = microtime(true);
        $lastCursor = null;
        $previousChunkToken = null;
        $stalledChunks = 0;
        $chunkNumber = 0;

        while (true) {
            $rows = $this->fetchChunk($chunkSize, $lastCursor);

            if ($rows->isEmpty()) {
                $this->info('No more rows to process.');
                break;
            }

            $chunkNumber++;
            $chunkStart = microtime(true);
            $firstKey = $this->cursorKeyFromRow($rows->first());
            $lastKey = $this->cursorKeyFromRow($rows->last());
            $progressToken = $this->signatureIndexAvailable
                ? $lastKey
                : $this->padId((int) $rows->last()->id);

            $progressMade = $previousChunkToken === null || strcmp($progressToken, $previousChunkToken) > 0;
            if (! $progressMade) {
                $stalledChunks++;
                $this->warn(sprintf(
                    '[chunk %d] stalled cursor at %s (stalled %d/%d)',
                    $chunkNumber,
                    $firstKey,
                    $stalledChunks,
                    self::MAX_STALLED_CHUNKS
                ));
            } else {
                $stalledChunks = 0;
            }

            if ($stalledChunks >= self::MAX_STALLED_CHUNKS) {
                throw new RuntimeException(sprintf(
                    'Cursor stalled at %s after %d attempts. Aborting to prevent infinite loop.',
                    $firstKey,
                    $stalledChunks
                ));
            }

            $buckets = $this->collectCompletedBuckets($rows);
            [$groupsInChunk, $duplicatesInChunk, $deletedInChunk] = $this->processBuckets(
                $buckets,
                $shouldForce,
                $shouldRelink,
                $summary
            );

            $this->line(sprintf(
                '[chunk %d] start=%s end=%s rows=%d dups=%d groups=%d deleted=%d time=%.2fs',
                $chunkNumber,
                $firstKey,
                $lastKey,
                $rows->count(),
                $duplicatesInChunk,
                $groupsInChunk,
                $deletedInChunk,
                microtime(true) - $chunkStart
            ));

            if ($progressMade || $duplicatesInChunk > 0 || $deletedInChunk > 0) {
                $clockLastProgress = microtime(true);
            }

            if ((microtime(true) - $clockLastProgress) > self::WATCHDOG_SECONDS) {
                throw new RuntimeException(sprintf(
                    'Watchdog triggered: no progress for %d seconds near cursor %s.',
                    self::WATCHDOG_SECONDS,
                    $lastKey
                ));
            }

            $previousChunkToken = $progressToken;
            $lastCursor = $this->signatureIndexAvailable
                ? $lastKey
                : (int) $rows->last()->id;
        }

        $this->finalizePendingBucket($shouldForce, $shouldRelink, $summary);
    }

    private function fetchChunk(int $chunkSize, $lastCursor): Collection
    {
        $query = DB::table('school_classes')
            ->select('id', 'external_source', 'external_id');

        if ($this->signatureIndexAvailable) {
            $query->orderBy('external_source')
                ->orderBy('external_id')
                ->orderBy('id');

            if ($lastCursor !== null) {
                $query->whereRaw($this->cursorSqlExpression . ' > ?', [$lastCursor]);
            }
        } else {
            $query->orderBy('id');

            if ($lastCursor !== null) {
                $query->where('id', '>', (int) $lastCursor);
            }
        }

        return $query->limit($chunkSize)->get();
    }

    private function collectCompletedBuckets(Collection $rows): array
    {
        $buckets = [];
        $currentBucket = $this->pendingBucket;

        foreach ($rows as $row) {
            $signature = $this->encodeSignature($row->external_source, $row->external_id);
            $rowId = (int) $row->id;

            if ($currentBucket === null) {
                $currentBucket = [
                    'signature' => $signature,
                    'keeper' => $rowId,
                    'duplicates' => [],
                ];
                continue;
            }

            if ($currentBucket['signature'] === $signature) {
                $currentBucket['duplicates'][] = $rowId;
                continue;
            }

            $buckets[] = $currentBucket;

            $currentBucket = [
                'signature' => $signature,
                'keeper' => $rowId,
                'duplicates' => [],
            ];
        }

        $this->pendingBucket = $currentBucket;

        return $buckets;
    }

    private function processBuckets(array $buckets, bool $shouldForce, bool $shouldRelink, array &$summary): array
    {
        $groupsInChunk = 0;
        $duplicatesInChunk = 0;
        $deletedInChunk = 0;
        $actionable = [];

        foreach ($buckets as $bucket) {
            $duplicateCount = count($bucket['duplicates']);
            if ($duplicateCount === 0) {
                continue;
            }

            $groupsInChunk++;
            $duplicatesInChunk += $duplicateCount;
            $summary['duplicate_groups']++;
            $summary['duplicate_rows'] += $duplicateCount;
            $actionable[] = $bucket;
        }

        if ($shouldForce && ! empty($actionable)) {
            $deletedInChunk = $this->deleteDuplicates($actionable, $shouldRelink, $summary);
            $summary['deleted'] += $deletedInChunk;
        }

        return [$groupsInChunk, $duplicatesInChunk, $deletedInChunk];
    }

    private function deleteDuplicates(array $buckets, bool $shouldRelink, array &$summary): int
    {
        $deleted = 0;

        DB::transaction(function () use (&$deleted, $buckets, $shouldRelink, &$summary) {
            foreach ($buckets as $bucket) {
                $duplicates = $bucket['duplicates'];
                if (empty($duplicates)) {
                    continue;
                }

                $keeperId = $bucket['keeper'];

                if ($shouldRelink) {
                    foreach (array_chunk($duplicates, self::DELETE_BATCH_SIZE) as $chunk) {
                        $updated = DB::table('class_sessions')
                            ->whereIn('school_class_id', $chunk)
                            ->update(['school_class_id' => $keeperId]);

                        $summary['sessions_relinked'] += $updated;
                    }
                }

                foreach (array_chunk($duplicates, self::DELETE_BATCH_SIZE) as $chunk) {
                    DB::table('school_classes')
                        ->whereIn('id', $chunk)
                        ->delete();

                    $deleted += count($chunk);
                }
            }
        });

        return $deleted;
    }

    private function finalizePendingBucket(bool $shouldForce, bool $shouldRelink, array &$summary): void
    {
        if ($this->pendingBucket === null) {
            return;
        }

        $bucket = $this->pendingBucket;
        $this->pendingBucket = null;

        $duplicateCount = count($bucket['duplicates']);
        if ($duplicateCount === 0) {
            return;
        }

        $summary['duplicate_groups']++;
        $summary['duplicate_rows'] += $duplicateCount;

        if ($shouldForce) {
            $deleted = $this->deleteDuplicates([$bucket], $shouldRelink, $summary);
            $summary['deleted'] += $deleted;
        }
    }

    private function detectSignatureIndex(): bool
    {
        try {
            $result = DB::select("SHOW INDEX FROM school_classes WHERE Key_name = 'school_classes_signature_index'");
            return ! empty($result);
        } catch (Throwable $e) {
            $this->warn('Could not determine signature index: ' . $e->getMessage());
            return false;
        }
    }

    private function determineCursorPadLength(): int
    {
        $maxId = (int) DB::table('school_classes')->max('id');
        if ($maxId <= 0) {
            $maxId = 1;
        }

        return max(8, strlen((string) $maxId));
    }

    private function cursorKeyFromRow($row): string
    {
        return $this->encodeCursor($row->external_source, $row->external_id, (int) $row->id);
    }

    /**
     * Signature excludes the auto-incrementing ID so true duplicates collapse into the same bucket.
     * The cursor retains the padded ID suffix to keep iteration deterministic across millions of rows.
     */
    private function encodeSignature(?string $source, ?string $externalId): string
    {
        $source = $source ?? 'acuity';
        $identifier = $externalId ?? 'legacy-missing';

        return sprintf('%s|%s', $source, $identifier);
    }

    private function encodeCursor(?string $source, ?string $externalId, int $id): string
    {
        return sprintf('%s|%s', $this->encodeSignature($source, $externalId), $this->padId($id));
    }

    private function padId(int $id): string
    {
        return str_pad((string) $id, $this->cursorPadLength, '0', STR_PAD_LEFT);
    }
}
