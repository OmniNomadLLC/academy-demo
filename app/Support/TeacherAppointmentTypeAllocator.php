<?php

namespace App\Support;

use App\Models\ClassSession;
use App\Models\TeacherAppointmentTypeAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use App\Support\AcuityCalendarMatcher;
use App\Support\DbExpressions;

class TeacherAppointmentTypeAllocator
{
    private const APPOINTMENT_TYPE_EXPRESSION = "COALESCE(
        json_extract(acuity_data, '$.appointmentTypeID'),
        json_extract(acuity_data, '$.appointmentTypeId'),
        json_extract(acuity_data, '$.appointmentType.id'),
        json_extract(acuity_data, '$.typeID'),
        json_extract(acuity_data, '$.TypeID')
    )";

    private const CALENDAR_ID_EXPRESSION = "COALESCE(
        json_extract(acuity_data, '$.calendarID'),
        json_extract(acuity_data, '$.calendarId'),
        json_extract(acuity_data, '$.calendar.id'),
        json_extract(acuity_data, '$.CalendarID'),
        json_extract(acuity_data, '$.Calendar.id')
    )";

    public static function sync(User $teacher, array $additionalCalendars = []): void
    {
        if (! Schema::hasTable('teacher_appointment_type_assignments')) {
            return;
        }

        $teacher->loadMissing('teacherAppointmentTypeAssignments');

        $supportsCoverTeachers = Schema::hasColumn('class_sessions', 'cover_teacher_id');
        $supportsAssignedTeachers = Schema::hasColumn('class_sessions', 'assigned_teacher_id');

        $activeCalendars = collect($teacher->teacherCalendarIds())
            ->filter(fn ($value) => is_string($value) || is_numeric($value))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        $calendarKeys = $activeCalendars
            ->concat(
                collect($additionalCalendars)
                    ->filter(fn ($value) => is_string($value) || is_numeric($value))
                    ->map(fn ($value) => trim((string) $value))
                    ->filter()
            )
            ->unique()
            ->values();

        $calendarMap = collect();

        foreach ($calendarKeys as $id) {
            $calendarMap->put($id, [
                'type_ids' => collect(),
                'is_active' => $activeCalendars->contains($id),
            ]);
        }

        foreach ($teacher->teacherAppointmentTypeAssignments as $assignment) {
            $calendarId = trim((string) ($assignment->acuity_calendar_id ?? ''));
            $typeId = trim((string) ($assignment->acuity_appointment_type_id ?? ''));

            if ($calendarId === '' || $typeId === '') {
                continue;
            }

            $entry = $calendarMap->get($calendarId, [
                'type_ids' => collect(),
                'is_active' => $activeCalendars->contains($calendarId),
            ]);

            $entry['type_ids'] = $entry['type_ids']->push($typeId);
            $calendarMap->put($calendarId, $entry);
        }

        if ($calendarMap->isEmpty()) {
            $update = ['teacher_id' => null];
            if ($supportsAssignedTeachers) {
                $update['assigned_teacher_id'] = null;
            }

            $query = ClassSession::query()->where('teacher_id', $teacher->id);
            if ($supportsCoverTeachers) {
                $query->whereNull('cover_teacher_id');
            }

            $query->update($update);

            return;
        }

        $appointmentExpression = DbExpressions::castToString(self::APPOINTMENT_TYPE_EXPRESSION);

        $calendarAssignmentPresence = collect();

        if ($calendarMap->isNotEmpty()) {
            $calendarKeys = $calendarMap->keys()
                ->filter(fn ($value) => is_string($value) || is_numeric($value))
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->values();

            if ($calendarKeys->isNotEmpty()) {
                $calendarAssignmentPresence = TeacherAppointmentTypeAssignment::query()
                    ->select('acuity_calendar_id')
                    ->whereIn('acuity_calendar_id', $calendarKeys->all())
                    ->distinct()
                    ->pluck('acuity_calendar_id')
                    ->map(fn ($value) => trim((string) $value))
                    ->filter()
                    ->flip();
            }
        }

        foreach ($calendarMap as $calendarId => $details) {
            $typeIds = ($details['type_ids'] ?? collect())
                ->filter(fn ($value) => is_string($value) && $value !== '')
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->values();
            $isActive = (bool) ($details['is_active'] ?? false);
            $calendarHasAssignments = $calendarAssignmentPresence->has(trim((string) $calendarId));

            $calendarScope = function ($query) use ($calendarId) {
                AcuityCalendarMatcher::apply($query, $calendarId);
            };

            $removalQuery = ClassSession::query()
                ->where('teacher_id', $teacher->id)
                ->where($calendarScope);

            if ($supportsCoverTeachers) {
                $removalQuery->whereNull('cover_teacher_id');
            }

            if ($typeIds->isEmpty()) {
                if ($calendarHasAssignments) {
                    $update = ['teacher_id' => null];
                    if ($supportsAssignedTeachers) {
                        $update['assigned_teacher_id'] = null;
                    }
                    $removalQuery->update($update);
                    continue;
                }

                if (! $isActive) {
                    $update = ['teacher_id' => null];
                    if ($supportsAssignedTeachers) {
                        $update['assigned_teacher_id'] = null;
                    }
                    $removalQuery->update($update);
                    continue;
                }

                $update = ['teacher_id' => $teacher->id];
                if ($supportsAssignedTeachers) {
                    $update['assigned_teacher_id'] = $teacher->id;
                }

                $assignQuery = ClassSession::query()->where($calendarScope);
                if ($supportsCoverTeachers) {
                    $assignQuery->whereNull('cover_teacher_id');
                }

                $assignQuery->update($update);

                continue;
            }

            $placeholders = implode(',', array_fill(0, $typeIds->count(), '?'));
            $bindings = $typeIds->all();

            $removalUpdate = ['teacher_id' => null];
            if ($supportsAssignedTeachers) {
                $removalUpdate['assigned_teacher_id'] = null;
            }

            $removalQuery
                ->whereRaw("($appointmentExpression IS NULL OR $appointmentExpression NOT IN ($placeholders))", $bindings)
                ->update($removalUpdate);

            $assignUpdate = ['teacher_id' => $teacher->id];
            if ($supportsAssignedTeachers) {
                $assignUpdate['assigned_teacher_id'] = $teacher->id;
            }

            $assignQuery = ClassSession::query()
                ->where($calendarScope)
                ->whereRaw("$appointmentExpression IN ($placeholders)", $bindings);

            if ($supportsCoverTeachers) {
                $assignQuery->whereNull('cover_teacher_id');
            }

            $assignQuery->update($assignUpdate);
        }
    }
}
