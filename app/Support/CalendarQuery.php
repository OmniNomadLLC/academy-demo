<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Carbon\Carbon;
use App\Services\LocationMappingService;

class CalendarQuery
{
    /**
     * Build a base query for upcoming/past window sessions joined with students, with robust JSON extraction.
     *
     * @param string|null $region One of 'UK','Spain','France' or null for all
     * @param array|null $calendarNames Filter by calendar names (array of strings)
     * @param string|null $search Search text across student name/email and category
     * @param Carbon|null $from
     * @param Carbon|null $to
     * @return QueryBuilder
     */
    public static function sessions(?string $region = null, ?array $calendarNames = null, ?string $search = null, ?Carbon $from = null, ?Carbon $to = null): QueryBuilder
    {
        $from = $from ?: now()->subDays(30);
        $to = $to ?: now()->addDays(60);

        $hasStudentId = Schema::hasColumn('class_sessions', 'student_id');

        // Robust JSON extractors
        $emailExpr = "LOWER(COALESCE(\n            json_extract(class_sessions.acuity_data, '$.email'),\n            json_extract(class_sessions.acuity_data, '$.client.email'),\n            json_extract(class_sessions.acuity_data, '$.Client.email')\n        ))";
        $clientIdExpr = "COALESCE(\n            json_extract(class_sessions.acuity_data, '$.clientID'),\n            json_extract(class_sessions.acuity_data, '$.clientId'),\n            json_extract(class_sessions.acuity_data, '$.client.id'),\n            json_extract(class_sessions.acuity_data, '$.client_id'),\n            json_extract(class_sessions.acuity_data, '$.ClientID'),\n            json_extract(class_sessions.acuity_data, '$.ClientId'),\n            json_extract(class_sessions.acuity_data, '$.Client.id'),\n            json_extract(class_sessions.acuity_data, '$.Client_id')\n        )";
        $calendarNameExpr = "COALESCE(\n            json_extract(class_sessions.acuity_data, '$.calendar'),\n            json_extract(class_sessions.acuity_data, '$.calendarName'),\n            json_extract(class_sessions.acuity_data, '$.calendar.name'),\n            json_extract(class_sessions.acuity_data, '$.Calendar'),\n            json_extract(class_sessions.acuity_data, '$.CalendarName')\n        )";
        $categoryExpr = "COALESCE(\n            json_extract(class_sessions.acuity_data, '$.category'),\n            json_extract(class_sessions.acuity_data, '$.Category')\n        )";

        $q = DB::table('class_sessions')
            ->when($hasStudentId, function ($query) {
                return $query->leftJoin('students', 'students.id', '=', 'class_sessions.student_id');
            }, function ($query) use ($emailExpr, $clientIdExpr) {
                // Fallback: join on email/client id from JSON
                return $query->leftJoin('students', function ($join) use ($emailExpr, $clientIdExpr) {
                    /** @var \Illuminate\Database\Query\JoinClause $join */
                    $join->on(DB::raw('LOWER(students.email)'), '=', DB::raw($emailExpr))
                         ->orOn(DB::raw(DbExpressions::castToString('students.acuity_client_id')), '=', DB::raw(DbExpressions::castToString($clientIdExpr)));
                });
            })
            ->whereBetween('class_sessions.session_date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($w) {
                $w->where('class_sessions.canceled', false)
                  ->orWhereNull('class_sessions.canceled')
                  ->orWhereIn('class_sessions.status', ['scheduled','confirmed']);
            })
            ->select([
                'class_sessions.id',
                'class_sessions.session_date',
                'class_sessions.start_time',
                'class_sessions.end_time',
                'class_sessions.acuity_data',
                'class_sessions.class_location_id',
                'class_sessions.venue_name',
                'class_sessions.venue_address',
                'class_sessions.is_virtual',
                'class_sessions.virtual_meeting_url',
                'class_sessions.virtual_meeting_room',
                'class_sessions.location as session_location',
                'class_sessions.category_norm',
                'students.id as student_id',
                'students.first_name',
                'students.last_name',
                'students.email',
                'students.location as student_location',
                DB::raw($calendarNameExpr.' as calendar_name'),
                DB::raw($categoryExpr.' as raw_category'),
            ]);

        // Region scope: student location OR derived category
        if ($region) {
            $keywords = LocationMappingService::keywordsForRegion($region);
            $q->where(function ($w) use ($region, $keywords, $categoryExpr) {
                $w->whereRaw('LOWER(COALESCE(students.location, class_sessions.location)) = ?', [strtolower($region)]);
                if (!empty($keywords)) {
                    $w->orWhere(function ($qq) use ($keywords) {
                        foreach ($keywords as $kw) {
                            $qq->orWhere('class_sessions.category_norm', 'like', '%'.$kw.'%');
                        }
                    });
                    $w->orWhere(function ($qq) use ($keywords, $categoryExpr) {
                        foreach ($keywords as $kw) {
                            $qq->orWhereRaw('LOWER(COALESCE('.$categoryExpr.", '')) like ?", ['%'.$kw.'%']);
                        }
                    });
                }
            });
        }

