<?php

namespace App\Support;

use App\Support\DbExpressions;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

class AcuityCalendarMatcher
{
    /**
     * Apply Acuity calendar ID comparisons across the known JSON paths.
     *
     * @param  EloquentBuilder|QueryBuilder  $query
     */
    public static function apply($query, string $calendarId, bool $includeCalendarName = false): void
    {
        $calendarId = trim($calendarId);

        if ($calendarId === '') {
            return;
        }

        $paths = [
            '$.calendarID',
            '$.calendarId',
            '$.calendar.id',
            '$.CalendarID',
            '$.Calendar.id',
        ];

        foreach ($paths as $index => $path) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}(DbExpressions::castToString("JSON_EXTRACT(acuity_data, '{$path}')") . ' = ?', [$calendarId]);
        }

        if (Schema::hasColumn('class_sessions', 'acuity_calendar_id')) {
            $query->orWhere('acuity_calendar_id', $calendarId);
        }

        if ($includeCalendarName) {
            $query->orWhereRaw('LOWER(TRIM(COALESCE(calendar_name, ?))) = ?', ['', strtolower($calendarId)]);
        }
    }
}
