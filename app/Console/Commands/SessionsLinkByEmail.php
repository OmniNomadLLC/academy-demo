<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Support\EmailNormalizer;

class SessionsLinkByEmail extends Command
{
    protected $signature = 'sessions:link-by-email {--chunk=500} {--from=} {--to=} {--days=}';
    protected $description = 'Backfill linking of class_sessions to students by normalized email.';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $from = $this->option('from');
        $to = $this->option('to');
        $days = (int) ($this->option('days') ?? 0);

        $q = DB::table('class_sessions')->whereNull('student_id');
        if ($from && $to) {
            $q->whereBetween('session_date', [$from, $to]);
        } elseif ($days > 0) {
            $q->where('session_date', '>=', now()->subDays($days)->toDateString());
        }
        $q->where(function($w){ $w->whereNull('link_status')->orWhere('link_status','unlinked'); });
        $total = (clone $q)->count();
        $this->info("Unlinked sessions: {$total}");

        $linked = 0; $remain = $total;
        $q->orderBy('id')->chunk($chunk, function ($rows) use (&$linked, &$remain) {
            foreach ($rows as $r) {
                $email = $r->student_email ?? null;
                if (!$email && isset($r->acuity_data)) {
                    $data = is_string($r->acuity_data) ? json_decode($r->acuity_data, true) : (array) $r->acuity_data;
                    if (is_array($data)) {
                        $email = EmailNormalizer::fromAcuity($data);
                    }
                }
                $emailNorm = EmailNormalizer::normalize($email);
                if (!$emailNorm) { $remain--; continue; }

                $student = DB::table('students')->whereRaw('LOWER(email) = ?', [$emailNorm])->first();
                if ($student) {
                    DB::table('class_sessions')->where('id', $r->id)->update([
                        'student_id' => $student->id,
                        'link_status' => 'linked_by_email',
                        'student_email' => $emailNorm,
                    ]);
                    $linked++; $remain--;
                } else {
                    $remain--;
                }
            }
            $this->line("Processed chunk. Linked so far: {$linked}. Remaining approx: {$remain}");
        });

        $remaining = DB::table('class_sessions')->whereNull('student_id')->count();
        $this->info("Backfill complete. Linked by email: {$linked}. Still unlinked: {$remaining}");
        return self::SUCCESS;
    }
}
