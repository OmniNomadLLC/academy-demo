<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Student;
use App\Services\AcuityService;
use App\Services\EmailNotifier;

class StudentsAuditAcuity extends Command
{
    protected $signature = 'students:audit-acuity
        {--stale-days=90 : Mark students stale if last class older than N days}
        {--page-size=500 : Acuity API page size}
        {--limit-missing=25 : Show up to N missing students}
        {--limit-duplicates=25 : Show up to N duplicates}
        {--limit-stale=25 : Show up to N stale students}
        {--email : Email the summary to ALERT_EMAIL_TO}
        {--clients-only : Use Acuity clients only; no fallback to appointments}
        {--export= : Export CSVs to storage/app/<name>-YYYYmmdd_HHMMSS}
    ';

    protected $description = 'Compare local students with Acuity clients; report missing, duplicates, and stale students.';

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');
        $staleDays = (int) $this->option('stale-days');
        $pageSize = min(max((int) $this->option('page-size'), 1), 1000);
        $limitMissing = (int) $this->option('limit-missing');
        $limitDup = (int) $this->option('limit-duplicates');
        $limitStale = (int) $this->option('limit-stale');
        $email = (bool) $this->option('email');
        $exportOpt = (string) ($this->option('export') ?? '');
        $clientsOnly = (bool) $this->option('clients-only');
        $exportDir = null;
        $fhMissing = $fhDupEmail = $fhDupCid = $fhStale = $fhSummary = null;
        if ($exportOpt !== '') {
            $suffix = date('Ymd_His');
            $rel = rtrim($exportOpt, '/').'-'.$suffix;
            $exportDir = rtrim(storage_path('app/'.$rel), '/');
            if (!is_dir($exportDir)) { @mkdir($exportDir, 0775, true); }
            // Prepare CSVs
            $fhMissing = @fopen($exportDir.'/missing.csv', 'w');
            if ($fhMissing) { fputcsv($fhMissing, ['id','name','email','acuity_client_id']); }
            $fhDupEmail = @fopen($exportDir.'/duplicates_emails.csv', 'w');
            if ($fhDupEmail) { fputcsv($fhDupEmail, ['email','ids']); }
            $fhDupCid = @fopen($exportDir.'/duplicates_client_ids.csv', 'w');
            if ($fhDupCid) { fputcsv($fhDupCid, ['acuity_client_id','ids']); }
            $fhStale = @fopen($exportDir.'/stale.csv', 'w');
            if ($fhStale) { fputcsv($fhStale, ['id','name','email','last_session']); }
            $fhSummary = @fopen($exportDir.'/summary.txt', 'w');
        }

        // Load Acuity clients (IDs + emails)
        $svc = new AcuityService();
        $acuityIds = [];
        $acuityEmails = [];
        $page = 1; $fetched = 0; $totalClients = 0;
        do {
            $list = $svc->getClients(['max' => $pageSize, 'page' => $page]);
            $n = is_array($list) ? count($list) : 0;
            $fetched += $n; $page++;
            foreach ($list as $c) {
                if (isset($c['id'])) { $acuityIds[(string)$c['id']] = true; $totalClients++; }
                $emailVal = $this->normalizeEmail($c['email'] ?? null);
                if ($emailVal) { $acuityEmails[$emailVal] = true; }
            }
        } while ($n === $pageSize && $page <= 10000);

        // Fallback: if clients endpoint returned none, derive identities from recent appointments
        $acuityApptIdentities = 0;
        if ($totalClients === 0 && $clientsOnly) {
            $this->error('Acuity Clients API returned 0 and --clients-only is set. Aborting.');
            return Command::FAILURE;
        }

        if ($totalClients === 0 && !$clientsOnly) {
            // Fallback: fetch a limited slice of recent appointments without aggregating all pages
            $perPage = 100; $maxPages = 10; // cap to avoid memory blowups
            $params = [ 'minDate' => now()->subDays(365)->format('Y-m-d') ];
            for ($p = 1; $p <= $maxPages; $p++) {
                try {
                    $slice = $svc->fetchAppointmentsPage($params, $p, $perPage);
                } catch (\Throwable $e) { $slice = []; }
                if (!is_array($slice) || count($slice) === 0) { break; }
                foreach ($slice as $a) {
                    if (isset($a['clientID'])) { $acuityIds[(string)$a['clientID']] = true; }
                    $e = $this->normalizeEmail($a['email'] ?? ($a['client']['email'] ?? null));
                    if ($e) { $acuityEmails[$e] = true; }
                }
                if (count($slice) < $perPage) { break; }
            }
            $acuityApptIdentities = count($acuityIds) + count($acuityEmails);
        }

