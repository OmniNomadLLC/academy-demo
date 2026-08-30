<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Student;

class BackfillPerfColumns extends Command
{
    protected $signature = 'app:backfill-perf-columns {--chunk=1000}';
    protected $description = 'Backfill class_sessions.category_norm and students first/last appointment dates';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');

        $this->info('Backfilling class_sessions.category_norm...');
        DB::table('class_sessions')->orderBy('id')->chunkById($chunk, function ($rows) {
            foreach ($rows as $r) {
                if (!isset($r->acuity_data)) continue;
                $data = json_decode($r->acuity_data, true);
                $cat = null;
                if (is_array($data) && isset($data['category']) && is_string($data['category'])) {
                    $cat = strtolower(trim($data['category']));
                }
                DB::table('class_sessions')->where('id', $r->id)->update(['category_norm' => $cat]);
            }
        });

        $this->info('Backfilling students first/last appointment dates (via attendance pivot)...');
        Student::query()->select('id')->orderBy('id')->chunkById($chunk, function ($students) {
            foreach ($students as $s) {
                $min = DB::table('class_sessions')
                    ->join('attendance_records as ar', 'ar.class_session_id', '=', 'class_sessions.id')
                    ->where('ar.student_id', $s->id)
                    ->min('class_sessions.session_date');
                $max = DB::table('class_sessions')
                    ->join('attendance_records as ar', 'ar.class_session_id', '=', 'class_sessions.id')
                    ->where('ar.student_id', $s->id)
                    ->max('class_sessions.session_date');
                DB::table('students')->where('id', $s->id)->update([
                    'first_appointment_date' => $min,
                    'last_appointment_date' => $max,
                ]);
            }
        });

        $this->info('Done.');
        return Command::SUCCESS;
    }
}
