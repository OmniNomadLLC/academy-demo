<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportUpcomingController extends Controller
{
    public function upcoming(Request $request): StreamedResponse
    {
        // Behind ['web','auth'] only. Without this, any authenticated user (e.g. a
        // teacher) could export every region's sessions incl. other teachers' names/
        // emails. Require the reporting domain and scope output to the user's regions.
        $user = $request->user();
        abort_unless($user && $user->hasDomainAccess('reporting'), 403);

        $region = $request->string('region')->toString(); // 'UK'|'Spain'|'France' or empty for all
        $calendar = $request->string('calendar')->toString();
        $teacherId = $request->integer('teacher_id') ?: null;
        $statuses = collect((array) $request->input('status'))->filter()->values()->all();
        $from = $request->date('from')?->format('Y-m-d');
        $until = $request->date('until')?->format('Y-m-d');
        $preset = $request->string('preset')->toString(); // 'today'|'next7'|'next30'

        $q = DB::table('class_sessions as cs')
            ->leftJoin('users as u', 'u.id', '=', 'cs.teacher_id')
            ->select(['cs.session_date','cs.start_time','cs.end_time','cs.location','cs.status','cs.calendar_name','cs.category_norm','u.name as teacher_name','u.email as teacher_email']);

        // Default window: next 60 days
        $defFrom = now()->toDateString();
        $defUntil = now()->addDays(60)->toDateString();

        // Apply preset if any
        if ($preset === 'today') { $from = now()->toDateString(); $until = $from; }
        if ($preset === 'next7') { $from = now()->toDateString(); $until = now()->addDays(7)->toDateString(); }
        if ($preset === 'next30') { $from = now()->toDateString(); $until = now()->addDays(30)->toDateString(); }

        $q->whereBetween('cs.session_date', [$from ?: $defFrom, $until ?: $defUntil])
          ->where(function ($w) {
              $w->where('cs.canceled', false)->orWhereNull('cs.canceled')->orWhereIn('cs.status', ['scheduled','confirmed']);
          })
          ->orderBy('cs.session_date')->orderBy('cs.start_time');

        if ($region) {
            abort_unless($user->hasRegionAccess($region), 403);
            $q->whereRaw('LOWER(cs.location) = ?', [strtolower($region)]);
        } elseif ($user->restrictsByRegion()) {
            // No explicit region requested: constrain to the regions this user may see.
            $allowed = array_map('strtolower', $user->allowedRegions());
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $q->whereRaw("LOWER(cs.location) IN ($placeholders)", $allowed);
        }
        if ($calendar) {
            $q->where(function ($w) use ($calendar) {
                $w->whereRaw("json_extract(cs.acuity_data, '$.calendar') = ?", [$calendar])
                  ->orWhereRaw("json_extract(cs.acuity_data, '$.calendarName') = ?", [$calendar])
                  ->orWhereRaw("json_extract(cs.acuity_data, '$.calendar.name') = ?", [$calendar])
                  ->orWhereRaw("json_extract(cs.acuity_data, '$.Calendar') = ?", [$calendar])
                  ->orWhereRaw("json_extract(cs.acuity_data, '$.CalendarName') = ?", [$calendar]);
            });
        }
        if ($teacherId) {
            $q->where('cs.teacher_id', $teacherId);
        }
        if (!empty($statuses)) {
            $q->whereIn('cs.status', $statuses);
        }

        $filename = 'upcoming'.($region ? '-'.strtolower($region) : '').'-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            // Header
            fputcsv($out, ['Date','Start','End','Region','Status','Calendar','Category','Teacher']);
            $q->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    $teacher = $r->teacher_name ?: $r->teacher_email ?: '';
                    fputcsv($out, [
                        $r->session_date,
                        $r->start_time,
                        $r->end_time,
                        $r->location,
                        $r->status,
                        $this->trimJsonQuotes($r->calendar_name),
                        $this->trimJsonQuotes($r->category_norm),
                        $teacher,
                    ]);
                }
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function trimJsonQuotes(?string $v): ?string
    {
        if ($v === null) return null;
        $s = trim($v);
        return trim($s, '"');
    }
}

