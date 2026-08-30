<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Student;

class StudentsBackfillRegion extends Command
{
    protected $signature = 'students:backfill-region {--chunk=200 : Chunk size}';
    protected $description = 'Backfill student acuity_category and location based on latest known Acuity category from class sessions';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $updated = 0; $unchanged = 0; $unknown = 0;

        $this->info("Backfilling students in chunks of {$chunk}...");

        Student::query()
            ->select(['id','acuity_client_id','first_name','last_name','email','acuity_category','location'])
            ->orderBy('id')
            ->chunkById($chunk, function ($students) use (&$updated, &$unchanged, &$unknown) {
                foreach ($students as $student) {
                    $latestCategory = $this->latestCategoryForStudent(
                        $student->acuity_client_id,
                        $student->id,
                        $student->email,
                        $student->first_name,
                        $student->last_name
                    );
                    $beforeLocation = $student->location;
                    $beforeCategory = $student->acuity_category;

                    $student->setAcuityCategoryAndLocation($latestCategory);

                    if ($student->isDirty(['acuity_category','location'])) {
                        $student->save();
                        $updated++;
                    } else {
                        $unchanged++;
                    }

                    if ($student->location === null) {
                        $unknown++;
                    }
                }
            });

        $this->line("");
        $this->info("Backfill completed.");
        $this->info("Updated: {$updated} | Unchanged: {$unchanged} | Unknown region: {$unknown}");

        $this->line("");
        $this->info("Students per region:");
        $rows = DB::table('students')
            ->select('location', DB::raw('COUNT(*) as cnt'))
            ->groupBy('location')
            ->orderBy('location')
            ->get();
        foreach ($rows as $row) {
            $this->line(sprintf("%s: %d", $row->location ?? 'NULL', $row->cnt));
        }

        return Command::SUCCESS;
    }

    private function latestCategoryForStudent(?string $acuityClientId, ?int $studentId = null, ?string $email = null, ?string $firstName = null, ?string $lastName = null): ?string
    {
        // 1) If we have a direct class_session link, prefer it
        if ($studentId) {
            $category = DB::table('class_sessions')
                ->where('student_id', $studentId)
                ->whereNotNull('acuity_data')
                ->whereRaw("json_extract(acuity_data, '$.category') IS NOT NULL")
                ->orderByDesc('session_date')
                ->limit(1)
                ->value(DB::raw("json_extract(acuity_data, '$.category')"));
            if ($category) return $category;
        }

        // 2) Match by Acuity clientID in stored JSON
        if ($acuityClientId) {
            $category = DB::table('class_sessions')
                ->whereNotNull('acuity_data')
                ->whereRaw("json_extract(acuity_data, '$.clientID') = ?", [$acuityClientId])
                ->whereRaw("json_extract(acuity_data, '$.category') IS NOT NULL")
                ->orderByDesc('session_date')
                ->limit(1)
                ->value(DB::raw("json_extract(acuity_data, '$.category')"));
            if ($category) return $category;
        }

        // 3) Fallback: match by email in acuity_data
        if ($email) {
            $category = DB::table('class_sessions')
                ->whereNotNull('acuity_data')
                ->whereRaw("LOWER(json_extract(acuity_data, '$.email')) = ?", [strtolower($email)])
                ->whereRaw("json_extract(acuity_data, '$.category') IS NOT NULL")
                ->orderByDesc('session_date')
                ->limit(1)
                ->value(DB::raw("json_extract(acuity_data, '$.category')"));
            if ($category) return $category;
        }

        // 4) Fallback: match by first & last name in acuity_data
        if ($firstName && $lastName) {
            $category = DB::table('class_sessions')
                ->whereNotNull('acuity_data')
                ->whereRaw("LOWER(json_extract(acuity_data, '$.firstName')) = ?", [strtolower($firstName)])
                ->whereRaw("LOWER(json_extract(acuity_data, '$.lastName')) = ?", [strtolower($lastName)])
                ->whereRaw("json_extract(acuity_data, '$.category') IS NOT NULL")
                ->orderByDesc('session_date')
                ->limit(1)
                ->value(DB::raw("json_extract(acuity_data, '$.category')"));
            if ($category) return $category;
        }

        return null;
    }
}