        // Filters: calendar names
        if (!empty($calendarNames)) {
            $placeholders = implode(',', array_fill(0, count($calendarNames), '?'));
            $q->whereIn(DB::raw('LOWER(COALESCE('.$calendarNameExpr.", 'Unknown'))"), array_map(fn($s)=>strtolower($s), $calendarNames));
        }

        // Search: name/email/category
        if ($search) {
            $needle = '%'.strtolower($search).'%';
            $q->where(function ($w) use ($needle, $emailExpr, $categoryExpr) {
                $w->whereRaw('LOWER(students.first_name) like ?', [$needle])
                  ->orWhereRaw('LOWER(students.last_name) like ?', [$needle])
                  ->orWhereRaw('LOWER(students.email) like ?', [$needle])
                  ->orWhereRaw('LOWER(COALESCE('.$categoryExpr.", '')) like ?", [$needle]);
            });
        }

        return $q;
    }

    /**
     * Map raw rows to calendar event arrays suitable for FullCalendar.
     */
    public static function mapToEvents($rows): array
    {
        $events = [];
        foreach ($rows as $r) {
            $date = $r->session_date instanceof \DateTimeInterface ? $r->session_date->format('Y-m-d') : $r->session_date;
            $start = trim($date.' '.($r->start_time ?? '00:00:00'));
            $endTime = $r->end_time ?? null;
            $end = $endTime ? trim($date.' '.$endTime) : $start;

            $category = null;
            if (!empty($r->category_norm)) {
                $category = $r->category_norm;
            } else {
                $raw = $r->raw_category ?? null;
                if (is_string($raw)) {
                    $category = strtolower(trim($raw));
                }
            }

            $title = trim(($r->first_name.' '.$r->last_name)).' • '.($category ?: 'Class');
            $calendar = $r->calendar_name ? trim(str_replace('"', '', (string) $r->calendar_name)) : 'Unknown';
            $region = $r->student_location ?: $r->session_location;
            if (!$region && $category) {
                $region = LocationMappingService::regionForCategory($category);
            }

            $events[] = [
                'id' => $r->id,
                'title' => $title,
                'start' => $start,
                'end' => $end,
                'calendar_name' => $calendar ?: 'Unknown',
                'student_id' => $r->student_id,
                'region' => $region ?: 'Unknown',
                'class_location_id' => $r->class_location_id,
                'venue_name' => $r->venue_name,
                'venue_address' => $r->venue_address,
                'is_virtual' => (bool) ($r->is_virtual ?? false),
                'virtual_meeting_url' => $r->virtual_meeting_url,
                'virtual_meeting_room' => $r->virtual_meeting_room,
            ];
        }
        return $events;
    }

    /**
     * Get distinct calendar names in scope.
     */
    public static function distinctCalendars(?string $region = null): array
    {
        $from = now()->subDays(60)->toDateString();
        $to = now()->addDays(120)->toDateString();
        $calendarNameExpr = "LOWER(COALESCE(\n            json_extract(class_sessions.acuity_data, '$.calendar'),\n            json_extract(class_sessions.acuity_data, '$.calendarName'),\n            json_extract(class_sessions.acuity_data, '$.calendar.name'),\n            json_extract(class_sessions.acuity_data, '$.Calendar'),\n            json_extract(class_sessions.acuity_data, '$.CalendarName')\n        ))";
        $q = DB::table('class_sessions')
            ->whereBetween('class_sessions.session_date', [$from, $to])
            ->where(function ($w) {
                $w->where('class_sessions.canceled', false)
                  ->orWhereNull('class_sessions.canceled')
                  ->orWhereIn('class_sessions.status', ['scheduled','confirmed']);
            })
            ->when($region, function ($query, $region) {
                return $query->where(function ($w) use ($region) {
                    $w->whereRaw('LOWER(class_sessions.location) = ?', [strtolower($region)])
                      ->orWhere('class_sessions.category_norm', 'like', '%'.strtolower($region).'%');
                });
            })
            ->select(DB::raw('DISTINCT '.$calendarNameExpr.' as cal'))
            ->orderBy('cal');
        $rows = $q->pluck('cal')->toArray();
        $vals = array_values(array_filter(array_map(function ($v) {
            if ($v === null) return null;
            $s = trim(str_replace('"', '', (string) $v));
            return $s !== '' ? $s : null;
        }, $rows)));
        sort($vals);
        return $vals;
    }
}
