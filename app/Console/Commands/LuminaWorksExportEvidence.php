<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\LuminaWorksActivityLog;
use App\Models\LuminaWorksApplication;
use App\Models\Student;
use App\Services\LuminaWorks\EvidenceLogger;
use Illuminate\Console\Command;
use ZipArchive;

class LuminaWorksExportEvidence extends Command
{
    protected $signature = 'luminaworks:export-evidence
        {student : Student id}
        {--from= : Include activity from this date (Y-m-d)}
        {--to= : Include activity up to this date (Y-m-d)}';

    protected $description = 'Export a per-student evidence bundle (lessons, applications, activity trail) as a zip (Lumina Works)';

    public function handle(EvidenceLogger $logger): int
    {
        if (!config('luminaworks.enabled')) {
            $this->warn('Lumina Works is disabled (LUMINAWORKS_ENABLED=false).');

            return self::SUCCESS;
        }

        $student = Student::withTrashed()->findOrFail((int) $this->argument('student'));
        $from = $this->option('from') ? \Carbon\Carbon::parse($this->option('from'))->startOfDay() : null;
        $to = $this->option('to') ? \Carbon\Carbon::parse($this->option('to'))->endOfDay() : null;

        // 1. Verify the chain BEFORE exporting; a broken chain must be visible.
        $broken = $logger->verifyChain($student->id);

        // 2. Collect the three evidence streams.
        $activity = LuminaWorksActivityLog::where('student_id', $student->id)
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->orderBy('id')
            ->get();

        $applications = LuminaWorksApplication::with('job')
            ->where('student_id', $student->id)
            ->orderBy('applied_at')
            ->get();

        $attendance = AttendanceRecord::with('classSession.schoolClass')
            ->where('student_id', $student->id)
            ->when($from, fn ($q) => $q->whereHas('classSession', fn ($s) => $s->where('session_date', '>=', $from)))
            ->when($to, fn ($q) => $q->whereHas('classSession', fn ($s) => $s->where('session_date', '<=', $to)))
            ->get()
            ->sortBy(fn ($r) => [$r->classSession?->session_date, $r->classSession?->start_time]);

        // 3. Build the zip.
        $dir = storage_path('app/luminaworks_evidence');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . '/evidence-student-' . $student->id . '-' . now()->format('Ymd-His') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            $this->error("Cannot create {$path}");

            return self::FAILURE;
        }

        $zip->addFromString('activity-log.csv', $this->toCsv(
            ['id', 'occurred_at', 'event_type', 'description', 'actor_role', 'prev_hash', 'hash'],
            $activity->map(fn ($a) => [
                $a->id,
                $a->occurred_at->toDateTimeString(),
                $a->event_type,
                $a->description,
                $a->actor_role,
                $a->prev_hash,
                $a->hash,
            ])
        ));

        $zip->addFromString('applications.csv', $this->toCsv(
            ['id', 'job_title', 'employer', 'status', 'applied_at', 'interview_at', 'outcome_at'],
            $applications->map(fn ($a) => [
                $a->id,
                $a->job?->title,
                $a->job?->employer_name,
                $a->status,
                $a->applied_at?->toDateTimeString(),
                $a->interview_at?->toDateTimeString(),
                $a->outcome_at?->toDateTimeString(),
            ])
        ));

        $zip->addFromString('lessons-attendance.csv', $this->toCsv(
            ['session_date_time', 'class', 'status', 'marked_at'],
            $attendance->map(fn ($r) => [
                ($r->classSession?->session_date?->toDateString() . ' ' . $r->classSession?->start_time),
                $r->classSession?->schoolClass?->name,
                $r->status,
                $r->marked_at,
            ])
        ));

        $zip->addFromString('summary.json', json_encode([
            'student_id' => $student->id,
            'student_name' => trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')),
            'generated_at' => now()->toIso8601String(),
            'window' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'counts' => [
                'activity_events' => $activity->count(),
                'applications' => $applications->count(),
                'lessons' => $attendance->count(),
                'lessons_present' => $attendance->where('status', 'present')->count(),
            ],
            'evidence_chain' => [
                'intact' => $broken === [],
                'broken_row_ids' => $broken,
                'last_hash' => $activity->last()?->hash,
            ],
            'note' => 'Activity log rows are hash-chained (SHA-256). Recompute per row to independently verify integrity.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $zip->close();

        if ($broken !== []) {
            $this->warn('WARNING: evidence chain NOT intact for rows: ' . implode(', ', $broken));
        }

        $this->info("Evidence bundle: {$path}");
        $this->line("Events: {$activity->count()} | Applications: {$applications->count()} | Lessons: {$attendance->count()} | Chain intact: " . ($broken === [] ? 'yes' : 'NO'));

        return self::SUCCESS;
    }

    private function toCsv(array $header, $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
