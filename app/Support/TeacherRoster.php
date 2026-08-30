<?php

namespace App\Support;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Support\AcuityCalendarMatcher;
use App\Support\DbExpressions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeacherRoster
{
    private const PAST_WINDOW_DAYS = 30;
    private const FUTURE_WINDOW_DAYS = 90;

    /**
     * Build a base query for class sessions scoped to the given teacher account.
     */
    public static function sessions(User $teacher): Builder
    {
        $scope = $teacher->teacherScope();
        $calendarIds = $teacher->teacherCalendarIds();
        $allowedRegions = $teacher->restrictsByRegion() ? $teacher->allowedRegions() : null;

        $query = ClassSession::query()
            ->where(function ($q) {
                $q->whereNull('canceled')
                    ->orWhere('canceled', false)
                    ->orWhereIn('status', ['scheduled', 'confirmed']);
            });

        if ($scope !== 'region') {
            if ($scope === 'assigned_classes') {
                $query->where(function ($inner) use ($teacher) {
                    $inner->where('teacher_id', $teacher->id)
                        ->orWhere('cover_teacher_id', $teacher->id);
                });
            } elseif ($scope === 'calendar_linked') {
                if (empty($calendarIds)) {
                    $query->where(function ($inner) use ($teacher) {
                        $inner->where('teacher_id', $teacher->id)
                            ->orWhere('cover_teacher_id', $teacher->id);
                    });
                } else {
                    $query->where(function ($outer) use ($calendarIds, $teacher) {
                        foreach ($calendarIds as $index => $calendarId) {
                            $method = $index === 0 ? 'where' : 'orWhere';
                            $outer->{$method}(function ($calendarQuery) use ($calendarId) {
                                AcuityCalendarMatcher::apply($calendarQuery, $calendarId);
                            });
                        }

                        $outer->orWhere(function ($sub) use ($teacher) {
                            $sub->where(function ($innerTeacher) use ($teacher) {
                                $innerTeacher->where('teacher_id', $teacher->id)
                                    ->orWhere('cover_teacher_id', $teacher->id);
                            })
                                ->where(function ($inner) {
                                    $inner->whereNull('acuity_data')
                                        ->orWhere(function ($missing) {
                                            $missing->whereRaw("json_extract(acuity_data, '$.calendarID') IS NULL")
                                                ->whereRaw("json_extract(acuity_data, '$.calendarId') IS NULL")
                                                ->whereRaw("json_extract(acuity_data, '$.calendar.id') IS NULL");
                                        });
                                });
                        });
                    });
                }
            }
        }

        if (! empty($allowedRegions)) {
            $query->whereIn('location', $allowedRegions);
        }

        [$windowStart, $windowEnd] = self::sessionWindowBounds();

        $query->where(function ($q) use ($windowStart, $windowEnd) {
            $q->whereBetween('session_date', [$windowStart, $windowEnd])
                ->orWhereNull('session_date');
        });

        return $query;
    }

    /**
     * Resolve the unique students linked to any of the teacher's sessions.
     *
     * @return Collection<int, Student>
     */
    public static function students(User $teacher): Collection
    {
        $keys = self::studentLookupKeys($teacher);

        if (empty($keys['ids']) && empty($keys['emails']) && empty($keys['client_ids'])) {
            return collect();
        }

        $query = Student::query();

        if ($teacher->restrictsByRegion()) {
            $allowed = $teacher->allowedRegions();
            $query->where(function ($q) use ($allowed) {
                $q->whereIn('location', $allowed)
                    ->orWhereNull('location')
                    ->orWhere('location', 'Academic');
            });
        }

        $query->where(function ($q) use ($keys) {
            if (! empty($keys['ids'])) {
                $q->orWhereIn('id', $keys['ids']);
            }
            if (! empty($keys['emails'])) {
                $q->orWhereIn('email_norm', $keys['emails']);
            }
            if (! empty($keys['client_ids'])) {
                $q->orWhereIn('acuity_client_id', $keys['client_ids']);
            }
        });

        return $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * Fetch identifiers that can be used to locate students for the teacher's sessions.
     *
     * @return array{ids: array<int>, emails: array<string>, client_ids: array<string>}
     */
    public static function studentLookupKeys(User $teacher): array
    {
        $sessionQuery = self::sessions($teacher)->cloneWithout(['orders', 'columns']);

        [$from, $to] = self::sessionWindowBounds();

        $rows = $sessionQuery
            ->select([
                'student_id',
                DB::raw("LOWER(COALESCE(student_email, client_email, json_extract(acuity_data, '$.email'), json_extract(acuity_data, '$.client.email'), json_extract(acuity_data, '$.Client.email'))) as email_norm"),
                DB::raw(DbExpressions::castToString("COALESCE(json_extract(acuity_data, '$.clientID'), json_extract(acuity_data, '$.clientId'), json_extract(acuity_data, '$.client.id'), json_extract(acuity_data, '$.client_id'), json_extract(acuity_data, '$.ClientID'), json_extract(acuity_data, '$.ClientId'), json_extract(acuity_data, '$.Client.id'), json_extract(acuity_data, '$.Client_id'))") . ' as client_key'),
            ])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('session_date', [$from, $to])
                    ->orWhereNull('session_date');
            })
            ->get();

        $ids = $rows->pluck('student_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $emails = $rows->pluck('email_norm')->filter(function ($value) {
            return is_string($value) && $value !== '';
        })->unique()->values()->all();
        $clientIds = $rows->pluck('client_key')->filter(function ($value) {
            return is_string($value) && $value !== '' && $value !== 'null';
        })->map(fn ($value) => (string) $value)->unique()->values()->all();

        return [
            'ids' => $ids,
            'emails' => $emails,
            'client_ids' => $clientIds,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function sessionWindowBounds(): array
    {
        $now = Carbon::now();

        return [
            $now->copy()->subDays(self::PAST_WINDOW_DAYS)->toDateString(),
            $now->copy()->addDays(self::FUTURE_WINDOW_DAYS)->toDateString(),
        ];
    }
}