        // Local totals
        $localCount = (int) DB::table('students')->count();

        // Compute duplicate emails (case-insensitive)
        $dupEmail = [];
        $emails = DB::table('students')->select('id','first_name','last_name','email')->whereNotNull('email')->get();
        $byEmail = [];
        foreach ($emails as $row) {
            $e = $this->normalizeEmail($row->email);
            if (!$e) continue;
            $byEmail[$e] = $byEmail[$e] ?? [];
            $byEmail[$e][] = $row;
        }
        foreach ($byEmail as $e => $listRows) {
            if (count($listRows) > 1) {
                $dupEmail[$e] = array_map(fn($r)=>$r->id, $listRows);
            }
        }

        // Duplicate acuity_client_id
        $dupClientId = [];
        $clientIds = DB::table('students')->select('id','first_name','last_name','acuity_client_id')->whereNotNull('acuity_client_id')->get();
        $byCid = [];
        foreach ($clientIds as $row) {
            $cid = (string) $row->acuity_client_id;
            $byCid[$cid] = $byCid[$cid] ?? [];
            $byCid[$cid][] = $row;
        }
        foreach ($byCid as $cid => $listRows) {
            if (count($listRows) > 1) { $dupClientId[$cid] = array_map(fn($r)=>$r->id, $listRows); }
        }

