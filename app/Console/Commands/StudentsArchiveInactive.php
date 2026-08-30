<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentsArchiveInactive extends Command
{
    protected $signature = 'students:archive-inactive
        {--dry-run : Report candidates without writing}
        {--threshold-weeks= : Override config threshold}';

    protected $description = 'Archive UK students with no recent activity and no future classes';

    public function handle(): int
    {
        $weeks = (int) ($this->option('threshold-weeks')
            ?? config('students.inactivity_threshold_weeks', 4));

        if ($weeks <= 0) {
            $this->error('threshold-weeks must be a positive integer.');
            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();
        $todayStr = $today->toDateString();
        $thresholdDate = $today->copy()->subWeeks($weeks)->toDateString();

        $this->info(sprintf(
            'Threshold: %d weeks (date %s)%s',
            $weeks,
            $thresholdDate,
            $dryRun ? ' (DRY-RUN)' : ''
        ));

        $candidates = Student::forRegion('UK')
            ->whereNull('archived_at')
            ->select(['id', 'first_name', 'last_name', 'email'])
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No UK students found.');
            return Command::SUCCESS;
        }

        $ids = $candidates->pluck('id')->all();

        $activityQuery = DB::table('class_sessions as cs')
            ->join('attendance_records as ar', 'ar.class_session_id', '=', 'cs.id')
            ->whereIn('ar.student_id', $ids)
            ->where(function ($w) {
                $w->where('cs.canceled', false)->orWhereNull('cs.canceled');
            })
            ->groupBy('ar.student_id')
            ->select(
                'ar.student_id',
                DB::raw('MAX(cs.session_date) AS last_activity_date'),
                DB::raw('SUM(CASE WHEN cs.session_date > ? THEN 1 ELSE 0 END) AS future_count')
            )
            ->addBinding($todayStr, 'select');

        ClassSession::applyExcludeAssessmentsToQuery($activityQuery, 'cs');

        $activity = $activityQuery->get()->keyBy('student_id');

        $archiveCandidates = [];
        foreach ($candidates as $student) {
            $row = $activity->get($student->id);
            if (! $row || ! $row->last_activity_date) {
                continue;
            }

            $lastActivity = Carbon::parse($row->last_activity_date);
            $futureCount = (int) ($row->future_count ?? 0);

            $lastActivityDate = $lastActivity->toDateString();
            $isInactive = $lastActivityDate < $thresholdDate;
            $hasNoFuture = $futureCount === 0;

            if ($isInactive && $hasNoFuture) {
                $daysInactive = $lastActivity->diffInDays($today);
                $archiveCandidates[] = [
                    'id' => $student->id,
                    'name' => trim($student->first_name.' '.$student->last_name),
                    'email' => $student->email ?? '',
                    'last_activity' => $lastActivityDate,
                    'days_inactive' => (int) $daysInactive,
                    'student' => $student,
                ];
            }
        }

        $total = count($archiveCandidates);

        if ($total > 0) {
            $rowsToShow = array_slice($archiveCandidates, 0, 50);
            $this->table(
                ['ID', 'Name', 'Email', 'Last activity', 'Days inactive'],
                array_map(
                    fn ($c) => [$c['id'], $c['name'], $c['email'], $c['last_activity'], $c['days_inactive']],
                    $rowsToShow
                )
            );
            if ($total > 50) {
                $this->line(sprintf('...and %d more', $total - 50));
            }
        }

        if (! $dryRun) {
            foreach ($archiveCandidates as $c) {
                $c['student']->update([
                    'archived_at' => now(),
                    'archived_reason' => 'inactivity',
                ]);

                Log::warning(sprintf(
                    'Archived student #%d (%s, %s) for inactivity. Last activity: %s.',
                    $c['id'],
                    $c['name'],
                    $c['email'],
                    $c['last_activity']
                ));
            }
        }

        $this->info($dryRun
            ? sprintf('Would archive %d students (dry-run)', $total)
            : sprintf('Archived %d students', $total));

        return Command::SUCCESS;
    }
}
