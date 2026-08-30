<?php

namespace App\Console\Commands;

use App\Models\AcuitySyncLog;
use App\Models\Student;
use App\Models\ClassSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncHealthCheck extends Command
{
    protected $signature = 'acuity:health-check';
    protected $description = 'Check sync system health and performance';

    public function handle()
    {
        $this->info('Running Acuity Sync Health Check...');
        
        // Check recent sync activity
        $recentSyncs = AcuitySyncLog::where('created_at', '>=', now()->subHour())->count();
        $failedSyncs = AcuitySyncLog::where('created_at', '>=', now()->subDay())
            ->where('status', 'failed')->count();
        
        // Check queue health
        $queuedJobs = DB::table('jobs')->where('queue', 'acuity-sync')->count();
        $failedJobs = DB::table('failed_jobs')->whereDate('failed_at', today())->count();
        
        // Check data freshness
        $recentStudents = Student::where('updated_at', '>=', now()->subHour())->count();
        $recentSessions = ClassSession::where('updated_at', '>=', now()->subHour())->count();
        
        // Display health report
        $this->table(['Metric', 'Value', 'Status'], [
            ['Recent Syncs (1hr)', $recentSyncs, $recentSyncs > 0 ? 'OK' : 'WARN: no activity'],
            ['Failed Syncs (24hr)', $failedSyncs, $failedSyncs === 0 ? 'OK' : 'FAIL: issues'],
            ['Queued Jobs', $queuedJobs, $queuedJobs < 10 ? 'OK' : 'WARN: high load'],
            ['Failed Jobs (today)', $failedJobs, $failedJobs === 0 ? 'OK' : 'FAIL: issues'],
            ['Updated Students (1hr)', $recentStudents, $recentStudents > 0 ? 'OK' : 'WARN: stale'],
            ['Updated Sessions (1hr)', $recentSessions, $recentSessions > 0 ? 'OK' : 'WARN: stale'],
        ]);
        
        // Overall health assessment
        $issues = $failedSyncs + $failedJobs;
        if ($issues === 0) {
            $this->info('System Health: EXCELLENT - all systems operational');
        } elseif ($issues < 5) {
            $this->warn('System Health: GOOD - minor issues detected');
        } else {
            $this->error('System Health: POOR - multiple issues need attention');
        }
        
        return Command::SUCCESS;
    }
}