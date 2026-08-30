<?php

namespace App\Support;

use App\Models\ClassLocationCalendar;
use App\Models\TeacherAppointmentTypeCatalog;
use App\Support\DbExpressions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TeacherAppointmentTypeCatalogBuilder
{
    private const FALLBACK_KEYWORDS = ['riverside', 'northgate', 'parkside', 'southbank'];

    public static function rebuild(): void
    {
        if (! Schema::hasTable('teacher_appointment_type_catalog')) {
            return;
        }

        $calendars = static::targetCalendars();

        if ($calendars->isEmpty()) {
            TeacherAppointmentTypeCatalog::query()->truncate();

            return;
        }

        $records = collect();

        foreach ($calendars as $calendar) {
            $rows = static::catalogRowsForCalendar($calendar);
            $records = $records->concat($rows);
        }

        $records = $records
            ->unique(fn (array $row) => $row['acuity_calendar_id'] . '|' . $row['acuity_appointment_type_id'])
            ->values();

        DB::transaction(function () use ($records, $calendars) {
            if ($records->isNotEmpty()) {
                $now = now();

                TeacherAppointmentTypeCatalog::query()->upsert(
                    $records
                        ->map(function (array $row) use ($now) {
                            $row['updated_at'] = $now;
                            $row['created_at'] = $row['created_at'] ?? $now;

                            return $row;
                        })
                        ->all(),
                    ['acuity_calendar_id', 'acuity_appointment_type_id'],
                    ['calendar_norm', 'calendar_label', 'appointment_type_name', 'last_seen_session_id', 'last_seen_session_date', 'last_seen_at', 'updated_at']
                );
            }

            $validKeys = $records
                ->map(fn (array $row) => [$row['acuity_calendar_id'], $row['acuity_appointment_type_id']])
                ->all();

            if (! empty($validKeys)) {
                TeacherAppointmentTypeCatalog::query()
                    ->whereNotIn(DB::raw("CONCAT(acuity_calendar_id, '::', acuity_appointment_type_id)"),
                        array_map(fn ($pair) => implode('::', $pair), $validKeys))
                    ->delete();
            } else {
                TeacherAppointmentTypeCatalog::query()->delete();
            }
        });

        Cache::forget('teacher_assignment_relevant_calendars');

        $calendarIds = $records
            ->pluck('acuity_calendar_id')
            ->filter()
            ->unique();

        foreach ($calendarIds as $calendarId) {
            Cache::forget("user_resource_calendar_appt_types_{$calendarId}");
        }
    }

    protected static function targetCalendars(): Collection
    {
        $calendars = collect();

        if (Schema::hasTable('class_location_calendars') && Schema::hasTable('class_locations')) {
            $calendars = ClassLocationCalendar::query()
                ->with('location')
                ->whereHas('location', function ($query) {
                    $query->where('region', 'UK')
                        ->where(function ($inner) {
                            $inner->whereNull('is_virtual')->orWhere('is_virtual', false);
                        });
                })
                ->get()
                ->map(function (ClassLocationCalendar $calendar) {
                    return [
                        'calendar_norm' => strtolower((string) $calendar->calendar_norm),
                        'calendar_label' => $calendar->calendar_name ?? Str::title(str_replace('-', ' ', (string) $calendar->calendar_norm)),
                        'calendar_slug' => Str::slug((string) $calendar->calendar_norm),
                    ];
                });
        }

        $fallback = static::fallbackCalendarsFromSessions();

        return $calendars->concat($fallback)
            ->filter(function ($row) {
                return ! empty($row['calendar_norm']);
            })
            ->unique(fn (array $row) => $row['calendar_norm'])
            ->values();
    }

    protected static function catalogRowsForCalendar(array $calendar): Collection
    {
        $calendarNorm = $calendar['calendar_norm'];

        $typeIdExpression = DbExpressions::castToString("COALESCE(
            JSON_EXTRACT(acuity_data, '$.appointmentTypeID'),
            JSON_EXTRACT(acuity_data, '$.appointmentTypeId'),
            JSON_EXTRACT(acuity_data, '$.appointmentType.id'),
            JSON_EXTRACT(acuity_data, '$.typeID'),
            JSON_EXTRACT(acuity_data, '$.TypeID'),
            JSON_EXTRACT(acuity_data, '$.classTypeID'),
            JSON_EXTRACT(acuity_data, '$.ClassTypeID'),
            JSON_EXTRACT(acuity_data, '$.classType.id'),
            JSON_EXTRACT(acuity_data, '$.ClassType.id'),
            JSON_EXTRACT(acuity_data, '$.type.id'),
            JSON_EXTRACT(acuity_data, '$.appointmentType')
        )");

        $typeNameExpression = DbExpressions::castToString("COALESCE(
            JSON_EXTRACT(acuity_data, '$.appointmentType'),
            JSON_EXTRACT(acuity_data, '$.appointmentType.name'),
            JSON_EXTRACT(acuity_data, '$.classType'),
            JSON_EXTRACT(acuity_data, '$.ClassType'),
            JSON_EXTRACT(acuity_data, '$.classType.name'),
            JSON_EXTRACT(acuity_data, '$.ClassType.name'),
            JSON_EXTRACT(acuity_data, '$.classTypeName'),
            JSON_EXTRACT(acuity_data, '$.type'),
            JSON_EXTRACT(acuity_data, '$.Type')
        )");

        $calendarIdExpression = DbExpressions::castToString("COALESCE(
            JSON_EXTRACT(acuity_data, '$.calendarID'),
            JSON_EXTRACT(acuity_data, '$.calendarId'),
            JSON_EXTRACT(acuity_data, '$.calendar.id'),
            JSON_EXTRACT(acuity_data, '$.CalendarID'),
            JSON_EXTRACT(acuity_data, '$.Calendar.id')
        )");

        $rows = DB::table('class_sessions')
            ->selectRaw("{$calendarIdExpression} as acuity_calendar_id, {$typeIdExpression} as type_id, {$typeNameExpression} as type_name, MAX(id) as last_session_id, MAX(session_date) as last_session_date")
            ->where('calendar_norm', $calendarNorm)
            ->whereNotNull('acuity_data')
            ->whereRaw("{$typeNameExpression} IS NOT NULL AND {$typeNameExpression} <> ''")
            ->groupBy('acuity_calendar_id', 'type_id', 'type_name')
            ->orderBy('type_name')
            ->get();

        $label = $calendar['calendar_label'];

        return $rows->map(function ($row) use ($calendar, $label) {
            $calendarId = is_string($row->acuity_calendar_id) ? trim($row->acuity_calendar_id) : null;
            $typeId = is_string($row->type_id) ? trim($row->type_id) : null;
            $typeName = is_string($row->type_name) ? trim($row->type_name) : null;

            if (! $typeName) {
                return null;
            }

            $typeId = $typeId ?: $typeName;

            $lastSessionId = $row->last_session_id ? (int) $row->last_session_id : null;
            $lastSessionDate = $row->last_session_date ? Carbon::parse($row->last_session_date) : null;

            return array_filter([
                'acuity_calendar_id' => $calendarId ?: $calendar['calendar_norm'],
                'calendar_norm' => $calendar['calendar_norm'],
                'calendar_label' => $label,
                'acuity_appointment_type_id' => $typeId,
                'appointment_type_name' => $typeName,
                'last_seen_session_id' => $lastSessionId,
                'last_seen_session_date' => $lastSessionDate?->toDateString(),
                'last_seen_at' => $lastSessionDate?->endOfDay()->toDateTimeString(),
            ], fn ($value) => $value !== null);
        })->filter()->values();
    }

    protected static function fallbackCalendarsFromSessions(): Collection
    {
        $keywords = collect(self::FALLBACK_KEYWORDS)
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values();

        if ($keywords->isEmpty()) {
            return collect();
        }

        $rows = DB::table('class_sessions')
            ->select('calendar_norm', 'calendar_name')
            ->whereNotNull('calendar_norm')
            ->where(function ($query) use ($keywords) {
                $query->whereRaw('LOWER(COALESCE(location, ?)) = ?', ['', 'uk']);

                foreach ($keywords as $keyword) {
                    $query->orWhereRaw('LOWER(COALESCE(calendar_norm, ?)) LIKE ?', ['', '%' . $keyword . '%']);
                    $query->orWhereRaw('LOWER(COALESCE(calendar_name, ?)) LIKE ?', ['', '%' . $keyword . '%']);
                }
            })
            ->distinct()
            ->get();

        return $rows->map(function ($row) {
            $norm = strtolower(trim((string) $row->calendar_norm));
            $label = trim((string) ($row->calendar_name ?? $norm));

            if ($norm === '') {
                return null;
            }

            return [
                'calendar_norm' => $norm,
                'calendar_label' => $label !== '' ? $label : Str::title(str_replace('-', ' ', $norm)),
                'calendar_slug' => Str::slug($norm),
            ];
        })->filter()
            ->unique(fn (array $row) => $row['calendar_norm'])
            ->values();
    }
}
