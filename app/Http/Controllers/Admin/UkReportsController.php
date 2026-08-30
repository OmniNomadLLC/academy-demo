<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Services\ActiveStudentsCounter;
use App\Services\AssessmentSkillAggregator;
use App\Services\LocationMappingService;
use App\Support\AttendanceMath;
use App\Support\Assessments\SkillCategory;
use App\Support\Reporting\UkReportPdfBuilder;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorImpl;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UkReportsController extends Controller
{
    public string $activeTab = 'attendance';
    protected const PDF_SECTION_OPTIONS = [
        'kpis' => 'KPI overview',
        'skill_development' => 'Skill development averages',
        'attendance_trend' => 'Attendance % over time',
        'sessions_per_day' => 'Sessions per day',
        'delivery_mode' => 'By delivery mode',
        'teacher_breakdown' => 'By teacher',
        'appointment_types' => 'By appointment type',
        'calendar_overview' => 'Calendar overview',
        'attention' => 'Sessions needing attention',
    ];

    /**
     * These routes sit behind ['web','auth'] only, so any authenticated user (incl.
     * teachers, whose portal is otherwise roster-scoped) could hit the HTML view and
     * the CSV/PDF exports and pull UK-wide attendance/teacher data. Mirror the Filament
     * UkReports page's own gate: the 'reporting' domain + UK region access.
     */
    private function authorizeUkReports(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user && $user->hasDomainAccess('reporting') && $user->hasRegionAccess('UK'),
            403
        );
    }

    public function index(Request $request)
    {
        $this->authorizeUkReports($request);

        $adminPanel = \Filament\Facades\Filament::getPanel('admin');
        \Filament\Facades\Filament::setCurrentPanel($adminPanel);
        \Filament\Facades\Filament::bootCurrentPanel();
        \Filament\Facades\Filament::setServingStatus();

        $filters = $this->resolveFilters($request);
        $page = (int) max(1, (int) $request->input('page', 1));

        $viewData = $this->buildReportData($filters, $page);

        $topPriorities = [];
        $averageAttendance = $viewData['kpis']['average_attendance'] ?? null;
        if (! is_null($averageAttendance) && $averageAttendance < 75) {
            $topPriorities[] = [
                'title' => 'Attendance below 75%',
                'severity' => 'critical',
                'actions' => [
                    [
                        'label' => 'View low attendance sessions',
                        'url' => $request->fullUrlWithQuery(['low_attendance' => 1, 'page' => 1, 'tab' => 'attendance']),
                    ],
                ],
            ];
        }

        $pendingEmails = $viewData['kpis']['pending_absence_sessions'] ?? 0;
        if ($pendingEmails > 0) {
            $topPriorities[] = [
                'title' => $pendingEmails.' sessions need follow-up emails',
                'severity' => 'high',
                'actions' => [
                    [
                        'label' => 'View sessions',
                        'url' => $request->fullUrlWithQuery(['pending' => 1, 'page' => 1, 'tab' => 'attendance']),
                    ],
                ],
            ];
        }

        $activeTab = $request->input('tab', $this->activeTab);
        $allowedTabs = ['attendance', 'development', 'outcomes'];
        $adminOnlyTabs = ['outcomes'];
        $isAdmin = $request->user()?->hasRole('admin', 'super_admin') ?? false;

        if (in_array($activeTab, $adminOnlyTabs, true) && ! $isAdmin) {
            $activeTab = 'attendance';
        }
        if (! in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'attendance';
        }

        $viewData['topPriorities'] = $topPriorities;
        $viewData['activeTab'] = $activeTab;

        return view('admin.uk-attendance-reports', $viewData);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorizeUkReports($request);

        $filters = $this->resolveFilters($request);
        $report = $this->buildReportData($filters);
        $sessions = $report['sessions'];

        $filename = 'uk-attendance-report.csv';

        return response()->streamDownload(function () use ($sessions): void {
            $handle = fopen('php://output', 'w');
            if (! $handle) {
                return;
            }

            fputcsv($handle, [
                'Date',
                'Calendar',
                'Appointment Type',
                'Teacher',
                'Delivery',
                'Present',
                'Late',
                'Absent',
                'Attendance %',
            ]);

            foreach ($sessions as $row) {
                fputcsv($handle, [
                    $row['session_date'],
                    $row['calendar_label'],
                    $row['appointment_type'] ?? '—',
                    $row['teacher']['name'] ?? 'Unassigned',
                    $row['delivery_label'],
                    $row['present_count'],
                    $row['late_count'],
                    $row['absent_count'],
                    $row['attendance_rate'] !== null ? number_format($row['attendance_rate'], 1) : '0.0',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportPdf(Request $request)
    {
        $this->authorizeUkReports($request);

        $filters = $this->resolveFilters($request);
        $report = $this->buildReportData($filters);

        $sections = $this->resolvePdfSections($request);

        $builder = new UkReportPdfBuilder($sections);
        $pdf = $builder->build($report);

        $filename = 'uk-attendance-report.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    protected function buildReportData(array $filters, int $page = 1): array
    {
        $sessions = $this->collectSessions($filters);
        $optionSessions = $this->collectSessions(array_merge($filters, [
            'calendar_ids' => [],
            'appointment_type_ids' => [],
            'teacher_id' => null,
        ]), applyAppointmentFilter: false);

        $calendarOptions = $optionSessions
            ->pluck('calendar_label')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $appointmentTypeOptions = $optionSessions
            ->pluck('appointment_type')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $categoryOptions = $optionSessions
            ->pluck('acuity_category')
            ->filter()
            ->unique()
            ->sort(fn ($a, $b) => strcasecmp($a, $b))
            ->values()
            ->all();

        $teacherOptions = $optionSessions
            ->pluck('teacher')
            ->filter(fn ($item) => isset($item['id']))
            ->unique('id')
            ->mapWithKeys(fn ($teacher) => [
                (string) $teacher['id'] => $teacher['name'],
            ])
            ->all();

        $kpis = $this->buildKpis($sessions);
        $breakdowns = $this->buildBreakdowns($sessions);

        $employmentKpis = $this->buildEmploymentKpis($sessions, $breakdowns);

        [$attendanceSeries, $sessionSeries] = $this->buildTimeSeries($sessions, $filters['from_date'], $filters['to_date']);

        $attention = $this->buildAttentionSets($sessions);

        $spotlight = $this->paginateSessions($sessions, $filters['per_page'], $page);

        $pendingAbsenceSessions = $sessions->filter(fn ($row) => $row['pending_absence_count'] > 0)->count();

        $uniqueStudentCount = $this->countUniqueStudents($sessions);

        $newStudentsCount = $this->buildNewStudentsCount($sessions, $filters['from_date']);

        $skillStats = $this->buildSkillDevelopmentStats($filters['from_date'], $filters['to_date']);

        return [
            'filters' => $filters,
            'sessions' => $sessions,
            'kpis' => array_merge($kpis, [
                'unique_students' => $uniqueStudentCount,
                'pending_absence_sessions' => $pendingAbsenceSessions,
                'new_students' => $newStudentsCount,
            ]),
            'calendarOptions' => $calendarOptions,
            'appointmentTypeOptions' => $appointmentTypeOptions,
            'categoryOptions' => $categoryOptions,
            'teacherOptions' => $teacherOptions,
            'breakdowns' => $breakdowns,
            'employmentKpis' => $employmentKpis,
            'seriesAttendanceOverTime' => $attendanceSeries,
            'seriesSessionsOverTime' => $sessionSeries,
            'sessionsNeedingAttention' => $attention['lowest'] ?? null,
            'pendingFollowUps' => $attention['pending'] ?? [],
            'spotlight' => $spotlight,
            'pdfSectionOptions' => self::PDF_SECTION_OPTIONS,
            'pdfDefaultSections' => array_keys(self::PDF_SECTION_OPTIONS),
            'skillStats' => $skillStats,
            'activeUkStudents' => app(ActiveStudentsCounter::class)->countForRegion('uk'),
        ];
    }

    protected function buildSkillDevelopmentStats(string $from, string $to): array
    {
        $empty = $this->emptySkillStats();

        $fromCarbon = Carbon::parse($from)->startOfDay();
        $toCarbon = Carbon::parse($to)->endOfDay();

        $ukStudentIds = Student::forRegion('UK')->pluck('id');

        if ($ukStudentIds->isEmpty()) {
            return $empty;
        }

        $latestAssessmentIds = StudentAssessment::query()
            ->where('status', StudentAssessment::STATUS_FINAL)
            ->whereIn('student_id', $ukStudentIds)
            ->whereBetween('assessed_at', [$fromCarbon, $toCarbon])
            ->select('student_id', DB::raw('MAX(id) as id'))
            ->groupBy('student_id')
            ->pluck('id');

        if ($latestAssessmentIds->isEmpty()) {
            return $empty;
        }

        $assessments = StudentAssessment::query()
            ->with(['answers', 'template.questions', 'items.templateQuestion'])
            ->whereIn('id', $latestAssessmentIds)
            ->get();

        $aggregator = app(AssessmentSkillAggregator::class);

        $perSkillScores = collect(SkillCategory::all())
            ->mapWithKeys(fn (string $skill) => [$skill => []])
            ->all();

        foreach ($assessments as $assessment) {
            $aggregated = $aggregator->aggregate($assessment);

            foreach ($aggregated as $row) {
                if (empty($row['is_empty'])) {
                    $perSkillScores[$row['skill']][] = (float) $row['score'];
                }
            }
        }

        $stats = $empty;
        $stats['student_count'] = $assessments->count();

        $skillAverages = [];
        foreach (SkillCategory::all() as $skill) {
            $scores = $perSkillScores[$skill];
            if (count($scores) > 0) {
                $avg = round(array_sum($scores) / count($scores), 1);
                $stats[$skill] = $avg;
                $skillAverages[] = $avg;
            }
        }

        if (count($skillAverages) > 0) {
            $stats['overall'] = round(array_sum($skillAverages) / count($skillAverages), 1);
        }

        return $stats;
    }

    protected function emptySkillStats(): array
    {
        return [
            'overall' => null,
            SkillCategory::SPEAKING_LISTENING => null,
            SkillCategory::READING_WRITING => null,
            SkillCategory::TO_LEARN => null,
            SkillCategory::WORK_READINESS => null,
            SkillCategory::IT_SKILLS => null,
            'student_count' => 0,
        ];
    }

    protected function resolveFilters(Request $request): array
    {
        $tz = config('app.timezone', 'UTC');
        $now = Carbon::now($tz);
        $defaultFrom = $now->copy()->subDays(30)->startOfDay();
        $defaultTo = $now->copy()->endOfDay();

        $presetInput = $request->filled('preset') ? trim((string) $request->input('preset')) : '';
        $presetRange = $presetInput !== '' ? $this->resolvePresetRange($presetInput, $now, $tz) : null;

        if ($presetRange) {
            $from = $presetRange['from'];
            $to = $presetRange['to'];
            $appliedPreset = $presetInput;
            $fromDateInput = null;
            $toDateInput = null;
        } else {
            $fromDateInput = $request->input('from_date');
            $toDateInput = $request->input('to_date');

            $from = $fromDateInput ? Carbon::parse($fromDateInput, $tz)->startOfDay() : $defaultFrom;
            $to = $toDateInput ? Carbon::parse($toDateInput, $tz)->endOfDay() : $defaultTo;

            if (! $fromDateInput && ! $toDateInput) {
                $appliedPreset = 'last_30_days';
            } else {
                $appliedPreset = $this->matchPresetFromRange($from, $to, $now, $tz);
            }
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPageOptions = [10, 15, 25, 50, 100];
        if (! in_array($perPage, $perPageOptions, true)) {
            $perPage = 15;
        }

        $fromBound = $from->copy()->startOfDay();
        $toBound = $to->copy()->endOfDay();

        return [
            'from_date' => $fromBound->toDateString(),
            'to_date' => $toBound->toDateString(),
            'from_bound' => $fromBound->toDateTimeString(),
            'to_bound' => $toBound->toDateTimeString(),
            'preset' => $appliedPreset,
            'delivery_mode' => $request->input('delivery_mode', 'all'),
            'calendar_ids' => $this->sanitizeStringArray($request->input('calendar_ids', [])),
            'appointment_type_ids' => $this->sanitizeStringArray($request->input('appointment_type_ids', [])),
            'teacher_id' => $request->filled('teacher_id') ? (int) $request->input('teacher_id') : null,
            'acuity_category' => $this->sanitizeString($request->input('acuity_category')),
            'per_page' => $perPage,
            'include_cancelled' => $request->boolean('include_cancelled', false),
            'show_pending_only' => $request->boolean('pending', false),
            'show_low_attendance_only' => $request->boolean('low_attendance', false),
        ];
    }

    /**
     * Compute the absolute from/to range for a named preset, evaluated
     * against $now so each request gets a freshly-rolling window.
     *
     * @return array{from: Carbon, to: Carbon}|null
     */
    protected function resolvePresetRange(string $preset, Carbon $now, string $tz): ?array
    {
        switch ($preset) {
            case 'this_week':
                return [
                    'from' => $now->copy()->startOfWeek()->startOfDay(),
                    'to' => $now->copy()->endOfWeek()->endOfDay(),
                ];
            case 'last_30_days':
                return [
                    'from' => $now->copy()->subDays(30)->startOfDay(),
                    'to' => $now->copy()->endOfDay(),
                ];
            case 'this_month':
                return [
                    'from' => $now->copy()->startOfMonth()->startOfDay(),
                    'to' => $now->copy()->endOfMonth()->endOfDay(),
                ];
            case 'last_month':
                return [
                    'from' => $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                    'to' => $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay(),
                ];
            case 'this_year':
                return [
                    'from' => $now->copy()->startOfYear()->startOfDay(),
                    'to' => $now->copy()->endOfYear()->endOfDay(),
                ];
            default:
                return null;
        }
    }

    /**
     * Detect whether the resolved from/to range exactly matches one of the
     * named presets (computed against $now). Used only to highlight the
     * active chip; does not influence the actual query range.
     */
    protected function matchPresetFromRange(Carbon $from, Carbon $to, Carbon $now, string $tz): ?string
    {
        $candidates = ['this_week', 'last_30_days', 'this_month', 'last_month', 'this_year'];
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        foreach ($candidates as $candidate) {
            $range = $this->resolvePresetRange($candidate, $now, $tz);
            if (! $range) {
                continue;
            }

            if ($range['from']->toDateString() === $fromDate
                && $range['to']->toDateString() === $toDate) {
                return $candidate;
            }
        }

        return null;
    }

    protected function resolvePdfSections(Request $request): array
    {
        $raw = $request->input('pdf_sections');

        if (is_string($raw)) {
            $sections = array_filter(array_map('trim', explode(',', $raw)));
        } else {
            $sections = array_filter((array) $raw);
        }

        $allowed = array_keys(self::PDF_SECTION_OPTIONS);
        $filtered = array_values(array_intersect($allowed, $sections));

        return ! empty($filtered) ? $filtered : $allowed;
    }

    protected function collectSessions(array $filters, bool $applyAppointmentFilter = true): Collection
    {
        $query = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->leftJoin('users as teacher', 'teacher.id', '=', 'cs.teacher_id')
            ->leftJoin('users as assigned', 'assigned.id', '=', 'cs.assigned_teacher_id')
            ->leftJoin('users as cover', 'cover.id', '=', 'cs.cover_teacher_id')
            ->whereIn('ar.status', ['present', 'late', 'absent'])
            ->whereRaw('LOWER(COALESCE(cs.location, "")) = ?', ['uk'])
            ->select([
                'cs.id as session_id',
                'cs.session_date',
                'cs.start_time',
                'cs.end_time',
                'cs.calendar_name',
                'cs.calendar_norm',
                'cs.acuity_data',
                'cs.is_virtual',
                'cs.teacher_id',
                'cs.assigned_teacher_id',
                'cs.cover_teacher_id',
                'cs.canceled',
                DB::raw('COUNT(DISTINCT ar.student_id) as unique_students'),
                DB::raw("SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count"),
                DB::raw("SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) as late_count"),
                DB::raw("SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count"),
                DB::raw("SUM(CASE WHEN ar.status = 'absent' AND ar.sent_at IS NULL THEN 1 ELSE 0 END) as pending_absence_count"),
                DB::raw('MAX(ar.marked_at) as last_marked_at'),
                'teacher.name as teacher_name',
                'teacher.email as teacher_email',
                'assigned.name as assigned_teacher_name',
                'assigned.email as assigned_teacher_email',
                'cover.name as cover_teacher_name',
                'cover.email as cover_teacher_email',
            ])
            ->groupBy([
                'cs.id',
                'cs.session_date',
                'cs.start_time',
                'cs.end_time',
                'cs.calendar_name',
                'cs.calendar_norm',
                'cs.acuity_data',
                'cs.is_virtual',
                'cs.teacher_id',
                'cs.assigned_teacher_id',
                'cs.cover_teacher_id',
                'cs.canceled',
                'teacher.name',
                'teacher.email',
                'assigned.name',
                'assigned.email',
                'cover.name',
                'cover.email',
            ]);

        $query->whereBetween('cs.session_date', [$filters['from_bound'], $filters['to_bound']]);

        if ($filters['calendar_ids']) {
            $query->whereIn('cs.calendar_name', $filters['calendar_ids']);
        }

        if ($filters['teacher_id']) {
            $query->where('cs.teacher_id', $filters['teacher_id']);
        }

        if (! $filters['include_cancelled']) {
            $query->where(function ($inner) {
                $inner->whereNull('cs.canceled')
                    ->orWhere('cs.canceled', false)
                    ->orWhere('cs.canceled', 0);
            });
        }

        \App\Models\ClassSession::applyExcludeAssessmentsToQuery($query, 'cs');

        $query->orderByDesc('cs.session_date')->orderByDesc('cs.start_time');

        $results = collect($query->get());

        $rows = $results->map(function ($row) {
            $present = (int) $row->present_count;
            $late = (int) $row->late_count;
            $absent = (int) $row->absent_count;

            $attendanceRate = AttendanceMath::attendancePct($present, $late, $absent);
            if ($present + $late + $absent === 0) {
                $attendanceRate = null;
            }

            $category = $this->extractAcuityCategory($row->acuity_data) ?? $row->calendar_name;
            $rawAppointmentType = trim((string) ($this->extractAppointmentType($row->acuity_data) ?? ''));
            $normalizedAppointmentType = strtolower(trim((string) $rawAppointmentType));
            $deliveryKey = $this->appointmentTypeIsOnline($normalizedAppointmentType) ? 'online' : 'in_person';

            return [
                'session_id' => (int) $row->session_id,
                'session_date' => $row->session_date,
                'start_time' => $row->start_time,
                'end_time' => $row->end_time,
                'calendar_label' => $row->calendar_name ?? 'Unknown calendar',
                'calendar_norm' => $row->calendar_norm,
                'appointment_type' => $rawAppointmentType,
                'normalized_appointment_type' => $normalizedAppointmentType,
                'acuity_category' => $category ? $this->stripCategoryPrefix($category) : null,
                'is_virtual' => $deliveryKey === 'online',
                'delivery_label' => $deliveryKey === 'online' ? 'Online' : 'In Person',
                'teacher' => [
                    'id' => $row->teacher_id ? (int) $row->teacher_id : null,
                    'name' => $row->teacher_name ?? ($row->teacher_email ?? 'Unassigned'),
                ],
                'assigned_teacher' => [
                    'id' => $row->assigned_teacher_id ? (int) $row->assigned_teacher_id : null,
                    'name' => $row->assigned_teacher_name ?? ($row->assigned_teacher_email ?? null),
                ],
                'cover_teacher' => [
                    'id' => $row->cover_teacher_id ? (int) $row->cover_teacher_id : null,
                    'name' => $row->cover_teacher_name ?? ($row->cover_teacher_email ?? null),
                ],
                'present_count' => $present,
                'late_count' => $late,
                'absent_count' => $absent,
                'pending_absence_count' => (int) $row->pending_absence_count,
                'attendance_rate' => $attendanceRate,
                'unique_students' => (int) $row->unique_students,
                'last_marked_at' => $row->last_marked_at,
                'show_url' => route('filament.admin.pages.take-attendance', ['sessionId' => (int) $row->session_id]),
                'cover_teacher_id' => $row->cover_teacher_id ? (int) $row->cover_teacher_id : null,
            ];
        });

        if ($applyAppointmentFilter && $filters['appointment_type_ids']) {
            $matchers = array_map(fn ($value) => strtolower(trim((string) $value)), $filters['appointment_type_ids']);
            $rows = $rows->filter(function ($row) use ($matchers) {
                if (! $row['normalized_appointment_type']) {
                    return false;
                }

                return in_array($row['normalized_appointment_type'], $matchers, true);
            });
        }

        if ($filters['delivery_mode'] === 'in_person') {
            $rows = $rows->filter(fn ($row) => ($row['is_virtual'] ?? false) === false);
        } elseif (in_array($filters['delivery_mode'], ['online', 'virtual'], true)) {
            $rows = $rows->filter(fn ($row) => ($row['is_virtual'] ?? false) === true);
        }

        if ($filters['show_pending_only']) {
            $rows = $rows->filter(fn ($row) => ($row['pending_absence_count'] ?? 0) > 0);
        }

        if ($filters['show_low_attendance_only']) {
            $rows = $rows->filter(fn ($row) => isset($row['attendance_rate'])
                && $row['attendance_rate'] !== null
                && $row['attendance_rate'] < 75);
        }

        if (! empty($filters['acuity_category'])) {
            $needle = strtolower($filters['acuity_category']);
            $rows = $rows->filter(function ($row) use ($needle) {
                if (! $row['acuity_category']) {
                    return false;
                }

                return strtolower($row['acuity_category']) === $needle;
            });
        }

        return $rows->values();
    }

    protected function buildEmploymentKpis(Collection $sessions, array $breakdowns): array
    {
        $studentIds = $this->uniqueStudentIds($sessions);
        $totalStudents = $studentIds->count();

        if ($totalStudents === 0) {
            return $this->emptyEmploymentKpis();
        }

        $studentStats = $this->studentSessionStats($sessions);
        $employmentRows = DB::table('employment_outcomes')
            ->select('student_id', 'job_status', 'job_start_date', 'job_end_date', 'hours_per_week', 'updated_at')
            ->whereIn('student_id', $studentIds)
            ->orderByDesc('updated_at')
            ->get();

        $employment = [];
        foreach ($employmentRows as $row) {
            if (! isset($employment[$row->student_id])) {
                $employment[$row->student_id] = $row;
            }
        }

        $employedStatuses = ['employed', 'sustained'];
        $employedStudents = 0;
        $timeDiffs = [];
        $sustainedCount = 0;

        foreach ($employment as $studentId => $row) {
            if (in_array($row->job_status, $employedStatuses, true)) {
                $employedStudents++;
            }

            $firstSession = $studentStats[$studentId]['first_session_date'] ?? null;
            if ($firstSession && $row->job_start_date) {
                $jobStart = Carbon::parse($row->job_start_date);
                $timeDiffs[] = $jobStart->diffInDays($firstSession);
            }

            if ($this->isSustainedOutcome($row)) {
                $sustainedCount++;
            }
        }

        $avgTimeToJobDays = ! empty($timeDiffs) ? round(array_sum($timeDiffs) / count($timeDiffs), 1) : null;
        $avgTimeToJobWeeks = $avgTimeToJobDays !== null ? round($avgTimeToJobDays / 7, 1) : null;

        $funnel = $this->buildRetentionFunnel($studentStats);
        $attendanceCorrelation = $this->buildAttendanceOutcomeCorrelation($studentStats, $employment);
        $teacherImpact = $this->buildTeacherImpactMetrics($sessions, $breakdowns['byTeacher'] ?? [], $employment);

        return [
            'total_students' => $totalStudents,
            'employed_students' => $employedStudents,
            'job_placement_rate' => $totalStudents > 0 ? round(($employedStudents / $totalStudents) * 100, 1) : 0,
            'avg_time_to_job_days' => $avgTimeToJobDays,
            'avg_time_to_job_weeks' => $avgTimeToJobWeeks,
            'sustained_rate' => $totalStudents > 0 ? round(($sustainedCount / $totalStudents) * 100, 1) : 0,
            'retention_funnel' => $funnel,
            'attendance_outcomes' => $attendanceCorrelation,
            'teacher_impact' => $teacherImpact,
        ];
    }

    protected function emptyEmploymentKpis(): array
    {
        return [
            'total_students' => 0,
            'employed_students' => 0,
            'job_placement_rate' => 0,
            'avg_time_to_job_days' => null,
            'avg_time_to_job_weeks' => null,
            'sustained_rate' => 0,
            'retention_funnel' => [
                'started' => 0,
                'week2' => 0,
                'week4' => 0,
                'completed' => 0,
            ],
            'attendance_outcomes' => [
                'high' => ['students' => 0, 'placements' => 0, 'placement_rate' => 0],
                'low' => ['students' => 0, 'placements' => 0, 'placement_rate' => 0],
            ],
            'teacher_impact' => [],
        ];
    }

    protected function uniqueStudentIds(Collection $sessions): Collection
    {
        $sessionIds = $sessions->pluck('session_id')->filter()->unique()->values();

        if ($sessionIds->isEmpty()) {
            return collect();
        }

        return DB::table('attendance_records')
            ->whereIn('class_session_id', $sessionIds)
            ->whereNotNull('student_id')
            ->distinct()
            ->pluck('student_id');
    }

    protected function studentSessionStats(Collection $sessions): array
    {
        $sessionIds = $sessions->pluck('session_id')->filter()->unique()->values();

        if ($sessionIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->whereIn('ar.class_session_id', $sessionIds)
            ->whereNotNull('ar.student_id')
            ->select('ar.student_id', 'cs.session_date', 'ar.status')
            ->get()
            ->groupBy('student_id');

        $stats = [];

        foreach ($rows as $studentId => $records) {
            $dates = $records->pluck('session_date')->map(fn ($date) => Carbon::parse($date))->sort();
            if ($dates->isEmpty()) {
                continue;
            }

            $first = $dates->first();
            $week2Cutoff = $first->copy()->addDays(7);
            $week4Cutoff = $first->copy()->addDays(28);
            $completedCutoff = $first->copy()->addDays(56);

            $week2 = $dates->first(fn ($date) => $date->gte($week2Cutoff)) !== null;
            $week4 = $dates->first(fn ($date) => $date->gte($week4Cutoff)) !== null;
            $completed = $dates->first(fn ($date) => $date->gte($completedCutoff)) !== null;

            $attended = $records->whereIn('status', ['present', 'late'])->count();
            $totalSessions = $records->count();
            $attendanceRate = $totalSessions > 0 ? round(($attended / $totalSessions) * 100, 1) : 0;

            $stats[$studentId] = [
                'first_session_date' => $first,
                'attendance_rate' => $attendanceRate,
                'week2' => $week2,
                'week4' => $week4,
                'completed' => $completed,
            ];
        }

        return $stats;
    }

    protected function buildRetentionFunnel(array $stats): array
    {
        $started = count($stats);
        $week2 = count(array_filter($stats, fn ($stat) => $stat['week2'] ?? false));
        $week4 = count(array_filter($stats, fn ($stat) => $stat['week4'] ?? false));
        $completed = count(array_filter($stats, fn ($stat) => $stat['completed'] ?? false));

        return compact('started', 'week2', 'week4', 'completed');
    }

    protected function buildAttendanceOutcomeCorrelation(array $stats, array $employment): array
    {
        $employedStatuses = ['employed', 'sustained'];

        $buildGroup = function (callable $filter) use ($stats, $employment, $employedStatuses) {
            $studentIds = array_keys(array_filter($stats, $filter));
            $placements = 0;

            foreach ($studentIds as $studentId) {
                if (isset($employment[$studentId]) && in_array($employment[$studentId]->job_status, $employedStatuses, true)) {
                    $placements++;
                }
            }

            $count = count($studentIds);

            return [
                'students' => $count,
                'placements' => $placements,
                'placement_rate' => $count > 0 ? round(($placements / $count) * 100, 1) : 0,
            ];
        };

        return [
            'high' => $buildGroup(fn ($stat) => ($stat['attendance_rate'] ?? 0) >= 85),
            'low' => $buildGroup(fn ($stat) => ($stat['attendance_rate'] ?? 0) > 0 && $stat['attendance_rate'] < 60),
        ];
    }

    protected function buildTeacherImpactMetrics(Collection $sessions, array $teacherBreakdown, array $employment): array
    {
        $teacherNames = collect($teacherBreakdown)
            ->mapWithKeys(fn ($row) => [
                (string) ($row['teacher_id'] ?? 'unassigned') => $row['teacher_name'] ?? 'Unassigned',
            ]);

        $teacherAttendanceAverages = collect($sessions)
            ->groupBy(fn ($row) => (string) ($row['teacher']['id'] ?? 'unassigned'))
            ->map(function (Collection $group) {
                $values = $group->pluck('attendance_rate')->filter(fn ($value) => $value !== null);
                return $values->isNotEmpty() ? round($values->avg(), 1) : null;
            });

        $teacherStudents = $this->teacherStudentMap($sessions);
        $employedStatuses = ['employed', 'sustained'];
        $impact = [];

        foreach ($teacherStudents as $teacherId => $studentList) {
            $total = count($studentList);
            if ($total === 0) {
                continue;
            }

            $placements = 0;
            foreach ($studentList as $studentId) {
                if (isset($employment[$studentId]) && in_array($employment[$studentId]->job_status, $employedStatuses, true)) {
                    $placements++;
                }
            }

            $impact[] = [
                'teacher_id' => $teacherId === 'unassigned' ? null : (int) $teacherId,
                'teacher_name' => $teacherNames[$teacherId] ?? 'Unassigned',
                'student_count' => $total,
                'placement_rate' => $total > 0 ? round(($placements / $total) * 100, 1) : 0,
                'avg_attendance' => $teacherAttendanceAverages[$teacherId] ?? null,
            ];
        }

        return collect($impact)
            ->sortByDesc('placement_rate')
            ->values()
            ->all();
    }

    protected function teacherStudentMap(Collection $sessions): array
    {
        $sessionIds = $sessions->pluck('session_id')->filter()->unique()->values();
        if ($sessionIds->isEmpty()) {
            return [];
        }

        return DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->whereIn('ar.class_session_id', $sessionIds)
            ->whereNotNull('ar.student_id')
            ->select('cs.teacher_id', 'ar.student_id')
            ->distinct()
            ->get()
            ->groupBy(fn ($row) => (string) ($row->teacher_id ?? 'unassigned'))
            ->map(fn ($group) => $group->pluck('student_id')->unique()->values()->all())
            ->toArray();
    }

    protected function isSustainedOutcome($row): bool
    {
        if (! $row) {
            return false;
        }

        if ($row->job_status === 'sustained') {
            return true;
        }

        if (empty($row->job_start_date)) {
            return false;
        }

        $hours = (int) ($row->hours_per_week ?? 0);
        if ($hours < 16) {
            return false;
        }

        $start = Carbon::parse($row->job_start_date);
        $end = $row->job_end_date ? Carbon::parse($row->job_end_date) : Carbon::now();

        return $start->diffInWeeks($end) >= 26;
    }

    protected function buildKpis(Collection $sessions): array
    {
        $totalSessions = $sessions->count();

        $present = $sessions->sum('present_count');
        $late = $sessions->sum('late_count');
        $absent = $sessions->sum('absent_count');

        $averageAttendance = AttendanceMath::attendancePct($present, $late, $absent);
        if ($present + $late + $absent === 0) {
            $averageAttendance = null;
        }

        return [
            'total_sessions' => $totalSessions,
            'average_attendance' => $averageAttendance,
        ];
    }

    protected function buildBreakdowns(Collection $sessions): array
    {
        $byCalendar = $sessions
            ->groupBy('calendar_label')
            ->map(function (Collection $group, $label) {
                $present = $group->sum('present_count');
                $late = $group->sum('late_count');
                $absent = $group->sum('absent_count');

                $attendance = AttendanceMath::attendancePct($present, $late, $absent);
                if ($present + $late + $absent === 0) {
                    $attendance = null;
                }

                $uniqueStudents = $this->countUniqueStudents($group, ['present', 'late']);

                return [
                    'calendar_name' => $label,
                    'sessions' => $group->count(),
                    'students' => $uniqueStudents,
                    'attendance_pct' => $attendance,
                ];
            })
            ->sortByDesc(fn ($row) => $row['sessions'])
            ->values()
            ->all();

        $byAppointmentType = $sessions
            ->groupBy(function ($row) {
                return $row['appointment_type'] ?: 'Unknown';
            })
            ->map(function (Collection $group, $label) {
                $present = $group->sum('present_count');
                $late = $group->sum('late_count');
                $absent = $group->sum('absent_count');

                $attendance = AttendanceMath::attendancePct($present, $late, $absent);
                if ($present + $late + $absent === 0) {
                    $attendance = null;
                }

                return [
                    'name' => $label,
                    'attendance_pct' => $attendance,
                    'sessions' => $group->count(),
                ];
            })
            ->sortByDesc(fn ($row) => $row['attendance_pct'] ?? 0)
            ->values()
            ->all();

        $byDeliveryMode = $sessions
            ->groupBy('delivery_label')
            ->map(function (Collection $group, $label) {
                return [
                    'mode_label' => $label,
                    'sessions' => $group->count(),
                ];
            })
            ->values()
            ->all();

        $teacherStats = [];

        $ensure = function ($id, $name) use (&$teacherStats) {
            $key = $id ?? 'unassigned';

            if (! isset($teacherStats[$key])) {
                $teacherStats[$key] = [
                    'teacher_id' => $id ? (int) $id : null,
                    'teacher_name' => $name,
                    'sessions' => 0,
                    'normal' => 0,
                    'covering' => 0,
                    'sick' => 0,
                ];
            }

            return $key;
        };

        foreach ($sessions as $row) {
            $actualId = $row['teacher']['id'] ?? null;
            $actualName = $row['teacher']['name'] ?? 'Unassigned';
            $assignedId = $row['assigned_teacher']['id'] ?? $actualId;
            $assignedName = $row['assigned_teacher']['name'] ?? $actualName;
            $coverId = $row['cover_teacher_id'] ?? null;

            $actualKey = $ensure($actualId, $actualName);

            if ($coverId && $actualId && $coverId === $actualId) {
                $teacherStats[$actualKey]['covering']++;
            } else {
                $teacherStats[$actualKey]['normal']++;
            }

            $teacherStats[$actualKey]['sessions'] = $teacherStats[$actualKey]['normal'] + $teacherStats[$actualKey]['covering'];

            if ($assignedId && $coverId && $coverId !== $assignedId) {
                $assignedKey = $ensure($assignedId, $assignedName);
                $teacherStats[$assignedKey]['sick']++;
            }
        }

        $byTeacher = collect($teacherStats)
            ->sortByDesc('sessions')
            ->values()
            ->all();

        $coverByTeacher = $sessions
            ->filter(fn ($row) => ! empty($row['cover_teacher_id']))
            ->groupBy(fn ($row) => $row['cover_teacher']['name'] ?? 'Covering teacher')
            ->map(function (Collection $group, $label) {
                $assignedSummary = $group
                    ->groupBy(fn ($row) => $row['assigned_teacher']['name'] ?? 'Unassigned')
                    ->map->count()
                    ->sortDesc()
                    ->map(function ($count, $name) {
                        return $name.' ('.$count.')';
                    })
                    ->values()
                    ->all();

                return [
                    'teacher_name' => $label,
                    'sessions' => $group->count(),
                    'assigned_teachers' => $assignedSummary,
                ];
            })
            ->sortByDesc(fn ($row) => $row['sessions'])
            ->values()
            ->all();

        return compact('byCalendar', 'byAppointmentType', 'byDeliveryMode', 'byTeacher', 'coverByTeacher');
    }

    protected function buildTimeSeries(Collection $sessions, string $from, string $to): array
    {
        $grouped = $sessions->groupBy('session_date');

        $attendanceSeries = [];
        $sessionSeries = [];

        $period = CarbonPeriod::create($from, $to);
        foreach ($period as $date) {
            $key = $date->format('Y-m-d');
            $dayGroup = $grouped->get($key, collect());

            $present = $dayGroup->sum('present_count');
            $late = $dayGroup->sum('late_count');
            $absent = $dayGroup->sum('absent_count');

            $attendance = null;
            if ($present + $late + $absent > 0) {
                $attendance = AttendanceMath::attendancePct($present, $late, $absent);
            }

            $attendanceSeries[] = [
                'date' => $key,
                'attendance_pct' => is_null($attendance) ? null : round($attendance, 1),
            ];

            $sessionSeries[] = [
                'date' => $key,
                'sessions' => $dayGroup->count(),
            ];
        }

        return [$attendanceSeries, $sessionSeries];
    }

    protected function buildAttentionSets(Collection $sessions): array
    {
        $lowest = $sessions
            ->filter(fn ($row) => $row['attendance_rate'] !== null)
            ->sortBy('attendance_rate')
            ->first();

        $pending = $sessions
            ->filter(fn ($row) => $row['pending_absence_count'] > 0)
            ->sortByDesc('pending_absence_count')
            ->take(5)
            ->values()
            ->all();

        return [
            'lowest' => $lowest,
            'pending' => $pending,
        ];
    }

    protected function paginateSessions(Collection $sessions, int $perPage, int $page): LengthAwarePaginator
    {
        $sorted = $sessions
            ->sortByDesc(fn ($row) => sprintf('%s %s', $row['session_date'], $row['start_time']))
            ->values();

        $total = $sorted->count();
        $items = $sorted->forPage($page, $perPage)->values();

        return new LengthAwarePaginatorImpl(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    protected function countUniqueStudents(Collection $sessions, ?array $statuses = null): int
    {
        $sessionIds = $sessions->pluck('session_id')->filter()->unique()->values();

        if ($sessionIds->isEmpty()) {
            return 0;
        }

        $query = DB::table('attendance_records')
            ->whereIn('class_session_id', $sessionIds)
            ->whereNotNull('student_id')
            ->when(! empty($statuses), fn ($q) => $q->whereIn('status', $statuses));

        return (int) $query
            ->distinct('student_id')
            ->count('student_id');
    }

    protected function buildNewStudentsCount(Collection $sessions, string $fromDate): int
    {
        $sessionIds = $sessions->pluck('session_id')->filter()->unique()->values();

        if ($sessionIds->isEmpty()) {
            return 0;
        }

        $inWindowStudentIds = DB::table('attendance_records')
            ->whereIn('class_session_id', $sessionIds)
            ->whereNotNull('student_id')
            ->distinct()
            ->pluck('student_id');

        if ($inWindowStudentIds->isEmpty()) {
            return 0;
        }

        $subQuery = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->whereRaw('LOWER(COALESCE(cs.location, "")) = ?', ['uk'])
            ->whereIn('ar.student_id', $inWindowStudentIds)
            ->whereNotNull('ar.student_id')
            ->groupBy('ar.student_id')
            ->havingRaw('MIN(cs.session_date) >= ?', [$fromDate])
            ->select('ar.student_id');

        \App\Models\ClassSession::applyExcludeAssessmentsToQuery($subQuery, 'cs');

        return (int) DB::query()->fromSub($subQuery, 'sub')->count();
    }

    protected function extractAppointmentType($payload): ?string
    {
        if (! $payload) {
            return null;
        }

        if (is_array($payload)) {
            $data = $payload;
        } else {
            $data = json_decode($payload, true);
        }

        if (! is_array($data)) {
            return null;
        }

        $candidates = ['type', 'appointmentType', 'appointment_type', 'appointment_type_name', 'name'];
        foreach ($candidates as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                return trim($data[$key]);
            }
        }

        if (! empty($data['labels']) && is_array($data['labels'])) {
            $first = $data['labels'][0] ?? null;
            if (is_string($first)) {
                return trim($first);
            }
        }

        return null;
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

    protected function extractAcuityCategory($payload): ?string
    {
        if (! $payload) {
            return null;
        }

        $data = is_array($payload) ? $payload : json_decode($payload, true);
        if (! is_array($data)) {
            return null;
        }

        $candidates = [];

        foreach (['category', 'appointmentCategory', 'category_name', 'calendarName', 'calendar', 'location'] as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                $candidates[] = $data[$key];
            }
        }

        if (! empty($data['labels']) && is_array($data['labels'])) {
            foreach ($data['labels'] as $label) {
                if (is_string($label)) {
                    $candidates[] = $label;
                }
            }
        }

        foreach ($candidates as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $region = LocationMappingService::regionForCategory($value);
            if ($region === 'UK') {
                return $this->stripCategoryPrefix($value);
            }
        }

        $keywords = LocationMappingService::keywordsForRegion('UK');
        foreach ($candidates as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $normalized = LocationMappingService::normalizeCategory($value);
            if ($normalized === null) {
                continue;
            }

            foreach ($keywords as $keyword) {
                if ($normalized === $keyword || str_contains($normalized, $keyword)) {
                    return $this->stripCategoryPrefix($value);
                }
            }
        }

        return null;
    }

    protected function stripCategoryPrefix(string $value): string
    {
        return trim(preg_replace('/^\s*\d+[\.)-]?\s*/', '', $value));
    }

    protected function sanitizeStringArray($value): array
    {
        $items = Arr::wrap($value);

        return array_values(array_filter(array_map(function ($item) {
            if (is_string($item)) {
                return trim($item);
            }

            if (is_numeric($item)) {
                return (string) $item;
            }

            return null;
        }, $items), fn ($item) => $item !== null && $item !== ''));
    }

    protected function sanitizeString($value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }

        return null;
    }
}
