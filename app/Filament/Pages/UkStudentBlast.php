<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Models\TeacherAppointmentTypeCatalog;
use App\Services\LocationMappingService;
use App\Support\DbExpressions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UkStudentBlast extends Page
{
    use RequiresRegionAccess;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';
    protected static ?string $navigationLabel = 'Student Blast';
    protected static ?string $title = 'Student Blast (UK)';
    protected static ?string $navigationGroup = 'UK';
    protected static ?int $navigationSort = 7;
    protected static bool $shouldRegisterNavigation = true;
    protected static ?string $slug = 'uk-student-blast';
    protected static string $view = 'filament.pages.uk-student-blast';
    public static string $requiredRegion = 'UK';

    public static function getNavigationGroup(): ?string
    {
        return 'UK';
    }

    public ?string $fromDate = null;
    public ?string $toDate = null;
    public ?string $calendar = null;
    /** @var array<string, string> */
    public array $appointmentTypeOptions = [];
    public string $subject = '';
    public string $message = '';
    public string $timeframe = 'today';
    public ?string $appointmentType = null;
    /** @var array<int, string> */
    public array $statuses = ['absent'];
    /** @var array<int, string> */
    public array $previewEmails = [];
    public string $recipientList = '';
    public int $matchingStudentCount = 0;
    public int $matchingAttendanceCount = 0;
    public array $matchingSummary = [];
    public bool $showSummary = false;
    public string $activeSource = 'attendance';
    public ?string $upcomingCalendar = null;
    public ?string $upcomingAppointmentType = null;
    /** @var array<string, string> */
    public array $upcomingAppointmentTypeOptions = [];
    public bool $attendanceFiltersOpen = false;
    public bool $upcomingFiltersOpen = false;

    public function mount(): void
    {
        $this->refreshPreview();
    }

    public function availableUkCalendars(): array
    {
        return $this->calendarOptions();
    }

    public static function canAccess(): bool
    {
        if (! static::userHasRequiredRegion()) {
            return false;
        }

        $user = Auth::user();
        if (! $user) {
            return false;
        }

        if (! $user->hasDomainAccess('automation') && ! $user->hasDomainAccess('reporting')) {
            return false;
        }

        return parent::canAccess();
    }

    public function previewList(): array
    {
        return $this->previewEmails;
    }

    public function send(): void
    {
        $emails = $this->previewEmails;
        if (empty($emails)) {
            $emails = $this->resolveBlastContext()['emails'];
        }
        if (empty($emails)) {
            Notification::make()->title('No recipients found')->warning()->send();
            return;
        }

        $sanitizedBody = trim(strip_tags((string) $this->message));
        if (trim($this->subject) === '' || $sanitizedBody === '') {
            Notification::make()->title('Subject and message are required')->danger()->send();
            return;
        }

        try {
            dispatch(new \App\Jobs\SendStudentBlast($emails, $this->subject, $this->message));
            Notification::make()->title('Queued blast to '.count($emails).' students')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Failed to queue blast')->danger()->send();
        }
    }

    public function statusOptions(): array
    {
        return [
            'present' => 'Present',
            'absent' => 'Absent',
            'late' => 'Late',
            'cancelled' => 'Cancelled',
        ];
    }

    private function resolveDateRange(): array
    {
        $tz = config('app.timezone', 'UTC');
        $now = Carbon::now($tz);

        return match ($this->timeframe) {
            'this_week' => [
                $now->copy()->startOfWeek()->toDateString(),
                $now->copy()->endOfWeek()->toDateString(),
            ],
            'custom' => $this->customDateRange($tz),
            default => [
                $now->copy()->toDateString(),
                $now->copy()->toDateString(),
            ],
        };
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function customDateRange(string $tz): array
    {
        $from = $this->parseDate($this->fromDate, $tz);
        $to = $this->parseDate($this->toDate, $tz);

        if (! $from && ! $to) {
            return [null, null];
        }

        $from ??= $to;
        $to ??= $from;

        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private function parseDate(?string $date, string $tz): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($date), $tz)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolvedStatuses(): array
    {
        $valid = array_keys($this->statusOptions());

        $selected = collect($this->statuses)
            ->filter(fn ($status) => is_string($status) && in_array($status, $valid, true))
            ->map(fn ($status) => (string) $status)
            ->unique()
            ->values()
            ->all();

        return $selected ?: $valid;
    }

    private function resolvedAppointmentType(): ?string
    {
        if (! is_string($this->appointmentType)) {
            return null;
        }

        $value = trim($this->appointmentType);

        return $value === '' ? null : $value;
    }

    public function applyFilters(): void
    {
        $this->applyAttendanceFilters();
    }

    public function applyAttendanceFilters(): void
    {
        $this->activeSource = 'attendance';
        $this->refreshPreview();
    }

    public function applyUpcomingFilters(): void
    {
        if (! $this->hasUpcomingSelection()) {
            Notification::make()
                ->title('Select a calendar or appointment type first')
                ->warning()
                ->send();

            return;
        }

        $this->activeSource = 'upcoming';
        $this->refreshPreview();
    }

    public function toggleSummary(): void
    {
        $this->showSummary = ! $this->showSummary;
    }

    public function toggleAttendanceFilters(): void
    {
        $this->attendanceFiltersOpen = ! $this->attendanceFiltersOpen;
    }

    public function toggleUpcomingFilters(): void
    {
        $this->upcomingFiltersOpen = ! $this->upcomingFiltersOpen;
    }

    public function updatedCalendar($value): void
    {
        $this->calendar = $this->resolveCalendarId($value);
        $this->appointmentType = null;
        $this->appointmentTypeOptions = $this->loadAppointmentTypesForCalendar($this->calendar);
    }

    public function updatedUpcomingCalendar($value): void
    {
        $this->upcomingCalendar = $this->resolveCalendarId($value);
        $this->upcomingAppointmentType = null;
        $this->upcomingAppointmentTypeOptions = $this->loadUpcomingAppointmentTypesForCalendar($this->upcomingCalendar);
    }

    private function resolveBlastContext(): array
    {
        return $this->activeSource === 'upcoming'
            ? $this->resolveUpcomingBlastContext()
            : $this->resolveAttendanceBlastContext();
    }

    private function refreshPreview(): void
    {
        $context = $this->activeSource === 'upcoming'
            ? $this->resolveUpcomingBlastContext()
            : $this->resolveAttendanceBlastContext();

        $this->previewEmails = $context['emails'];
        $this->recipientList = implode("\n", $context['emails']);
        $this->matchingStudentCount = $context['student_count'];
        $this->matchingAttendanceCount = $context['attendance_count'];
        $this->matchingSummary = $context['summary'];

        if (empty($this->matchingSummary['students']['list'] ?? [])) {
            $this->showSummary = false;
        }
    }

    private function applyUkScope($query, string $studentAlias = 'students'): void
    {
        $keywords = LocationMappingService::keywordsForRegion('UK');

        $query->where(function ($w) use ($studentAlias, $keywords) {
            $studentLocationExpr = "LOWER(TRIM(COALESCE({$studentAlias}.location,'')))";
            $classLocationExpr = "LOWER(TRIM(COALESCE(cs.location,'')))";
            $categoryNormExpr = "LOWER(TRIM(COALESCE(cs.category_norm,'')))";

            $w->whereRaw("{$studentLocationExpr} = 'uk'")
                ->orWhere("{$studentAlias}.in_uk", true)
                ->orWhereRaw("{$classLocationExpr} = 'uk'");

            if (! empty($keywords)) {
                $w->orWhere(function ($qq) use ($keywords, $categoryNormExpr) {
                    foreach ($keywords as $kw) {
                        $qq->orWhereRaw("{$categoryNormExpr} like ?", ['%'.$kw.'%']);
                    }
                });
            }
        });
    }

    private function normalizeOptionalString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function resolveCalendarId(?string $value): ?string
    {
        $input = $this->normalizeOptionalString($value);
        if ($input === null || ! ctype_digit($input)) {
            return null;
        }

        return $input;
    }

    private function loadAppointmentTypesForCalendar(?string $calendarId): array
    {
        if (! $calendarId) {
            return [];
        }

        $typeIdExpression = $this->appointmentTypeIdExpression();
        $calendarIdExpression = $this->calendarIdExpression();

        $query = DB::table('class_sessions as cs')
            ->whereRaw('LOWER(cs.location) = ?', ['uk'])
            ->whereNotNull('cs.acuity_data')
            ->whereRaw("LOWER(TRIM({$calendarIdExpression})) = ?", [$calendarId])
            ->whereIn('cs.status', ['scheduled', 'confirmed'])
            ->where(function ($w) {
                $w->whereNull('cs.canceled')->orWhere('cs.canceled', false);
            })
            ->whereRaw("{$typeIdExpression} IS NOT NULL AND TRIM({$typeIdExpression}) <> ''")
            ->selectRaw("{$typeIdExpression} as appointment_type_id");

        $typeIds = $query
            ->distinct()
            ->pluck('appointment_type_id')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($typeIds->isEmpty()) {
            return [];
        }

        $catalogLabels = TeacherAppointmentTypeCatalog::query()
            ->where('acuity_calendar_id', $calendarId)
            ->whereIn('acuity_appointment_type_id', $typeIds->all())
            ->orderBy('appointment_type_name')
            ->pluck('appointment_type_name', 'acuity_appointment_type_id')
            ->map(fn ($label) => trim((string) $label))
            ->toArray();

        $options = [];
        foreach ($typeIds as $typeId) {
            $label = $catalogLabels[$typeId] ?? $typeId;
            $options[$typeId] = $label === '' ? $typeId : $label;
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    private function loadUpcomingAppointmentTypesForCalendar(?string $calendarId): array
    {
        if (! $calendarId) {
            return [];
        }

        $typeIdExpression = $this->appointmentTypeIdExpression();
        $calendarIdExpression = $this->calendarIdExpression();
        $tz = config('app.timezone', 'UTC');
        $windowStart = Carbon::now($tz)->toDateString();
        $windowEnd = Carbon::now($tz)->addDays(45)->toDateString();

        $query = DB::table('class_sessions as cs')
            ->whereRaw('LOWER(cs.location) = ?', ['uk'])
            ->whereNotNull('cs.acuity_data')
            ->whereRaw("LOWER(TRIM({$calendarIdExpression})) = ?", [$calendarId])
            ->whereBetween('cs.session_date', [$windowStart, $windowEnd])
            ->whereIn('cs.status', ['scheduled', 'confirmed'])
            ->where(function ($w) {
                $w->whereNull('cs.canceled')->orWhere('cs.canceled', false);
            })
            ->whereRaw("{$typeIdExpression} IS NOT NULL AND TRIM({$typeIdExpression}) <> ''")
            ->selectRaw("{$typeIdExpression} as appointment_type_id");

        $typeIds = $query
            ->distinct()
            ->pluck('appointment_type_id')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($typeIds->isEmpty()) {
            return [];
        }

        $catalogLabels = TeacherAppointmentTypeCatalog::query()
            ->where('acuity_calendar_id', $calendarId)
            ->whereIn('acuity_appointment_type_id', $typeIds->all())
            ->orderBy('appointment_type_name')
            ->pluck('appointment_type_name', 'acuity_appointment_type_id')
            ->map(fn ($label) => trim((string) $label))
            ->toArray();

        $options = [];
        foreach ($typeIds as $typeId) {
            $label = $catalogLabels[$typeId] ?? $typeId;
            $options[$typeId] = $label === '' ? $typeId : $label;
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    private function calendarOptions(): array
    {
        static $options;

        if ($options !== null) {
            return $options;
        }

        $calendarIdExpr = DbExpressions::castToString("JSON_EXTRACT(acuity_data, '$.calendarID')");
        $calendarLabelExpr = "COALESCE(NULLIF(TRIM(calendar_name), ''), TRIM(JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.calendar'))), 'Unknown calendar')";

        $options = DB::table('class_sessions as cs')
            ->selectRaw("DISTINCT TRIM({$calendarIdExpr}) as calendar_id, {$calendarLabelExpr} as calendar_label")
            ->whereRaw('LOWER(COALESCE(cs.location, "")) = ?', ['uk'])
            ->whereNotNull('cs.acuity_data')
            ->whereRaw("{$calendarIdExpr} IS NOT NULL AND TRIM({$calendarIdExpr}) <> ''")
            ->orderBy('calendar_label')
            ->limit(500)
            ->get()
            ->mapWithKeys(function ($row) {
                $id = trim((string) $row->calendar_id);
                if ($id === '') {
                    return [];
                }

                $label = trim((string) ($row->calendar_label ?? ''));
                if ($label === '') {
                    $label = $id;
                }

                return [$id => $label];
            })
            ->toArray();

        return $options;
    }

    private function calendarIdExpression(): string
    {
        return DbExpressions::castToString("COALESCE(
            JSON_EXTRACT(cs.acuity_data, '$.calendarID'),
            JSON_EXTRACT(cs.acuity_data, '$.calendarId'),
            JSON_EXTRACT(cs.acuity_data, '$.calendar.id'),
            JSON_EXTRACT(cs.acuity_data, '$.CalendarID'),
            JSON_EXTRACT(cs.acuity_data, '$.Calendar.id')
        )");
    }

    private function appointmentTypeIdExpression(): string
    {
        return DbExpressions::castToString("COALESCE(
            JSON_EXTRACT(cs.acuity_data, '$.appointmentTypeID'),
            JSON_EXTRACT(cs.acuity_data, '$.appointmentTypeId'),
            JSON_EXTRACT(cs.acuity_data, '$.appointmentType.id'),
            JSON_EXTRACT(cs.acuity_data, '$.typeID'),
            JSON_EXTRACT(cs.acuity_data, '$.TypeID'),
            JSON_EXTRACT(cs.acuity_data, '$.classTypeID'),
            JSON_EXTRACT(cs.acuity_data, '$.ClassTypeID'),
            JSON_EXTRACT(cs.acuity_data, '$.classType.id'),
            JSON_EXTRACT(cs.acuity_data, '$.ClassType.id'),
            JSON_EXTRACT(cs.acuity_data, '$.type.id'),
            JSON_EXTRACT(cs.acuity_data, '$.appointmentType')
        )");
    }

    private function hasUpcomingSelection(): bool
    {
        return (bool) ($this->resolveCalendarId($this->upcomingCalendar)
            ?? $this->normalizeOptionalString($this->upcomingAppointmentType));
    }

    private function emptyBlastContext(string $context): array
    {
        return [
            'emails' => [],
            'student_count' => 0,
            'attendance_count' => 0,
            'summary' => [
                'context' => $context,
                'students' => [
                    'list' => [],
                    'total' => 0,
                    'truncated' => false,
                ],
                'sessions' => [
                    'total' => 0,
                    'status_counts' => [
                        'present' => 0,
                        'late' => 0,
                        'absent' => 0,
                        'cancelled' => 0,
                    ],
                    'calendar_counts' => [],
                    'appointment_counts' => [],
                ],
            ],
        ];
    }

    /**
     * @return array{emails: array<int,string>, student_count: int, attendance_count: int}
     */
    private function resolveAttendanceBlastContext(): array
    {
        $statuses = $this->resolvedStatuses();
        if (empty($statuses)) {
            return $this->emptyBlastContext('attendance');
        }

        $query = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'ar.class_session_id', '=', 'cs.id')
            ->join('students', 'ar.student_id', '=', 'students.id')
            ->whereNull('students.deleted_at')
            ->whereNotNull('students.email')
            ->whereIn('ar.status', $statuses);

        $this->applyUkScope($query);

        [$start, $end] = $this->resolveDateRange();
        if ($start && $end) {
            $query->whereBetween('cs.session_date', [$start, $end]);
        }

        if ($calendar = $this->resolveCalendarId($this->calendar)) {
            $calendarExpr = $this->calendarIdExpression();
            $query->whereRaw("LOWER(TRIM({$calendarExpr})) = ?", [$calendar]);
        }

        $appointmentType = $this->resolvedAppointmentType();
        if ($appointmentType) {
            $typeExpr = $this->appointmentTypeIdExpression();
            $query->whereRaw("LOWER(TRIM({$typeExpr})) = ?", [strtolower($appointmentType)]);
        }

        $studentCount = (clone $query)->distinct('students.id')->count('students.id');
        $attendanceCount = (clone $query)->count();

        $emails = (clone $query)
            ->distinct()
            ->limit(500)
            ->pluck('students.email')
            ->filter(static fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email) => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();

        $studentRows = (clone $query)
            ->select(
                'students.id',
                'students.first_name',
                'students.last_name',
                'students.email',
                DB::raw('COUNT(DISTINCT cs.id) as session_total'),
                DB::raw("SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_total"),
                DB::raw("SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as late_total"),
                DB::raw("SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_total")
            )
            ->groupBy('students.id', 'students.first_name', 'students.last_name', 'students.email')
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                $name = trim(collect([$row->first_name, $row->last_name])->filter()->implode(' '));

                return [
                    'id' => (int) $row->id,
                    'name' => $name !== '' ? $name : (string) $row->email,
                    'email' => (string) $row->email,
                    'sessions' => (int) $row->session_total,
                    'present' => (int) $row->present_total,
                    'late' => (int) $row->late_total,
                    'absent' => (int) $row->absent_total,
                ];
            })
            ->values()
            ->all();

        $sessionCount = (clone $query)->distinct('cs.id')->count('cs.id');

        $statusCounts = (clone $query)
            ->select('ar.status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('ar.status')
            ->pluck('aggregate', 'ar.status')
            ->map(fn ($value) => (int) $value)
            ->toArray();

        return [
            'emails' => $emails,
            'student_count' => $studentCount,
            'attendance_count' => $attendanceCount,
            'summary' => [
                'context' => 'attendance',
                'students' => [
                    'list' => $studentRows,
                    'total' => $studentCount,
                    'truncated' => $studentCount > count($studentRows),
                ],
                'sessions' => [
                    'total' => (int) $sessionCount,
                    'status_counts' => [
                        'present' => $statusCounts['present'] ?? 0,
                        'late' => $statusCounts['late'] ?? 0,
                        'absent' => $statusCounts['absent'] ?? 0,
                        'cancelled' => $statusCounts['cancelled'] ?? 0,
                    ],
                    'calendar_counts' => [],
                    'appointment_counts' => [],
                ],
            ],
        ];
    }

    /**
     * @return array{emails: array<int,string>, student_count: int, attendance_count: int}
     */
    private function resolveUpcomingBlastContext(): array
    {
        $calendar = $this->resolveCalendarId($this->upcomingCalendar);
        $appointmentType = $this->normalizeOptionalString($this->upcomingAppointmentType);

        if (! $calendar && ! $appointmentType) {
            return $this->emptyBlastContext('upcoming');
        }

        $query = DB::table('class_sessions as cs')
            ->join('students', 'cs.student_id', '=', 'students.id')
            ->whereNull('students.deleted_at')
            ->whereNotNull('students.email');

        $this->applyUkScope($query);

        $tz = config('app.timezone', 'UTC');
        $today = Carbon::now($tz)->toDateString();
        $limit = Carbon::now($tz)->addDays(45)->toDateString();

        $query->whereBetween('cs.session_date', [$today, $limit]);
        $query->where(function ($w) {
            $w->whereNull('cs.canceled')
                ->orWhere('cs.canceled', false);
        });
        $query->whereIn('cs.status', ['scheduled', 'confirmed']);

        if ($calendar) {
            $calendarExpr = $this->calendarIdExpression();
            $query->whereRaw("LOWER(TRIM({$calendarExpr})) = ?", [$calendar]);
        }

        if ($appointmentType) {
            $typeExpr = $this->appointmentTypeIdExpression();
            $query->whereRaw("LOWER(TRIM({$typeExpr})) = ?", [strtolower($appointmentType)]);
        }

        $studentCount = (clone $query)->distinct('students.id')->count('students.id');
        $attendanceCount = (clone $query)->distinct('cs.id')->count('cs.id');

        $emails = (clone $query)
            ->distinct()
            ->limit(500)
            ->pluck('students.email')
            ->filter(static fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email) => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();

        $studentRows = (clone $query)
            ->select(
                'students.id',
                'students.first_name',
                'students.last_name',
                'students.email',
                DB::raw('COUNT(DISTINCT cs.id) as session_total'),
                DB::raw('MIN(cs.session_date) as next_session_date'),
                DB::raw('MIN(cs.start_time) as next_session_time')
            )
            ->groupBy('students.id', 'students.first_name', 'students.last_name', 'students.email')
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                $name = trim(collect([$row->first_name, $row->last_name])->filter()->implode(' '));
                $nextDate = $row->next_session_date ? Carbon::parse($row->next_session_date)->format('M j, Y') : null;
                $nextLabel = $nextDate;

                if ($nextDate && $row->next_session_time) {
                    $time = substr((string) $row->next_session_time, 0, 5);
                    $nextLabel = trim($nextDate.' · '.$time);
                }

                return [
                    'id' => (int) $row->id,
                    'name' => $name !== '' ? $name : (string) $row->email,
                    'email' => (string) $row->email,
                    'sessions' => (int) $row->session_total,
                    'present' => 0,
                    'late' => 0,
                    'absent' => 0,
                    'next_session' => $nextLabel,
                ];
            })
            ->values()
            ->all();

        $sessionCount = (clone $query)->distinct('cs.id')->count('cs.id');
        $calendarCounts = $this->summarizeCalendarCounts($query);
        $appointmentCounts = $this->summarizeAppointmentCounts($query);

        return [
            'emails' => $emails,
            'student_count' => $studentCount,
            'attendance_count' => $attendanceCount,
            'summary' => [
                'context' => 'upcoming',
                'students' => [
                    'list' => $studentRows,
                    'total' => $studentCount,
                    'truncated' => $studentCount > count($studentRows),
                ],
                'sessions' => [
                    'total' => (int) $sessionCount,
                    'status_counts' => [
                        'present' => 0,
                        'late' => 0,
                        'absent' => 0,
                        'cancelled' => 0,
                    ],
                    'calendar_counts' => $calendarCounts,
                    'appointment_counts' => $appointmentCounts,
                ],
            ],
        ];
    }

    private function summarizeCalendarCounts($query): array
    {
        return (clone $query)
            ->select(
                DB::raw("COALESCE(NULLIF(cs.calendar_name, ''), 'Unknown') as calendar_name"),
                DB::raw('COUNT(DISTINCT cs.id) as aggregate')
            )
            ->groupBy('calendar_name')
            ->orderByDesc('aggregate')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->calendar_name,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();
    }

    private function summarizeAppointmentCounts($query): array
    {
        return (clone $query)
            ->select(
                DB::raw("COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cs.acuity_data, '$.type'))), ''), NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cs.acuity_data, '$.appointmentType'))), ''), 'Unknown') as appointment_type"),
                DB::raw('COUNT(DISTINCT cs.id) as aggregate')
            )
            ->groupBy('appointment_type')
            ->orderByDesc('aggregate')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->appointment_type,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();
    }
}
