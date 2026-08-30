<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Services\Reporting\UkReportActionService;
use App\Services\Reporting\UkReportIntelligenceService;
use App\Services\Reporting\UkReportPriorityService;
use App\Support\Concerns\InterpretsAcuityFields;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UkReports extends Page
{
    use RequiresRegionAccess;
    use WithPagination;
    use InterpretsAcuityFields;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Reports';
    protected static ?string $title = 'Reports (UK)';
    protected static ?string $navigationGroup = 'UK';
    protected static ?int $navigationSort = 6;
    protected static string $view = 'filament.pages.uk-reports';
    protected static ?string $slug = 'uk-reports';
    public static string $requiredRegion = 'UK';
    protected static bool $allowsUkManagerAccess = true;

    public ?string $fromDate = null;
    public ?string $toDate = null;
    public string $rangePreset = 'last30';
    /**
     * @var list<string>
     */
    public array $selectedCalendars = [];
    /**
     * @var list<string>
     */
    public array $selectedAppointmentTypes = [];
    public string $deliveryMode = 'all';
    public ?string $teacherId = null;
    public bool $onlyLowAttendance = false;
    public bool $includeCancelled = false;
    public int $perPage = 15;
    public array $topPriorities = [];

    protected string $paginationTheme = 'tailwind';

    protected ?Collection $sessionRowsCache = null;
    protected array $groupedClassCache = [];
    protected ?array $attentionCardsCache = null;
    protected ?array $kpiStripCache = null;
    protected ?array $classOutlookCache = null;
    protected ?array $intelligenceCache = null;
    protected ?array $priorityCache = null;

    protected $queryString = [
        'page' => ['except' => 1],
        'fromDate' => ['except' => null, 'as' => 'from'],
        'toDate' => ['except' => null, 'as' => 'to'],
        'rangePreset' => ['except' => 'last30', 'as' => 'preset'],
        'selectedCalendars' => ['except' => [], 'as' => 'cal'],
        'selectedAppointmentTypes' => ['except' => [], 'as' => 'appt'],
        'deliveryMode' => ['except' => 'all', 'as' => 'mode'],
        'teacherId' => ['except' => null, 'as' => 'teacher'],
        'onlyLowAttendance' => ['except' => false, 'as' => 'low'],
        'includeCancelled' => ['except' => false, 'as' => 'canceled'],
        'perPage' => ['except' => 15, 'as' => 'per'],
    ];

    protected const LOW_ATTENDANCE_THRESHOLD = 75.0;
    protected const CRITICAL_ATTENDANCE_THRESHOLD = 60.0;
    protected const INACTIVE_DAYS = 45;
    protected const NEW_STUDENT_WINDOW_DAYS = 7;

    public static function getNavigationGroup(): ?string
    {
        return 'UK';
    }

    public static function canAccess(): bool
    {
        if (! static::userHasRequiredRegion()) {
            return false;
        }

        $user = Auth::user();
        if (! $user || ! $user->hasDomainAccess('reporting')) {
            return false;
        }

        return parent::canAccess();
    }

    public function mount(): void
    {
        $this->selectedCalendars = $this->sanitizeStringArray($this->selectedCalendars);
        $this->selectedAppointmentTypes = $this->sanitizeStringArray($this->selectedAppointmentTypes);
        $this->deliveryMode = in_array($this->deliveryMode, ['all', 'virtual', 'in_person'], true)
            ? $this->deliveryMode
            : 'all';

        $this->perPage = in_array((int) $this->perPage, [10, 15, 25, 50, 100], true)
            ? (int) $this->perPage
            : 15;

        if (! $this->fromDate || ! $this->toDate) {
            $this->applyPresetDates($this->rangePreset);
        } else {
            $this->rangePreset = 'custom';
        }
    }

    public function applyFilters(): void
    {
        $this->selectedCalendars = $this->sanitizeStringArray($this->selectedCalendars);
        $this->selectedAppointmentTypes = $this->sanitizeStringArray($this->selectedAppointmentTypes);
        $this->teacherId = $this->teacherId !== null && $this->teacherId !== '' ? (string) $this->teacherId : null;
        $this->perPage = in_array((int) $this->perPage, [10, 15, 25, 50, 100], true)
            ? (int) $this->perPage
            : 15;

        if ($this->rangePreset !== 'custom') {
            $this->rangePreset = 'custom';
        }

        $this->invalidateCache();
        $this->resetPage();
        $this->dispatch('$refresh');
    }

    public function resetFilters(): void
    {
        $this->selectedCalendars = [];
        $this->selectedAppointmentTypes = [];
        $this->deliveryMode = 'all';
        $this->teacherId = null;
        $this->onlyLowAttendance = false;
        $this->includeCancelled = false;
        $this->perPage = 15;
        $this->applyPresetDates('last30');
        $this->invalidateCache();
        $this->resetPage();
        $this->dispatch('$refresh');
    }

    public function applyPreset(string $preset): void
    {
        $this->rangePreset = $preset;
        $this->applyPresetDates($preset);
        $this->invalidateCache();
        $this->resetPage();
        $this->dispatch('$refresh');
    }

    public function updatedFromDate(): void
    {
        $this->rangePreset = 'custom';
        $this->invalidateCache();
    }

    public function updatedToDate(): void
    {
        $this->rangePreset = 'custom';
        $this->invalidateCache();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array((int) $this->perPage, [10, 15, 25, 50, 100], true)
            ? (int) $this->perPage
            : 15;
        $this->resetPage();
        $this->invalidateCache();
    }

    public function downloadCsv(): StreamedResponse
    {
        $rows = $this->sessionRows();

        $filename = 'uk-attendance-report-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            if (! $handle) {
                return;
            }

            fputcsv($handle, [
                'Session Date',
                'Start Time',
                'End Time',
                'Calendar',
                'Appointment Type',
                'Teacher',
                'Delivery Mode',
                'Present',
                'Late',
                'Absent',
                'Attendance %',
                'Pending Absences',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->session_date,
                    $row->start_time,
                    $row->end_time,
                    $row->calendar_label,
                    $row->appointment_type ?? '—',
                    $row->teacher_label,
                    $row->delivery_label,
                    $row->present_count,
                    $row->late_count,
                    $row->absent_count,
                    $row->attendance_rate !== null ? number_format($row->attendance_rate, 1) : '—',
                    $row->pending_absence_count,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function attentionRequiredCards(): array
    {
        if (is_array($this->attentionCardsCache)) {
            return $this->attentionCardsCache;
        }

        $base = $this->ukStudentBaseQuery();
        $tz = config('app.timezone', 'UTC');
        $now = Carbon::now($tz);
        $today = $now->toDateString();
        $recentCutoff = $now->copy()->subDays(max(0, self::NEW_STUDENT_WINDOW_DAYS - 1))->startOfDay();
        $inactiveCutoff = $now->copy()->subDays(self::INACTIVE_DAYS)->toDateString();

        $lowAttendance = (clone $base)
            ->whereNotNull('attendance_rate')
            ->where('attendance_rate', '<', self::CRITICAL_ATTENDANCE_THRESHOLD)
            ->count();

        $noUpcoming = (clone $base)
            ->where(function ($query) use ($today) {
                $query->whereNull('next_appointment_date')
                    ->orWhereDate('next_appointment_date', '<', $today);
            })
            ->count();

        $newStudents = (clone $base)
            ->whereNotNull('created_at')
            ->where('created_at', '>=', $recentCutoff)
            ->count();

        $inactive = (clone $base)
            ->where(function ($query) use ($inactiveCutoff) {
                $query->whereNull('last_appointment_date')
                    ->orWhereDate('last_appointment_date', '<', $inactiveCutoff);
            })
            ->count();

        $cards = [
            [
                'key' => 'low_attendance',
                'label' => 'Low attendance (<60%)',
                'description' => 'Students below 60% attendance',
                'count' => (int) $lowAttendance,
                'tone' => 'danger',
                'icon' => 'heroicon-o-exclamation-triangle',
                'url' => $this->studentIndexUrl([
                    'critical_attendance' => ['value' => true],
                ]),
            ],
            [
                'key' => 'no_upcoming',
                'label' => 'No upcoming classes',
                'description' => 'Next appointment missing or past due',
                'count' => (int) $noUpcoming,
                'tone' => 'warning',
                'icon' => 'heroicon-o-calendar-days',
                'url' => $this->studentIndexUrl([
                    'no_upcoming' => ['value' => true],
                ]),
            ],
            [
                'key' => 'new_students',
                'label' => 'New in last 7 days',
                'description' => 'Fresh enrolments to welcome',
                'count' => (int) $newStudents,
                'tone' => 'info',
                'icon' => 'heroicon-o-user-plus',
                'url' => $this->studentIndexUrl([
                    'new_last7' => ['value' => true],
                ]),
            ],
            [
                'key' => 'inactive',
                'label' => 'Inactive (45+ days)',
                'description' => 'No session in the last 45 days',
                'count' => (int) $inactive,
                'tone' => 'danger',
                'icon' => 'heroicon-o-user-minus',
                'url' => $this->studentIndexUrl([
                    'inactive_45' => ['value' => true],
                ]),
            ],
        ];

        return $this->attentionCardsCache = $cards;
    }

    public function kpiStripMetrics(): array
    {
        if (is_array($this->kpiStripCache)) {
            return $this->kpiStripCache;
        }

        $base = $this->ukStudentBaseQuery();
        $activeStudents = (clone $base)->where('is_active_recent', true)->count();
        $averageAttendance = (clone $base)
            ->whereNotNull('attendance_rate')
            ->avg('attendance_rate');
        $averageAttendance = $averageAttendance !== null
            ? round((float) $averageAttendance, 1)
            : null;

        $tz = config('app.timezone', 'UTC');
        $today = Carbon::today($tz);
        $todayGroups = $this->groupedClassesForRange($today, $today);
        $classesToday = $todayGroups->count();
        $fillRate = $this->calculateFillRatePercentage($todayGroups);

        return $this->kpiStripCache = [
            'active_students' => (int) $activeStudents,
            'average_attendance' => $averageAttendance,
            'classes_today' => $classesToday,
            'fill_rate' => $fillRate,
        ];
    }

    public function classOutlook(): array
    {
        if (is_array($this->classOutlookCache)) {
            return $this->classOutlookCache;
        }

        $tz = config('app.timezone', 'UTC');
        $today = Carbon::today($tz);
        $weekStart = $today->copy()->addDay();
        $weekEnd = $today->copy()->addDays(7);

        $todayGroups = $this->groupedClassesForRange($today, $today);
        $weekGroups = $this->groupedClassesForRange($weekStart, $weekEnd);

        return $this->classOutlookCache = [
            'today' => $this->formatClassOutlookBlock('Today', $today, $today, $todayGroups),
            'upcoming' => $this->formatClassOutlookBlock('Next 7 days', $weekStart, $weekEnd, $weekGroups),
        ];
    }

    public function attendanceTrendInsight(): ?array
    {
        $insights = $this->intelligenceInsights();

        return $insights['attendance_trend'] ?? null;
    }

    public function studentRiskInsight(): array
    {
        $insights = $this->intelligenceInsights();

        return $insights['student_risks'] ?? ['count' => 0, 'students' => []];
    }

    public function classRiskInsight(): array
    {
        $insights = $this->intelligenceInsights();

        return $insights['class_risks'] ?? ['count' => 0, 'classes' => []];
    }

    public function topPriorities(): array
    {
        if (is_array($this->priorityCache)) {
            return $this->priorityCache;
        }

        $priorityService = app(UkReportPriorityService::class);
        $priorities = $priorityService->build(
            $this->intelligenceInsights(),
            [
                'attendance_trend_url' => $this->attendanceTrendUrl(),
                'student_risk_url' => $this->highRiskStudentsUrl(),
                'class_risk_url' => $this->classRiskUrl(),
            ]
        );

        $actionService = app(UkReportActionService::class);
        $this->topPriorities = $actionService->attach($priorities, [
            'student_risk_url' => $this->highRiskStudentsUrl(),
            'student_blast_url' => route('filament.admin.pages.uk-student-blast'),
            'class_risk_url' => $this->classRiskUrl(),
            'low_attendance_url' => $this->studentIndexUrl([
                'critical_attendance' => ['value' => true],
            ]),
            'attendance_export_url' => route('admin.uk-reports.export', request()->query()),
            'attendance_trend_url' => $this->attendanceTrendUrl(),
        ]);

        logger('TOP PRIORITIES:', $this->topPriorities);

        return $this->topPriorities;
    }

    public function getViewData(): array
    {
        return [
            'topPriorities' => $this->topPriorities,
        ];
    }

    public function summaryMetrics(): array
    {
        $rows = $this->sessionRows();
        $sessionCount = $rows->count();

        $totalMarked = $rows->sum(fn ($row) => $row->total_marked);
        $totalAttended = $rows->sum(fn ($row) => $row->attended_count);
        $averageAttendance = $totalMarked > 0
            ? round(($totalAttended / $totalMarked) * 100, 1)
            : null;

        $pendingAbsence = $rows->sum(fn ($row) => $row->pending_absence_count);

        $uniqueStudents = $this->uniqueStudentCount($rows);

        $lowAttendanceSessions = $rows->filter(fn ($row) => $row->attendance_rate !== null && $row->attendance_rate < self::LOW_ATTENDANCE_THRESHOLD)->count();

        return [
            'total_sessions' => $sessionCount,
            'average_attendance' => $averageAttendance,
            'unique_students' => $uniqueStudents,
            'pending_absence' => $pendingAbsence,
            'low_attendance_sessions' => $lowAttendanceSessions,
        ];
    }

    public function calendarBreakdown(): array
    {
        return $this->groupedBreakdown('calendar_label', 'Unknown calendar', true);
    }

    public function appointmentTypeBreakdown(): array
    {
        return $this->groupedBreakdown('appointment_type', 'Unknown appointment');
    }

    public function deliveryModeBreakdown(): array
    {
        return $this->groupedBreakdown('delivery_label');
    }

    public function teacherBreakdown(): array
    {
        return $this->groupedBreakdown('teacher_label', 'Unassigned teacher');
    }

    public function paginatedSessions(): LengthAwarePaginator
    {
        $rows = $this->sessionRows();
        $page = $this->page ?? 1;
        $perPage = $this->perPage;

        $items = $rows->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    public function lowAttendanceSessions(): array
    {
        return $this->sessionRows()
            ->filter(fn ($row) => $row->attendance_rate !== null && $row->attendance_rate < self::LOW_ATTENDANCE_THRESHOLD)
            ->sortBy('attendance_rate')
            ->take(5)
            ->values()
            ->all();
    }

    public function pendingFollowUps(): array
    {
        return $this->sessionRows()
            ->filter(fn ($row) => $row->pending_absence_count > 0)
            ->sortByDesc('pending_absence_count')
            ->take(5)
            ->values()
            ->all();
    }

    public function availableCalendars(): array
    {
        $query = DB::table('class_sessions as cs')
            ->select('cs.calendar_name')
            ->whereRaw('LOWER(COALESCE(cs.location, "")) = ?', ['uk'])
            ->whereNotNull('cs.calendar_name')
            ->distinct()
            ->orderBy('cs.calendar_name');

        if ($this->fromDate) {
            $query->where('cs.session_date', '>=', $this->fromDate);
        }
        if ($this->toDate) {
            $query->where('cs.session_date', '<=', $this->toDate);
        }

        return $query->pluck('calendar_name')->map(fn ($name) => (string) $name)->values()->all();
    }

    public function availableTeachers(): array
    {
        $query = DB::table('class_sessions as cs')
            ->join('users as u', 'u.id', '=', 'cs.teacher_id')
            ->select('u.id', 'u.name', 'u.email')
            ->whereRaw('LOWER(COALESCE(cs.location, "")) = ?', ['uk'])
            ->distinct()
            ->orderBy('u.name');

        if ($this->fromDate) {
            $query->where('cs.session_date', '>=', $this->fromDate);
        }
        if ($this->toDate) {
            $query->where('cs.session_date', '<=', $this->toDate);
        }

        return $query
            ->get()
            ->mapWithKeys(function ($row) {
                $label = (string) ($row->name ?? $row->email ?? 'Teacher #' . $row->id);
                return [(string) $row->id => $label];
            })
            ->all();
    }

    public function availableAppointmentTypes(): array
    {
        return $this->sessionRows()
            ->pluck('appointment_type')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function ukStudentBaseQuery()
    {
        return DB::table('students')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->where('in_uk', true)
                    ->orWhereRaw('LOWER(COALESCE(location, "")) = ?', ['uk']);
            });
    }

    protected function studentIndexUrl(array $filters = []): string
    {
        $url = route('filament.admin.resources.u-k-students.index');

        if (empty($filters)) {
            return $url;
        }

        return $url . '?' . Arr::query([
            'tableFilters' => $filters,
        ]);
    }

    protected function groupedClassesForRange(Carbon $from, Carbon $to): Collection
    {
        $key = $from->toDateString() . '_' . $to->toDateString();

        if (array_key_exists($key, $this->groupedClassCache)) {
            return $this->groupedClassCache[$key];
        }

        $calendarExpr = $this->qualifyAcuityExpression($this->calendarExpr());
        $typeIdExpr = $this->qualifyAcuityExpression($this->appointmentTypeIdExpr());
        $typeLabelExpr = $this->qualifyAcuityExpression($this->appointmentTypeLabelExpr());

        $results = DB::table('class_sessions as cs')
            ->leftJoin('users as teacher', 'teacher.id', '=', 'cs.teacher_id')
            ->select([
                DB::raw('MIN(cs.id) as id'),
                'cs.session_date',
                'cs.start_time',
                'cs.end_time',
                DB::raw("COALESCE(NULLIF(MAX(cs.calendar_name), ''), 'Unknown calendar') as calendar_label"),
                'cs.teacher_id',
                DB::raw('MAX(teacher.name) as teacher_name'),
                DB::raw('MAX(teacher.email) as teacher_email'),
                DB::raw("COALESCE(MAX({$typeLabelExpr}), MAX({$typeIdExpr}), 'Class') as appointment_label"),
                DB::raw('COUNT(*) as booking_count'),
                DB::raw('COUNT(DISTINCT CASE WHEN cs.student_id IS NOT NULL THEN cs.student_id END) as student_count'),
                DB::raw('MAX(COALESCE(cs.max_students, 0)) as capacity'),
            ])
            ->whereBetween('cs.session_date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query) {
                $query->where('cs.canceled', false)
                    ->orWhereNull('cs.canceled')
                    ->orWhereIn('cs.status', ['scheduled', 'confirmed']);
            })
            ->where(function ($query) {
                $query->whereRaw('LOWER(COALESCE(cs.location, "")) = ?', ['uk'])
                    ->orWhereRaw('LOWER(COALESCE(cs.category_norm, "")) = ?', ['uk']);
            })
            ->groupBy(
                'cs.session_date',
                'cs.start_time',
                'cs.end_time',
                'cs.teacher_id',
                DB::raw($calendarExpr),
                DB::raw($typeIdExpr)
            )
            ->orderBy('cs.session_date')
            ->orderBy('cs.start_time')
            ->limit(200)
            ->get();

    	$groups = $results->map(function ($row) {
            $studentCount = (int) $row->student_count;
            $capacity = (int) $row->capacity;
            $isEmpty = $studentCount === 0;
            $isOverbooked = $capacity > 0 && $studentCount > $capacity;
            $isMissingTeacher = $row->teacher_id === null;
            $teacherLabel = $row->teacher_name ?: ($row->teacher_email ?: 'Unassigned');

            return [
                'id' => (int) $row->id,
                'session_date' => $row->session_date,
                'start_time' => $row->start_time,
                'end_time' => $row->end_time,
                'calendar_label' => $row->calendar_label,
                'appointment_label' => $row->appointment_label,
                'teacher_label' => $teacherLabel,
                'booking_count' => (int) $row->booking_count,
                'student_count' => $studentCount,
                'capacity' => $capacity > 0 ? $capacity : null,
                'is_empty' => $isEmpty,
                'is_overbooked' => $isOverbooked,
                'is_missing_teacher' => $isMissingTeacher,
                'url' => $this->ukReportsLinkForClass($row->session_date, $row->calendar_label),
            ];
        })->values();

        return $this->groupedClassCache[$key] = $groups;
    }

    protected function formatClassOutlookBlock(string $label, Carbon $from, Carbon $to, Collection $groups): array
    {
        $rangeLabel = $from->equalTo($to)
            ? $from->format('D d M')
            : $from->format('d M') . ' – ' . $to->format('d M');

        return [
            'label' => $label,
            'range_label' => $rangeLabel,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'url' => $this->ukReportsRangeUrl($from, $to),
            'groups' => $groups,
            'issues' => $this->summarizeClassIssues($groups),
        ];
    }

    protected function summarizeClassIssues(Collection $groups): array
    {
        return [
            'empty' => $groups->where('is_empty', true)->count(),
            'overbooked' => $groups->where('is_overbooked', true)->count(),
            'missing_teacher' => $groups->where('is_missing_teacher', true)->count(),
        ];
    }

    protected function calculateFillRatePercentage(Collection $groups): ?float
    {
        $capacity = $groups->sum(fn ($group) => $group['capacity'] ?? 0);

        if ($capacity <= 0) {
            return null;
        }

        $students = $groups->sum(fn ($group) => $group['student_count']);

        if ($students <= 0) {
            return 0.0;
        }

        return round(min(100, ($students / max($capacity, 1)) * 100), 1);
    }

    protected function ukReportsLinkForClass(string $date, ?string $calendar = null): string
    {
        $params = [
            'from' => $date,
            'to' => $date,
            'preset' => 'custom',
        ];

        if (is_string($calendar) && trim($calendar) !== '') {
            $params['cal'] = [$calendar];
        }

        return route('filament.admin.pages.uk-reports') . '?' . Arr::query($params);
    }

    protected function ukReportsRangeUrl(Carbon $from, Carbon $to, array $extra = []): string
    {
        $params = array_merge([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'preset' => 'custom',
        ], $extra);

        return route('filament.admin.pages.uk-reports') . '?' . Arr::query($params);
    }

    public function attendanceTrendUrl(): string
    {
        $tz = config('app.timezone', 'UTC');
        $end = Carbon::today($tz);
        $start = $end->copy()->subDays(6);

        return $this->ukReportsRangeUrl($start, $end);
    }

    public function highRiskStudentsUrl(): string
    {
        return $this->studentIndexUrl([
            'high_risk' => ['isActive' => true],
        ]);
    }

    public function classRiskUrl(): string
    {
        $now = Carbon::now(config('app.timezone', 'UTC'));
        $end = $now->copy()->addHours(48);

        return route('filament.admin.resources.u-k-upcomings.index', [
            'tableFilters' => [
                'date_range' => [
                    'from' => $now->toDateString(),
                    'until' => $end->toDateString(),
                ],
            ],
        ]);
    }

    protected function intelligenceInsights(): array
    {
        if (is_array($this->intelligenceCache)) {
            return $this->intelligenceCache;
        }

        $service = app(UkReportIntelligenceService::class);
        $this->intelligenceCache = $service->buildInsights();

        return $this->intelligenceCache;
    }

    protected function qualifyAcuityExpression(string $expression): string
    {
        return str_replace(
            ['acuity_data', 'calendar_norm', 'category_norm'],
            ['cs.acuity_data', 'cs.calendar_norm', 'cs.category_norm'],
            $expression,
        );
    }

    protected function sessionRows(): Collection
    {
        if ($this->sessionRowsCache instanceof Collection) {
            return $this->sessionRowsCache;
        }

        $rows = $this->buildSessionRows();
        $this->sessionRowsCache = $rows;

        return $rows;
    }

    protected function buildSessionRows(): Collection
    {
        $query = $this->baseAttendanceBuilder();

        $results = $query->get();

        $rows = $results->map(function ($row) {
            $rawAppointmentType = $this->extractAppointmentType($row->acuity_data ?? null, $row->appointment_type_name ?? $row->appointment_type ?? null);
            $normalizedAppointmentType = strtolower(trim($rawAppointmentType));

            $totalMarked = (int) $row->present_count + (int) $row->late_count + (int) $row->absent_count;
            $attendedCount = (int) $row->present_count + (int) $row->late_count;
            $attendanceRate = $totalMarked > 0
                ? round(($attendedCount / $totalMarked) * 100, 1)
                : null;

            $carbonDate = $row->session_date ? Carbon::parse($row->session_date) : null;

            $deliveryKey = $this->appointmentTypeIsOnline($normalizedAppointmentType) ? 'online' : 'in_person';
            $deliveryLabel = $deliveryKey === 'online' ? 'Online' : 'In Person';
            $teacherLabel = $row->teacher_name ?? $row->teacher_email ?? 'Unassigned';
            $calendarLabel = $row->calendar_name ?? 'Unknown calendar';

            return (object) [
                'session_id' => (int) $row->session_id,
                'session_date' => $row->session_date,
                'session_date_carbon' => $carbonDate,
                'start_time' => $row->start_time,
                'end_time' => $row->end_time,
                'calendar_name' => $row->calendar_name,
                'calendar_label' => $calendarLabel,
                'appointment_type' => $rawAppointmentType !== '' ? $rawAppointmentType : null,
                'normalized_appointment_type' => $normalizedAppointmentType,
                'delivery_label' => $deliveryLabel,
                'delivery_key' => $deliveryKey,
                'teacher_id' => $row->teacher_id ? (int) $row->teacher_id : null,
                'teacher_label' => $teacherLabel,
                'present_count' => (int) $row->present_count,
                'late_count' => (int) $row->late_count,
                'absent_count' => (int) $row->absent_count,
                'cancelled_count' => (int) $row->cancelled_count,
                'pending_absence_count' => (int) $row->pending_absence_count,
                'sent_absence_count' => (int) $row->sent_absence_count,
                'unique_students' => (int) $row->unique_students,
                'attended_count' => $attendedCount,
                'total_marked' => $totalMarked,
                'attendance_rate' => $attendanceRate,
                'last_marked_at' => $row->last_marked_at,
            ];
        });

        if (! empty($this->selectedAppointmentTypes)) {
            $filters = array_map(fn ($value) => strtolower(trim((string) $value)), $this->selectedAppointmentTypes);
            $rows = $rows->filter(function ($row) use ($filters) {
                if (! $row->normalized_appointment_type) {
                    return false;
                }

                return in_array($row->normalized_appointment_type, $filters, true);
            });
        }

        if ($this->onlyLowAttendance) {
            $rows = $rows->filter(function ($row) {
                return $row->attendance_rate !== null && $row->attendance_rate < self::LOW_ATTENDANCE_THRESHOLD;
            });
        }

        return $rows->values();
    }

    protected function baseAttendanceBuilder()
    {
        $query = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->leftJoin('users as teacher', 'teacher.id', '=', 'cs.teacher_id')
            ->select([
                'cs.id as session_id',
                'cs.session_date',
                'cs.start_time',
                'cs.end_time',
                'cs.calendar_name',
                'cs.is_virtual',
                'cs.teacher_id',
                'cs.acuity_data',
                'teacher.name as teacher_name',
                'teacher.email as teacher_email',
                DB::raw('COUNT(DISTINCT ar.student_id) as unique_students'),
                DB::raw("SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count"),
                DB::raw("SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as late_count"),
                DB::raw("SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count"),
                DB::raw("SUM(CASE WHEN ar.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count"),
                DB::raw("SUM(CASE WHEN ar.status = 'absent' AND ar.sent_at IS NULL THEN 1 ELSE 0 END) as pending_absence_count"),
                DB::raw("SUM(CASE WHEN ar.status = 'absent' AND ar.sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent_absence_count"),
                DB::raw('MAX(ar.marked_at) as last_marked_at'),
            ])
            ->whereIn('ar.status', ['present', 'late', 'absent', 'cancelled'])
            ->whereRaw('LOWER(COALESCE(cs.location, "")) = ?', ['uk'])
            ->groupBy([
                'cs.id',
                'cs.session_date',
                'cs.start_time',
                'cs.end_time',
                'cs.calendar_name',
                'cs.is_virtual',
                'cs.teacher_id',
                'cs.acuity_data',
                'teacher.name',
                'teacher.email',
            ]);

        if ($this->fromDate) {
            $query->where('cs.session_date', '>=', $this->fromDate);
        }
        if ($this->toDate) {
            $query->where('cs.session_date', '<=', $this->toDate);
        }

        if (! empty($this->selectedCalendars)) {
            $query->whereIn('cs.calendar_name', $this->selectedCalendars);
        }

        if ($this->deliveryMode === 'virtual') {
            $query->where(function ($inner) {
                $inner->where('cs.is_virtual', true)
                    ->orWhere('cs.is_virtual', 1);
            });
        } elseif ($this->deliveryMode === 'in_person') {
            $query->where(function ($inner) {
                $inner->where('cs.is_virtual', false)
                    ->orWhere('cs.is_virtual', 0)
                    ->orWhereNull('cs.is_virtual');
            });
        }

        if ($this->teacherId) {
            $query->where('cs.teacher_id', (int) $this->teacherId);
        }

        if (! $this->includeCancelled) {
            $query->where(function ($inner) {
                $inner->whereNull('cs.canceled')
                    ->orWhere('cs.canceled', false)
                    ->orWhere('cs.canceled', 0);
            });
        }

        return $query->orderByDesc('cs.session_date')->orderByDesc('cs.start_time');
    }

    protected function groupedBreakdown(string $groupKey, string $fallback = 'Unknown', bool $useUniqueStudents = false): array
    {
        $rows = $this->sessionRows();

        return $rows
            ->groupBy(function ($row) use ($groupKey, $fallback) {
                $value = $row->{$groupKey} ?? null;
                $label = is_string($value) && trim($value) !== '' ? $value : $fallback;

                return $label;
            })
            ->map(function (Collection $group, $label) {
                $sessionCount = $group->count();
                $totalMarked = $group->sum(fn ($row) => $row->total_marked);
                $attended = $group->sum(fn ($row) => $row->attended_count);
                $rate = $totalMarked > 0 ? round(($attended / $totalMarked) * 100, 1) : null;

                $studentCount = $useUniqueStudents
                    ? $this->uniqueStudentCount($group, ['present', 'late'])
                    : $group->sum(fn ($row) => $row->unique_students);

                return [
                    'label' => $label,
                    'sessions' => $sessionCount,
                    'students' => $studentCount,
                    'pending_absence' => $group->sum(fn ($row) => $row->pending_absence_count),
                    'absent' => $group->sum(fn ($row) => $row->absent_count),
                    'attendance_rate' => $rate,
                ];
            })
            ->sortByDesc(fn ($item) => $item['sessions'])
            ->values()
            ->all();
    }

    protected function uniqueStudentCount(Collection $rows, ?array $statuses = null): int
    {
        $sessionIds = $rows->pluck('session_id')->filter()->unique()->values();

        if ($sessionIds->isEmpty()) {
            return 0;
        }

        $query = DB::table('attendance_records')
            ->whereIn('class_session_id', $sessionIds)
            ->whereNotNull('student_id');

        if (! empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        return (int) $query
            ->distinct('student_id')
            ->count('student_id');
    }

    protected function applyPresetDates(?string $preset): void
    {
        $preset = $preset ?? 'last30';
        $tz = config('app.timezone', 'UTC');
        $today = Carbon::today($tz);

        switch ($preset) {
            case 'last7':
                $from = $today->copy()->subDays(6);
                $to = $today->copy();
                break;
            case 'last30':
                $from = $today->copy()->subDays(29);
                $to = $today->copy();
                break;
            case 'current_month':
                $from = $today->copy()->startOfMonth();
                $to = $today->copy()->endOfMonth();
                break;
            case 'previous_month':
                $from = $today->copy()->subMonth()->startOfMonth();
                $to = $today->copy()->subMonth()->endOfMonth();
                break;
            case 'year_to_date':
                $from = $today->copy()->startOfYear();
                $to = $today->copy();
                break;
            default:
                $this->rangePreset = 'custom';
                return;
        }

        $this->fromDate = $from->toDateString();
        $this->toDate = $to->toDateString();
        $this->rangePreset = $preset;
    }

    protected function sanitizeStringArray(array $items): array
    {
        return array_values(array_filter(array_map(static function ($value) {
            if (is_string($value)) {
                return trim($value);
            }

            if (is_numeric($value)) {
                return (string) $value;
            }

            return null;
        }, Arr::wrap($items)), static fn ($value) => $value !== '' && $value !== null));
    }

    protected function extractAppointmentType(?string $payload, ?string $explicit = null): string
    {
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        if (! is_string($payload) || trim($payload) === '') {
            return '';
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return '';
        }

        $candidates = ['appointmentType', 'appointment_type', 'type', 'name'];
        foreach ($candidates as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    protected function appointmentTypeIsOnline(string $normalizedAppointmentType): bool
    {
        $normalized = strtolower(trim($normalizedAppointmentType));
        if ($normalized === '') {
            return false;
        }

        foreach (['online', 'on-line', 'on line', 'virtual', 'portal'] as $needle) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }

        $exactOnlineTypes = [
            'harbour english',
        ];

        return in_array($normalized, $exactOnlineTypes, true);
    }

    protected function invalidateCache(): void
    {
        $this->sessionRowsCache = null;
        $this->attentionCardsCache = null;
        $this->kpiStripCache = null;
        $this->classOutlookCache = null;
        $this->intelligenceCache = null;
        $this->priorityCache = null;
    }
}
