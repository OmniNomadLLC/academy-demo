<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Acuity\AppointmentExtractor;
use App\Services\AcuityService;
use App\Services\LocationMappingService;
use App\Support\EmailNormalizer;
use App\Support\AcuitySchoolClassSynchronizer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AcuityDeltaSync extends Command
{
    public function __construct(protected AcuitySchoolClassSynchronizer $schoolClassSynchronizer)
    {
        parent::__construct();
    }

    protected $signature = 'acuity:delta-sync
      {--since= : Relative time like "-7 days"; ignored if --from is given}
      {--from= : ISO date/time start (Y-m-d or RFC3339)}
      {--to= : ISO date/time end (defaults now)}
      {--limit=0 : Max records per page/batch}
      {--chunk-days=1 : Split [from..to] into N-day chunks}
      {--pages=0 : Max pages per chunk (0 = unlimited)}
      {--page-size=200 : Page size for API calls}
      {--timeout=30 : HTTP timeout seconds}
      {--retries=3 : Retries per request with backoff}
      {--dry : Dry-run; log but don’t write}';

    protected $description = 'Delta-sync Acuity appointments and clients changed since a timestamp into class_sessions and students.

Examples:
  php artisan acuity:delta-sync --since=-2 hours
  php artisan acuity:delta-sync --from=2025-09-01 --to=2025-09-03 --limit=100
  php artisan "acuity:delta-sync" --from="2025-09-02T00:00:00Z" --dry';

    public function handle(): int
    {
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '1024M');
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        @ob_implicit_flush(true);

        $dry = (bool) $this->option('dry');
        $limitOpt = (int) $this->option('limit');
        $pageSize = (int) $this->option('page-size');
        if ($pageSize < 25) $pageSize = 25; if ($pageSize > 500) $pageSize = 500;

        [$from, $to] = $this->resolveWindow();
        $this->info(sprintf('Delta window: %s .. %s%s', $from->toIso8601String(), $to->toIso8601String(), $dry ? ' (dry-run)' : ''));

        $acuity = new AcuityService();
        // Configure HTTP from options
        $timeout = (int) $this->option('timeout');
        $retries = (int) $this->option('retries');
        if ($retries < 0) $retries = 0; if ($retries > 10) $retries = 10;
        if (method_exists($acuity, 'setTimeouts')) { $acuity->setTimeouts($timeout, 10); }
        if (method_exists($acuity, 'setRetryConfig')) { $acuity->setRetryConfig($retries, 250); }

        $errors = 0;
        $apptFetched = 0; $apptUpserted = 0; $apptSkipped = 0;
        $clientFetched = 0; $clientUpserted = 0; $clientSkipped = 0;
        $pagesCap = (int) $this->option('pages');
        $chunkDays = max(1, (int) $this->option('chunk-days'));

        $this->info(sprintf('Delta window: %s .. %s; chunk-days=%d; page-size=%d%s', $from->toDateString(), $to->toDateString(), $chunkDays, $pageSize, $dry ? ' (dry-run)' : ''));

        // 1) First pass: fetch appointments modified since window start; fallback to date window
        $globalStart = hrtime(true);

        $firstPassProcessed = false;
        try {
            // Prefer Acuity's modifiedSince/lastUpdated filters to catch reschedules/cancellations
            $sinceIso = $from->toIso8601String();
            $attempt = 0; $changed = [];
            do {
                try {
                    $changed = $acuity->getAppointments(['lastUpdated' => $sinceIso, 'max' => $pageSize]) ?? [];
                    if (empty($changed)) {
                        $changed = $acuity->getAppointments(['modifiedSince' => $sinceIso, 'max' => $pageSize]) ?? [];
                    }
                    break;
                } catch (\Throwable $ex) {
                    $attempt++;
                    if ($attempt > $retries) { throw $ex; }
                    usleep((int) (250 * (2 ** ($attempt - 1)) * 1000));
                }
            } while (true);

            if (is_array($changed) && count($changed) > 0) {
                $firstPassProcessed = true;
                $pageFetched = count($changed);
                $this->info(sprintf('[modifiedSince] %d changed appointments since %s', $pageFetched, $sinceIso));
                $apptFetched += $pageFetched;
                DB::transaction(function () use ($changed, $dry, &$apptUpserted, &$apptSkipped) {
                    foreach ($changed as $appointmentData) {
                        try {
                            $this->upsertAppointment($appointmentData, $dry, $apptUpserted, $apptSkipped);
                        } catch (\Throwable $e) {
                            $this->warn('Appointment upsert error: '.$e->getMessage());
                        }
                    }
                });
            } else {
                $this->info('[modifiedSince] no changes returned; falling back to date window');
            }
        } catch (\Throwable $e) {
            $errors++;
            $this->warn('[modifiedSince] fetch failed: '.$e->getMessage());
        }

        // 2) Process by date chunks only if first pass did not return anything (safe fallback)
        $cursor = $from->copy();
        while (!$firstPassProcessed && $cursor <= $to) {
            $chunkStart = $cursor->copy();
            $chunkEnd = $cursor->copy()->addDays($chunkDays - 1);
            if ($chunkEnd > $to) { $chunkEnd = $to->copy(); }
            $minDate = $chunkStart->toDateString();
            $maxDate = $chunkEnd->toDateString();

            $this->info(sprintf('Chunk %s..%s starting', $minDate, $maxDate));

            $chunkFetched = 0; $chunkUpserted = 0; $chunkSkipped = 0; $chunkErrors = 0;
            $page = 1; $lastFingerprint = null; $stuck = 0;
            do {
                // Per-page retry loop
                $attempt = 0; $pageData = [];
                do {
                    try {
                        $pageData = $acuity->fetchAppointmentsPage(['minDate' => $minDate, 'maxDate' => $maxDate], $page, $pageSize);
                        break;
                    } catch (\Throwable $ex) {
                        $attempt++;
                        if ($attempt > $retries) { throw $ex; }
                        usleep((int) (250 * (2 ** ($attempt - 1)) * 1000));
                    }
                } while (true);

                $count = is_array($pageData) ? count($pageData) : 0;
                $chunkFetched += $count; $apptFetched += $count;
                $fingerprint = sha1(json_encode(array_map(fn($r) => $r['id'] ?? null, $pageData)));
                $stuck = ($fingerprint === $lastFingerprint) ? ($stuck + 1) : 0;
                $lastFingerprint = $fingerprint;

                $this->info(sprintf('chunk %s..%s | page %d | got %d', $minDate, $maxDate, $page, $count));
                @ob_flush(); @flush();

                if ($count > 0) {
                    DB::transaction(function () use ($pageData, $dry, &$chunkUpserted, &$chunkSkipped) {
                        foreach ($pageData as $appointmentData) {
                            try {
                                $this->upsertAppointment($appointmentData, $dry, $chunkUpserted, $chunkSkipped);
                            } catch (\Throwable $e) {
                                $this->warn('Appointment upsert error: '.$e->getMessage());
                            }
                        }
                    });
                }

                // Exit guards
                if ($count < $pageSize) { break; }
                if ($pagesCap > 0 && $page >= $pagesCap) { break; }
                if ($stuck >= 2) { $this->warn('No progress detected, breaking.'); break; }
                $page++;
                if ($limitOpt > 0 && $apptFetched >= $limitOpt) { break; }
            } while (true);

            $apptUpserted += $chunkUpserted; $apptSkipped += $chunkSkipped;
            $this->info(sprintf('Chunk done %s..%s | fetched=%d upserted=%d skipped=%d errors=%d', $minDate, $maxDate, $chunkFetched, $chunkUpserted, $chunkSkipped, $chunkErrors));
            @ob_flush(); @flush();

            if ($limitOpt > 0 && $apptFetched >= $limitOpt) { break; }
            $cursor = $chunkEnd->copy()->addDay();
        }

        // 2) Fetch clients changed since (single request with retry/backoff)
        $clients = [];
        try {
            $sinceIso = $from->toIso8601String();
            $attempt = 0;
            do {
                try {
                    $clients = $acuity->getClients(['lastUpdated' => $sinceIso, 'max' => $pageSize]) ?? [];
                    if (empty($clients)) {
                        $clients = $acuity->getClients(['modifiedSince' => $sinceIso, 'max' => $pageSize]) ?? [];
                    }
                    break;
                } catch (\Throwable $ex) {
                    $attempt++;
                    if ($attempt > $retries) { throw $ex; }
                    usleep((int) (250 * (2 ** ($attempt - 1)) * 1000));
                }
            } while (true);
        } catch (\Throwable $e) {
            $errors++;
            $this->error('Clients fetch failed: '.$e->getMessage());
        }
        $clientFetched = is_array($clients) ? count($clients) : 0;
        $this->info(sprintf('Clients fetched: %d', $clientFetched));

        // 3) Upsert clients into students (chunked, transactional)
        $clientChunks = array_chunk($clients, 200);
        foreach ($clientChunks as $chunk) {
            try {
                DB::transaction(function () use ($chunk, $dry, &$clientUpserted, &$clientSkipped) {
                    foreach ($chunk as $clientData) {
                        try {
                            $this->upsertClient($clientData, $dry, $clientUpserted, $clientSkipped);
                        } catch (\Throwable $e) {
                            $this->warn('Client upsert error: '.$e->getMessage());
                        }
                    }
                });
            } catch (\Throwable $e) {
                $errors++;
                $this->error('Chunk transaction failed (clients): '.$e->getMessage());
            }
        }

        // 4) Backfill metadata to ensure calendar_norm/category_norm/location populated
        try {
            $this->call('backfill:class-session-metadata', [
                '--limit' => 0,
                '--dry' => $dry,
            ]);
        } catch (\Throwable $e) {
            $errors++;
            $this->warn('Backfill metadata failed: '.$e->getMessage());
        }

        // Totals
        $elapsedMs = (int) ((hrtime(true) - $globalStart) / 1e6);
        $this->info(sprintf('Totals: appts fetched=%d upserted=%d skipped=%d | clients fetched=%d upserted=%d skipped=%d | errors=%d | elapsed_ms=%d',
            $apptFetched, $apptUpserted, $apptSkipped, $clientFetched, $clientUpserted, $clientSkipped, $errors, $elapsedMs));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveWindow(): array
    {
        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');
        $sinceOpt = $this->option('since');

        $now = Carbon::now();
        $from = null; $to = null;
        try {
            if ($fromOpt) {
                $from = Carbon::parse($fromOpt);
            }
        } catch (\Throwable $e) {
            $this->warn('Invalid --from; defaulting to -7 days. Error: '.$e->getMessage());
        }
        if (!$from && $sinceOpt) {
            $ts = @strtotime($sinceOpt);
            if ($ts !== false) { $from = Carbon::createFromTimestamp($ts); }
        }
        if (!$from) { $from = $now->copy()->subDays(7); }

        try {
            $to = $toOpt ? Carbon::parse($toOpt) : $now;
        } catch (\Throwable $e) {
            $to = $now;
        }
        if ($to->lessThan($from)) { $to = $from->copy()->addMinute(); }
        return [$from, $to];
    }

    private function upsertAppointment(array $appointmentData, bool $dry, int &$upserted, int &$skipped): void
    {
        if (empty($appointmentData['id']) || empty($appointmentData['datetime'])) {
            return; // skip invalid
        }

        // Extract tolerant fields
        $ex = AppointmentExtractor::extract($appointmentData);
        $category = $ex['category'] ?? ($appointmentData['category'] ?? null);
        $location = $category ? LocationMappingService::getLocationFromCategory($category) : null;

        // Teacher mapping via calendar id (best-effort)
        $calendarId = data_get($appointmentData, 'calendarID')
            ?? data_get($appointmentData, 'calendarId')
            ?? data_get($appointmentData, 'calendar.id')
            ?? (is_scalar(data_get($appointmentData, 'calendar')) ? data_get($appointmentData, 'calendar') : null);
        $teacher = null;
        if ($calendarId) {
            $teacher = User::whereIn('role', User::TEACHING_ROLES)
                ->where('is_active', true)
                ->where(function ($query) use ($calendarId) {
                    $calendarId = (string) $calendarId;
                    $query->where('acuity_calendar_id', $calendarId)
                        ->orWhereJsonContains('teacher_calendar_ids', $calendarId);
                })
                ->first();
        }
        if (!$teacher) {
            $teacher = User::whereIn('role', User::TEACHING_ROLES)->where('is_active', true)->first()
                ?? User::where('role', 'admin')->first();
        }

        // Ensure a school class exists for this calendar/type
        $schoolClass = $this->findOrCreateSchoolClass($appointmentData, $location ?? 'UK');

        // Date/time normalized to appointment timezone if provided
        $start = Carbon::parse($appointmentData['datetime']);
        $tz = data_get($appointmentData, 'timezone');
        if (is_string($tz) && trim($tz) !== '') {
            $start = $start->setTimezone($tz);
        }
        $end = (clone $start)->addMinutes((int) ($appointmentData['duration'] ?? 60));

        $email = EmailNormalizer::fromAcuity($appointmentData) ?? ($ex['clientEmail'] ?? null);

        $status = $this->mapAcuityStatus($appointmentData);
        $update = [
            'school_class_id' => $schoolClass->id,
            'teacher_id' => $teacher ? $teacher->id : null,
            'session_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'status' => $status,
            'canceled' => (bool)($appointmentData['canceled'] ?? false),
            'location' => $location ?? null,
            'calendar_name' => $ex['calendar'] ?? ($appointmentData['calendar'] ?? null),
            'calendar_norm' => $ex['calendar_norm'] ?? null,
            'category_norm' => $ex['category_norm'] ?? ($appointmentData['category'] ?? null),
            'client_email' => $email ?: null,
            'student_email' => $email ?: null,
            'max_students' => 10,
            'notes' => $appointmentData['notes'] ?? null,
            'acuity_data' => $appointmentData,
        ];
        // Optionally include student_id if column exists
        try {
            if (Schema::hasColumn('class_sessions', 'student_id')) {
                // Best-effort linking: maintain existing student_id if already present
                $existing = ClassSession::where('acuity_appointment_id', (string) $appointmentData['id'])->first();
                if ($existing && isset($existing->student_id)) {
                    $update['student_id'] = $existing->student_id;
                }
            }
        } catch (\Throwable $e) {}

        // Idempotency: skip if unchanged on key fields
        $existing = ClassSession::where('acuity_appointment_id', (string) $appointmentData['id'])->first();
        if ($existing) {
            $cmp = [
                'session_date' => $existing->session_date ? Carbon::parse($existing->session_date)->toDateString() : null,
                'start_time' => (string) $existing->start_time,
                'end_time' => (string) $existing->end_time,
                'status' => (string) $existing->status,
                'calendar_name' => (string) ($existing->calendar_name ?? ''),
                'calendar_norm' => (string) ($existing->calendar_norm ?? ''),
                'category_norm' => (string) ($existing->category_norm ?? ''),
                'location' => (string) ($existing->location ?? ''),
            ];
            $cmpNew = [
                'session_date' => $update['session_date'],
                'start_time' => $update['start_time'],
                'end_time' => $update['end_time'],
                'status' => $update['status'],
                'calendar_name' => (string) ($update['calendar_name'] ?? ''),
                'calendar_norm' => (string) ($update['calendar_norm'] ?? ''),
                'category_norm' => (string) ($update['category_norm'] ?? ''),
                'location' => (string) ($update['location'] ?? ''),
            ];
            if ($cmp == $cmpNew) {
                $skipped++;
                return;
            }
        }

        if ($dry) {
            $skipped++; // treat as skipped in dry-run for write
            return;
        }

        ClassSession::updateOrCreate(
            ['acuity_appointment_id' => (string) $appointmentData['id']],
            $update
        );
        $upserted++;
    }

    private function upsertClient(array $clientData, bool $dry, int &$upserted, int &$skipped): void
    {
        // Tolerant extraction
        $acuityId = $clientData['id']
            ?? $clientData['clientID']
            ?? $clientData['clientId']
            ?? $clientData['ClientID']
            ?? $clientData['ClientId']
            ?? null;
        $email = $clientData['email']
            ?? data_get($clientData, 'client.email')
            ?? data_get($clientData, 'Client.email')
            ?? null;
        $emailNorm = $email ? strtolower(trim($email)) : null;

        $student = null;
        if (!empty($acuityId)) {
            $student = Student::where('acuity_client_id', (string) $acuityId)->first();
        }
        if (!$student && !empty($emailNorm)) {
            $student = Student::where('email_norm', $emailNorm)->first();
        }

        $payload = [
            'first_name' => $clientData['firstName'] ?? ($student->first_name ?? ''),
            'last_name' => $clientData['lastName'] ?? ($student->last_name ?? ''),
            'email' => $email ?? ($student->email ?? null),
            'email_norm' => $emailNorm,
            'phone' => $clientData['phone'] ?? ($student->phone ?? null),
            'notes' => $clientData['notes'] ?? ($student->notes ?? null),
        ];

        if ($student) {
            // Idempotency check on tracked fields
            $cmp = [
                'first_name' => (string) $student->first_name,
                'last_name' => (string) $student->last_name,
                'email' => (string) ($student->email ?? ''),
                'phone' => (string) ($student->phone ?? ''),
                'notes' => (string) ($student->notes ?? ''),
            ];
            $cmpNew = [
                'first_name' => (string) ($payload['first_name'] ?? ''),
                'last_name' => (string) ($payload['last_name'] ?? ''),
                'email' => (string) ($payload['email'] ?? ''),
                'phone' => (string) ($payload['phone'] ?? ''),
                'notes' => (string) ($payload['notes'] ?? ''),
            ];
            if ($cmp == $cmpNew) {
                $skipped++;
                return;
            }
            if ($dry) { $skipped++; return; }
            if (!empty($acuityId) && empty($student->acuity_client_id)) {
                $student->acuity_client_id = (string) $acuityId;
            }
            $student->fill($payload);
            $student->save();
            $upserted++;
        } else {
            if ($dry) { $skipped++; return; }
            // Prefer upsert by acuity_client_id; fallback to email_norm; otherwise skip
            if (!empty($acuityId)) {
                $student = Student::updateOrCreate(
                    ['acuity_client_id' => (string) $acuityId],
                    $payload + [
                        'registration_date' => now()->toDateString(),
                        'is_active' => true,
                    ]
                );
                $upserted++;
            } elseif (!empty($emailNorm)) {
                $student = Student::updateOrCreate(
                    ['email_norm' => $emailNorm],
                    $payload + [
                        'registration_date' => now()->toDateString(),
                        'is_active' => true,
                    ]
                );
                $upserted++;
            } else {
                // No stable key; skip to avoid duplicates
                $skipped++;
                return;
            }
        }
    }

    private function findOrCreateSchoolClass(array $appointmentData, string $location): SchoolClass
    {
        $appointmentTypeName = $appointmentData['type'] ?? ($appointmentData['appointmentType'] ?? 'General Class');
        $calendarId = data_get($appointmentData, 'calendarID')
            ?? data_get($appointmentData, 'calendarId')
            ?? data_get($appointmentData, 'calendar.id')
            ?? (is_scalar(data_get($appointmentData, 'calendar')) ? data_get($appointmentData, 'calendar') : null);
        $calendarName = data_get($appointmentData, 'calendar')
            ?? data_get($appointmentData, 'calendarName')
            ?? data_get($appointmentData, 'calendar.name');

        return $this->schoolClassSynchronizer->syncFromAppointment($appointmentData, [
            'location' => $location,
        ]);
    }

    private function mapAcuityStatus(array $appointmentData): string
    {
        try {
            $datetime = Carbon::parse($appointmentData['datetime']);
        } catch (\Throwable $e) {
            return 'scheduled';
        }
        if (!empty($appointmentData['canceled'])) {
            return 'cancelled';
        }
        return $datetime->isPast() ? 'completed' : 'scheduled';
    }
}
