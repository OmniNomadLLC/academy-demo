<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StudentsDedupe extends Command
{
    protected $signature = 'students:dedupe
        {--by=id : Strategy: id|email}
        {--dry : Dry run}
        {--limit=0 : Max merge groups}
        {--only= : Target a single email (for --by=email)}
        {--client-id= : Target a single acuity_client_id (for --by=id)}';

    protected $description = 'Deduplicate Students by Acuity ID or normalized email, migrating references and merging fields.';

    public function handle(): int
    {
        $strategy = strtolower((string) $this->option('by')) ?: 'id';
        if (!in_array($strategy, ['id', 'email'])) {
            $this->error('Invalid --by strategy. Use id|email');
            return self::INVALID;
        }
        $dry = (bool) $this->option('dry');
        $limit = (int) $this->option('limit');
        $onlyEmail = $this->option('only');
        $onlyClientId = $this->option('client-id');

        $this->info(sprintf('Students Dedupe: strategy=%s dry=%s limit=%d', $strategy, $dry ? 'yes' : 'no', $limit));

        // Build duplicate groups
        if ($strategy === 'id') {
            $q = DB::table('students')
                ->select('acuity_client_id', DB::raw('COUNT(*) as cnt'))
                ->whereNotNull('acuity_client_id')
                ->whereRaw("TRIM(acuity_client_id) <> ''")
                ->groupBy('acuity_client_id')
                ->havingRaw('COUNT(*) > 1')
                ->orderByDesc('cnt');
            if ($onlyClientId) { $q->where('acuity_client_id', (string) $onlyClientId); }
        } else { // email strategy uses email_norm and only non-null/non-empty
            $q = DB::table('students')
                ->selectRaw('email_norm as email_lc, COUNT(*) as cnt')
                ->whereNotNull('email_norm')
                ->whereRaw("TRIM(email_norm) <> ''")
                ->groupBy('email_norm')
                ->havingRaw('COUNT(*) > 1')
                ->orderByDesc('cnt');
            if ($onlyEmail) { $q->whereRaw('email_norm = ?', [strtolower(trim($onlyEmail))]); }
        }
        if ($limit > 0) { $q->limit($limit); }
        $groups = $q->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate groups found.');
            return self::SUCCESS;
        }

        $this->info('Duplicate groups: '.count($groups));

        $procGroups = 0; $merged = 0; $fksUpdated = 0; $deleted = 0; $errors = 0;
        $hasClassStudent = Schema::hasColumn('class_sessions', 'student_id');

        foreach ($groups as $g) {
            $groupKey = $strategy === 'id' ? $g->acuity_client_id : $g->email_lc;
            $this->line("Group: ".$groupKey.' (count='.$g->cnt.')');

            // Load all records in group
            if ($strategy === 'id') {
                $rows = DB::table('students')->where('acuity_client_id', $groupKey)->orderBy('id')->get();
            } else {
                $rows = DB::table('students')->where('email_norm', $groupKey)->orderBy('id')->get();
            }
            if ($rows->count() < 2) { continue; }

            // Choose canonical: prefer oldest created_at if present; fallback to lowest id
            $canonical = $rows
                ->sortBy(function ($r) { return [$r->created_at ?? '1970-01-01 00:00:00', $r->id]; })
                ->first();
            $canonicalId = $canonical->id;
            $others = $rows->filter(fn($r) => $r->id !== $canonicalId)->values();

            // Compute merged fields, preferring most recent non-null from others
            $fields = ['first_name', 'last_name', 'phone'];
            $mergedData = [];
            // Build descending recency list (updated_at desc, created_at desc, id desc)
            $sortedByRecent = $rows->sortByDesc(function ($r) {
                return [
                    $r->updated_at ?? $r->created_at ?? '1970-01-01 00:00:00',
                    $r->created_at ?? '1970-01-01 00:00:00',
                    $r->id,
                ];
            });
            foreach ($fields as $f) {
                $value = null;
                foreach ($sortedByRecent as $r) {
                    $v = $r->{$f} ?? null;
                    if ($v !== null && trim((string) $v) !== '') { $value = $v; break; }
                }
                // Keep canonical email only; do not override email
                if ($value !== null && trim((string) $value) !== '') {
                    $mergedData[$f] = $value;
                }
            }

            // Keep canonical email as-is; email_norm will follow via model hook or DB computed column

            $this->line(sprintf('Canonical #%d; merging %d duplicates', $canonicalId, $others->count()));
            if ($dry) { $procGroups++; $merged += $others->count(); continue; }

            try {
                DB::transaction(function () use ($canonicalId, $others, $hasClassStudent, $mergedData, &$fksUpdated, &$deleted) {
                    // Update merged fields on canonical
                    if (!empty($mergedData)) {
                        DB::table('students')->where('id', $canonicalId)->update($mergedData);
                    }

                    $otherIds = $others->pluck('id')->all();
                    // Reassign attendance_records
                    $fksUpdated += DB::table('attendance_records')->whereIn('student_id', $otherIds)->update(['student_id' => $canonicalId]);
                    // Reassign class_sessions.student_id if present
                    if ($hasClassStudent) {
                        $fksUpdated += DB::table('class_sessions')->whereIn('student_id', $otherIds)->update(['student_id' => $canonicalId]);
                    }

                    // Delete duplicates
                    $deleted += DB::table('students')->whereIn('id', $otherIds)->delete();
                });
                $procGroups++; $merged += $others->count();
            } catch (\Throwable $e) {
                $errors++;
                Log::error('students:dedupe group failed', [
                    'key' => $groupKey,
                    'error' => $e->getMessage(),
                ]);
                $this->warn('Group failed: '.$e->getMessage());
            }
        }

        $this->info(sprintf('Summary: groups=%d merged=%d fks_updated=%d deleted=%d errors=%d%s',
            $procGroups, $merged, $fksUpdated, $deleted, $errors, $dry ? ' (dry run)' : ''));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