        // Missing in Acuity (stream to CSV if exporting)
        $missing = [];
        $missingCount = 0;
        Student::query()
            ->select('id','first_name','last_name','email','acuity_client_id')
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$missing, &$missingCount, $acuityIds, $acuityEmails, $limitMissing, $fhMissing) {
                foreach ($chunk as $s) {
                    $cid = $s->acuity_client_id ? (string) $s->acuity_client_id : null;
                    $email = $this->normalizeEmail($s->email ?? null);
                    $inByCid = ($cid && isset($acuityIds[$cid]));
                    $inByEmail = ($email && isset($acuityEmails[$email]));
                    if (!$inByCid && !$inByEmail) {
                        $missingCount++;
                        if (count($missing) < $limitMissing) {
                            $missing[] = [
                                'id' => $s->id,
                                'name' => trim(($s->first_name.' '.$s->last_name)),
                                'email' => $s->email,
                                'acuity_client_id' => $cid,
                            ];
                        }
                        if ($fhMissing) {
                            fputcsv($fhMissing, [$s->id, trim(($s->first_name.' '.$s->last_name)), $s->email, $cid]);
                        }
                    }
                }
            });

        // Stale students
        $stale = [];
        $staleCutoff = now()->subDays($staleDays)->toDateString();
        $staleCount = 0;
        Student::query()->select('id','first_name','last_name','email','acuity_client_id')->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$stale, &$staleCount, $staleCutoff, $limitStale, $fhStale) {
                foreach ($chunk as $s) {
                    $last = $this->lastSessionDateFor($s->id, $s->email, $s->acuity_client_id);
                    if ($last === null || $last < $staleCutoff) {
                        $staleCount++;
                        if (count($stale) < $limitStale) {
                            $stale[] = [
                                'id' => $s->id,
                                'name' => trim(($s->first_name.' '.$s->last_name)),
                                'email' => $s->email,
                                'last_session' => $last,
                            ];
                        }
                        if ($fhStale) {
                            fputcsv($fhStale, [$s->id, trim(($s->first_name.' '.$s->last_name)), $s->email, $last]);
                        }
                    }
                }
            });

        // Output summary
        $summary = [];
        $summary[] = sprintf('Local students: %d', $localCount);
        $summary[] = sprintf('Acuity clients: %d', $totalClients);
        if ($totalClients === 0 && $acuityApptIdentities > 0) {
            $summary[] = sprintf('Acuity identities from appointments (fallback): %d', $acuityApptIdentities);
        }
        $summary[] = sprintf('Missing in Acuity: %d', $missingCount);
        $summary[] = sprintf('Duplicate emails (local): %d', count($dupEmail));
        $summary[] = sprintf('Duplicate client IDs (local): %d', count($dupClientId));
        $summary[] = sprintf('Stale > %d days: %d', $staleDays, $staleCount);

        foreach ($summary as $line) { $this->info($line); }

        if (!empty($missing)) {
            $this->line('Sample missing (up to limit):');
            foreach ($missing as $row) {
                $this->line(sprintf('- #%d %s | %s | cid=%s', $row['id'], $row['name'], $row['email'] ?: '-', $row['acuity_client_id'] ?: '-'));
            }
        }
        if (!empty($dupEmail)) {
            $this->line('Duplicate emails:');
            $i = 0;
            foreach ($dupEmail as $emailVal => $ids) {
                $this->line(sprintf('- %s -> %s', $emailVal, implode(',', array_slice($ids, 0, 20))));
                if (++$i >= $limitDup) break;
                if ($fhDupEmail) { fputcsv($fhDupEmail, [$emailVal, implode('|', $ids)]); }
            }
        }
        if (!empty($dupClientId)) {
            $this->line('Duplicate client IDs:');
            $i = 0;
            foreach ($dupClientId as $cid => $ids) {
                $this->line(sprintf('- %s -> %s', $cid, implode(',', array_slice($ids, 0, 20))));
                if (++$i >= $limitDup) break;
                if ($fhDupCid) { fputcsv($fhDupCid, [$cid, implode('|', $ids)]); }
            }
        }
        if (!empty($stale)) {
            $this->line('Sample stale (up to limit):');
            foreach ($stale as $row) {
                $this->line(sprintf('- #%d %s | %s | last=%s', $row['id'], $row['name'], $row['email'] ?: '-', $row['last_session'] ?: 'never'));
            }
        }

        if ($email) {
            $body = implode("\n", $summary);
            (new EmailNotifier())->send('Students audit summary', $body);
        }

        if ($fhSummary) {
            foreach ($summary as $line) { fwrite($fhSummary, $line."\n"); }
        }

        // Close any open files
        foreach ([$fhMissing,$fhDupEmail,$fhDupCid,$fhStale,$fhSummary] as $fh) {
            if (is_resource($fh)) { fclose($fh); }
        }

        if ($exportDir) {
            $this->info('Export written to: '.$exportDir);
        }

        return Command::SUCCESS;
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) return null;
        $e = strtolower(trim($email));
        $e = preg_replace('/\s+/', '', $e);
        return $e !== '' ? $e : null;
    }

    private function lastSessionDateFor(int $studentId, ?string $email, ?string $acuityClientId): ?string
    {
        try {
            if (Schema::hasColumn('class_sessions', 'student_id')) {
                $last = DB::table('class_sessions')
                    ->where('student_id', $studentId)
                    ->where(function ($w) {
                        $w->where('canceled', false)->orWhereNull('canceled');
                    })
                    ->max('session_date');
                if ($last) return (string) $last;
            }
        } catch (\Throwable $e) {
            // ignore schema check failure
        }

        // Fallback by email or clientID from stored JSON
        if ($email) {
            $emailL = strtolower($email);
            $last = DB::table('class_sessions')
                ->where(function ($w) use ($emailL) {
                    $w->whereRaw('LOWER(COALESCE(student_email, ' . "''" . ')) = ?', [$emailL])
                      ->orWhereRaw("LOWER(json_extract(acuity_data, '$.email')) = ?", [$emailL])
                      ->orWhereRaw("LOWER(json_extract(acuity_data, '$.client.email')) = ?", [$emailL])
                      ->orWhereRaw("LOWER(json_extract(acuity_data, '$.clientEmail')) = ?", [$emailL])
                      ->orWhereRaw("LOWER(json_extract(acuity_data, '$.client.emailAddress')) = ?", [$emailL])
                      ->orWhereRaw("LOWER(json_extract(acuity_data, '$.ClientEmail')) = ?", [$emailL]);
                })
                ->max('session_date');
            if ($last) return (string) $last;
        }
        if ($acuityClientId) {
            $last = DB::table('class_sessions')
                ->whereRaw("json_extract(acuity_data, '$.clientID') = ?", [$acuityClientId])
                ->max('session_date');
            if ($last) return (string) $last;
        }
        return null;
    }
}
