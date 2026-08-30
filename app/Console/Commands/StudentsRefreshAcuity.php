<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Student;

class StudentsRefreshAcuity extends Command
{
    protected $signature = 'students:refresh-acuity {--days=90 : Lookback window in days} {--chunk=200}';
    protected $description = 'Refresh student acuity_category and location based on recent appointments only';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $chunk = (int) $this->option('chunk');
        $since = now()->subDays($days)->toDateString();

        $this->info("Refreshing from class sessions since {$since}...");

        $updated = 0; $cleared = 0; $unchanged = 0;

        Student::query()
            ->select(['id','acuity_client_id','first_name','last_name','email','acuity_category','location'])
            ->orderBy('id')
            ->chunkById($chunk, function ($students) use (&$updated, &$cleared, &$unchanged, $since) {
                foreach ($students as $student) {
                    $latestRecentCategory = DB::table('class_sessions')
                        ->whereDate('session_date', '>=', $since)
                        ->whereNotNull('acuity_data')
                        // Prefer direct relation via pivot
                        ->whereIn('id', function ($q) use ($student) {
                            $q->select('class_session_id')->from('attendance_records')->where('student_id', $student->id);
                        })
                        ->whereRaw("json_extract(acuity_data, '$.category') IS NOT NULL")
                        ->orderByDesc('session_date')
                        ->limit(1)
                        ->value(DB::raw("json_extract(acuity_data, '$.category')"));

                    $beforeCat = $student->acuity_category;
                    if ($latestRecentCategory) {
                        $student->setAcuityCategoryAndLocation($latestRecentCategory);
                        if ($student->isDirty(['acuity_category','location'])) {
                            $student->save();
                            $updated++;
                        } else {
                            $unchanged++;
                        }
                    } else {
                        // No recent category; clear and preserve location logic
                        if ($student->acuity_category !== null) {
                            $student->acuity_category = null;
                            $student->save();
                            $cleared++;
                        } else {
                            $unchanged++;
                        }
                    }
                }
            });

        $this->info("Done. Updated: {$updated}, Cleared: {$cleared}, Unchanged: {$unchanged}");
        $this->line('Categories after refresh:');
        $cats = Student::query()->whereNotNull('acuity_category')->distinct()->orderBy('acuity_category')->pluck('acuity_category');
        foreach ($cats as $c) $this->line(" - $c");

        return Command::SUCCESS;
    }
}

