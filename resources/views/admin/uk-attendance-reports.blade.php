@php
    $adminPanel = \Filament\Facades\Filament::getPanel('admin');
    $selectedCalendars = $filters['calendar_ids'] ?? [];
    $selectedAppointmentTypes = $filters['appointment_type_ids'] ?? [];
    $selectedTeacher = $filters['teacher_id'] ?? null;
    $deliveryMode = $filters['delivery_mode'] ?? 'all';
    $selectedCategory = $filters['acuity_category'] ?? null;
    $perPage = $filters['per_page'] ?? 15;
    $includeCancelled = $filters['include_cancelled'] ?? false;

    $fromDateObj = \Illuminate\Support\Carbon::parse($filters['from_date']);
    $toDateObj = \Illuminate\Support\Carbon::parse($filters['to_date']);
    $dateRangeLabel = $fromDateObj->format('d M Y').' - '.$toDateObj->format('d M Y');
    $rangeDays = $fromDateObj->diffInDays($toDateObj) + 1;

    $datePresets = [
        'this_week' => 'This week',
        'last_30_days' => 'Last 30 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
    ];
    $activePreset = $filters['preset'] ?? null;
    $activePresetLabel = $activePreset && isset($datePresets[$activePreset])
        ? $datePresets[$activePreset]
        : null;

    if ($activePresetLabel) {
        $filterSummaryTitle = $activePresetLabel.' selected';
        $filterSummarySubtext = 'Showing '.strtolower($activePresetLabel).' ('.$dateRangeLabel.').';
    } else {
        $filterSummaryTitle = 'custom range — '.$dateRangeLabel;
        $filterSummarySubtext = 'Showing custom range: '.$dateRangeLabel.'.';
    }

    $currentUser = auth()->user();
    $isUkManager = $currentUser?->isUkManager();
    $showTopPriorities = (bool) ($currentUser?->hasRole('admin', 'super_admin'));
    $canViewEmployment = $showTopPriorities;
    $activeTab = $activeTab ?? 'attendance';

    $kpiCards = [
        [
            'label' => 'Total sessions',
            'value' => number_format($kpis['total_sessions'] ?? 0),
            'icon' => 'heroicon-o-calendar-days',
            'tone' => 'from-slate-50 to-white dark:from-slate-900/40 dark:to-slate-900/10',
        ],
        [
            'label' => 'Average attendance',
            'value' => ! is_null($kpis['average_attendance']) ? number_format($kpis['average_attendance'], 1) . '%' : '—',
            'icon' => 'heroicon-o-chart-bar-square',
            'tone' => 'from-emerald-50 to-white dark:from-emerald-900/30 dark:to-emerald-900/5',
        ],
        [
            'label' => 'Unique students',
            'value' => number_format($kpis['unique_students'] ?? 0),
            'icon' => 'heroicon-o-users',
            'tone' => 'from-sky-50 to-white dark:from-sky-900/30 dark:to-sky-900/5',
        ],
        [
            'label' => 'New students',
            'value' => number_format($kpis['new_students'] ?? 0),
            'icon' => 'heroicon-o-user-plus',
            'tone' => 'from-amber-50 to-white dark:from-amber-900/30 dark:to-amber-900/5',
        ],
    ];

    $skillStats = $skillStats ?? [
        'overall' => null,
        'speaking_listening' => null,
        'reading_writing' => null,
        'to_learn' => null,
        'work_readiness' => null,
        'it_skills' => null,
        'student_count' => 0,
    ];

    $skillCardFormat = fn ($value) => is_null($value) ? '—' : number_format($value, 1);

    $skillCards = [
        [
            'label' => 'Overall skill avg',
            'value' => $skillCardFormat($skillStats['overall'] ?? null),
            'is_empty' => is_null($skillStats['overall'] ?? null),
            'icon' => 'heroicon-o-academic-cap',
            'tone' => 'from-indigo-50 to-white dark:from-indigo-900/30 dark:to-indigo-900/5',
        ],
        [
            'label' => 'Speaking & Listening',
            'value' => $skillCardFormat($skillStats['speaking_listening'] ?? null),
            'is_empty' => is_null($skillStats['speaking_listening'] ?? null),
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'tone' => 'from-rose-50 to-white dark:from-rose-900/30 dark:to-rose-900/5',
        ],
        [
            'label' => 'Reading & Writing',
            'value' => $skillCardFormat($skillStats['reading_writing'] ?? null),
            'is_empty' => is_null($skillStats['reading_writing'] ?? null),
            'icon' => 'heroicon-o-book-open',
            'tone' => 'from-violet-50 to-white dark:from-violet-900/30 dark:to-violet-900/5',
        ],
        [
            'label' => 'Motivation to Learn',
            'value' => $skillCardFormat($skillStats['to_learn'] ?? null),
            'is_empty' => is_null($skillStats['to_learn'] ?? null),
            'icon' => 'heroicon-o-light-bulb',
            'tone' => 'from-yellow-50 to-white dark:from-yellow-900/30 dark:to-yellow-900/5',
        ],
        [
            'label' => 'Work Readiness',
            'value' => $skillCardFormat($skillStats['work_readiness'] ?? null),
            'is_empty' => is_null($skillStats['work_readiness'] ?? null),
            'icon' => 'heroicon-o-briefcase',
            'tone' => 'from-emerald-50 to-white dark:from-emerald-900/30 dark:to-emerald-900/5',
        ],
        [
            'label' => 'IT Skills',
            'value' => $skillCardFormat($skillStats['it_skills'] ?? null),
            'is_empty' => is_null($skillStats['it_skills'] ?? null),
            'icon' => 'heroicon-o-computer-desktop',
            'tone' => 'from-cyan-50 to-white dark:from-cyan-900/30 dark:to-cyan-900/5',
        ],
    ];

    $employmentKpiCards = [
        [
            'label' => 'Job placement rate',
            'value' => number_format($employmentKpis['job_placement_rate'] ?? 0, 1) . '%',
            'icon' => 'heroicon-o-briefcase',
            'tone' => 'from-emerald-50 to-white dark:from-emerald-900/30 dark:to-emerald-900/5',
        ],
        [
            'label' => 'Avg time to job',
            'value' => ($employmentKpis['avg_time_to_job_days'] ?? null) !== null
                ? number_format($employmentKpis['avg_time_to_job_days'], 1) . ' days'
                : '—',
            'icon' => 'heroicon-o-clock',
            'tone' => 'from-sky-50 to-white dark:from-sky-900/30 dark:to-sky-900/5',
        ],
        [
            'label' => 'Sustained employment',
            'value' => number_format($employmentKpis['sustained_rate'] ?? 0, 1) . '%',
            'icon' => 'heroicon-o-chart-bar-square',
            'tone' => 'from-amber-50 to-white dark:from-amber-900/30 dark:to-amber-900/5',
        ],
        [
            'label' => 'Students scoped',
            'value' => number_format($employmentKpis['total_students'] ?? 0),
            'icon' => 'heroicon-o-user-group',
            'tone' => 'from-slate-50 to-white dark:from-slate-900/40 dark:to-slate-900/10',
        ],
    ];

    $totalDeliverySessions = collect($breakdowns['byDeliveryMode'] ?? [])->sum('sessions');
    $teacherBreakdown = collect($breakdowns['byTeacher'] ?? []);
    $maxTeacherSessions = $teacherBreakdown->max('sessions') ?: 0;
    $totalTeacherSessions = $teacherBreakdown->sum('sessions') ?: 0;
    $attendanceSeriesData = collect($seriesAttendanceOverTime ?? []);
    $attendancePoints = $attendanceSeriesData->filter(fn ($point) => ! is_null($point['attendance_pct'] ?? null))->values();
    $hasAttendanceSeries = $attendancePoints->isNotEmpty();

    $attendanceChartWidth = 360;
    $attendanceChartHeight = 220;
    $attendanceChartMarginLeft = 44;
    $attendanceChartMarginTop = 16;
    $attendanceChartMarginRight = 12;
    $attendanceChartMarginBottom = 28;
    $attendanceInnerWidth = $attendanceChartWidth - $attendanceChartMarginLeft - $attendanceChartMarginRight;
    $attendanceInnerHeight = $attendanceChartHeight - $attendanceChartMarginTop - $attendanceChartMarginBottom;

    $attendanceLinePath = null;
    $attendanceAreaPath = null;
    $attendancePointsCoordinates = collect();
    $attendanceXAxisLabels = collect();
    $attendanceYAxisTicks = collect([0, 25, 50, 75, 100])->map(function ($tick) use ($attendanceChartMarginTop, $attendanceInnerHeight) {
        $y = $attendanceChartMarginTop + $attendanceInnerHeight - ($tick / 100) * $attendanceInnerHeight;
        return ['value' => $tick, 'y' => $y];
    });

    $pdfSectionOptionsList = [];
    foreach (($pdfSectionOptions ?? []) as $key => $label) {
        $pdfSectionOptionsList[] = ['key' => $key, 'label' => $label];
    }
    $pdfDefaultSections = $pdfDefaultSections ?? array_map(fn ($section) => $section['key'], $pdfSectionOptionsList);

    if ($hasAttendanceSeries) {
        $count = $attendancePoints->count();
        $step = $count > 1 ? $attendanceInnerWidth / ($count - 1) : 0;
        $lineSegments = [];
        $areaSegments = [];

        foreach ($attendancePoints as $index => $point) {
            $value = (float) ($point['attendance_pct'] ?? 0);
            $clamped = max(0, min(100, $value));
            if ($count > 1) {
                $x = $attendanceChartMarginLeft + $index * $step;
            } else {
                $x = $attendanceChartMarginLeft + $attendanceInnerWidth / 2;
            }
            $y = $attendanceChartMarginTop + $attendanceInnerHeight - ($clamped / 100) * $attendanceInnerHeight;
            $lineSegments[] = sprintf('%s %.2f %.2f', $index === 0 ? 'M' : 'L', $x, $y);
            $attendancePointsCoordinates = $attendancePointsCoordinates->push(['x' => $x, 'y' => $y, 'date' => $point['date'], 'value' => $clamped]);
        }

        $attendanceLinePath = implode(' ', $lineSegments);
        if ($attendancePointsCoordinates->isNotEmpty()) {
            $axisBottom = $attendanceChartMarginTop + $attendanceInnerHeight;
            $first = $attendancePointsCoordinates->first();
            $last = $attendancePointsCoordinates->last();
            $areaSegments = $lineSegments;
            $areaSegments[] = sprintf('L %.2f %.2f', $last['x'], $axisBottom);
            $areaSegments[] = sprintf('L %.2f %.2f', $first['x'], $axisBottom);
            $areaSegments[] = 'Z';
            $attendanceAreaPath = implode(' ', $areaSegments);
        }

        $labelIndices = collect([0, (int) floor(($count - 1) / 2), $count - 1])->unique()->values();
        $attendanceXAxisLabels = $labelIndices->map(function ($index) use ($attendancePointsCoordinates) {
            $point = $attendancePointsCoordinates[$index];
            $label = \Illuminate\Support\Carbon::parse($point['date'])->format('d M');
            return ['x' => $point['x'], 'text' => $label, 'date' => $point['date']];
        });
    }
    $sessionsSeriesData = collect($seriesSessionsOverTime ?? []);
    $sessionsPoints = $sessionsSeriesData->filter(fn ($point) => ! is_null($point['sessions'] ?? null))->values();
    $hasSessionsSeries = $sessionsPoints->isNotEmpty();

    $sessionsChartWidth = 360;
    $sessionsChartHeight = 220;
    $sessionsChartMarginLeft = 44;
    $sessionsChartMarginTop = 16;
    $sessionsChartMarginRight = 12;
    $sessionsChartMarginBottom = 28;
    $sessionsInnerWidth = $sessionsChartWidth - $sessionsChartMarginLeft - $sessionsChartMarginRight;
    $sessionsInnerHeight = $sessionsChartHeight - $sessionsChartMarginTop - $sessionsChartMarginBottom;

    $sessionsBars = collect();
    $sessionsXAxisLabels = collect();
    $sessionsYAxisTicks = collect();

    if ($hasSessionsSeries) {
        $count = $sessionsPoints->count();
        $maxSessions = max($sessionsPoints->max(fn ($point) => (int) ($point['sessions'] ?? 0)), 1);
        $tickStep = max(1, (int) ceil($maxSessions / 4));
        $tickValues = collect(range(0, $maxSessions, $tickStep));
        if ($tickValues->last() != $maxSessions) {
            $tickValues = $tickValues->push($maxSessions);
        }
        $sessionsYAxisTicks = $tickValues->map(function ($tick) use ($sessionsChartMarginTop, $sessionsInnerHeight, $maxSessions) {
            $y = $sessionsChartMarginTop + $sessionsInnerHeight - ($maxSessions > 0 ? ($tick / $maxSessions) * $sessionsInnerHeight : 0);
            return ['value' => $tick, 'y' => $y];
        });

        $barSpacing = $count > 0 ? $sessionsInnerWidth / $count : $sessionsInnerWidth;
        $barWidth = min(32, $barSpacing * 0.6);

        foreach ($sessionsPoints as $index => $point) {
            $value = (int) ($point['sessions'] ?? 0);
            $height = $maxSessions > 0 ? ($value / $maxSessions) * $sessionsInnerHeight : 0;
            $xCenter = $count > 1
                ? $sessionsChartMarginLeft + ($index * $barSpacing) + ($barSpacing / 2)
                : $sessionsChartMarginLeft + $sessionsInnerWidth / 2;
            $x = $xCenter - ($barWidth / 2);
            $y = $sessionsChartMarginTop + $sessionsInnerHeight - $height;

            $sessionsBars->push([
                'x' => $x,
                'y' => $y,
                'width' => $barWidth,
                'height' => $height,
                'value' => $value,
                'date' => $point['date'],
                'label' => \Illuminate\Support\Carbon::parse($point['date'])->format('d M'),
                'xCenter' => $xCenter,
            ]);
        }

        $labelIndices = collect([0, (int) floor(($count - 1) / 2), $count - 1])->unique()->values();
        $sessionsXAxisLabels = $labelIndices->map(function ($index) use ($sessionsBars) {
            $bar = $sessionsBars->get($index);
            return ['x' => $bar['xCenter'], 'text' => $bar['label']];
        });
    } else {
        $sessionsYAxisTicks = collect([[
            'value' => 0,
            'y' => $sessionsChartMarginTop + $sessionsInnerHeight,
        ]]);
    }

@endphp



<x-filament-panels::layout :panel="$adminPanel" :livewire="null" class="uk-reports-page">
    <style>
        /* UK Reports — nuclear horizontal overflow suppression.
           Pre-existing bug (carryover #34) bypassed via brute force.
           Page-scoped via inline blade <style>; effective only while
           this blade renders. Root cause to be diagnosed in a
           dedicated session with browser dev tools. */
        html { overflow-x: hidden !important; }
        body { overflow-x: hidden !important; max-width: 100vw !important; }
    </style>
    <main class="uk-reports fi-page mx-auto w-full max-w-screen-2xl overflow-x-hidden px-4 py-8 text-sm md:px-6 lg:px-8">
        <section class="flex flex-col gap-y-8">
    <x-filament::card class="relative overflow-hidden border border-gray-200 bg-gradient-to-r from-gray-50 via-white to-gray-100 dark:border-gray-800 dark:from-gray-900/60 dark:via-gray-900/40 dark:to-gray-900/20">
        <div class="absolute inset-y-0 right-[-30%] hidden w-[55%] rounded-full bg-primary-200/30 blur-3xl sm:block dark:bg-primary-500/10"></div>
        <div class="relative flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div class="flex-1">
                <p class="text-sm font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-300">Insight dashboard</p>
                <h1 class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">UK Reports</h1>
                <p class="text-sm text-gray-600 dark:text-gray-300">Analyse attendance performance across UK calendars.</p>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white/70 px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-900/40">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="h-7 w-7 text-emerald-600 dark:text-emerald-400">
                    <path d="M17 20h5v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M9 20H4v-2a4 4 0 0 1 3-3.87"/>
                    <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    <path d="M8 3.13a4 4 0 0 0 0 7.75"/>
                </svg>
                <div class="flex flex-col leading-tight">
                    <span class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ number_format($activeUkStudents) }}</span>
                    <span class="text-xs font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">Active Students</span>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ now()->format('D j M Y') }}</span>
                </div>
            </div>
        </div>
    </x-filament::card>

    <form
        method="GET"
        action="{{ route('filament.admin.pages.uk-reports') }}"
        class="space-y-4"
        x-data="ukReportFilterForm({
            sectionOptions: @js($pdfSectionOptionsList),
            defaultSections: @js($pdfDefaultSections),
            initialTab: @js($activeTab),
        })"
        x-ref="filtersForm"
        x-on:keydown.escape.window="pdfModalOpen = false"
    >
        <input type="hidden" name="pdf_sections" x-ref="pdfSectionsField">
        <input type="hidden" name="tab" value="{{ $activeTab }}" x-ref="tabField">
        <input type="hidden" name="preset" value="">
        <button type="submit" formaction="{{ route('admin.uk-reports.export-pdf') }}" formmethod="GET" class="hidden" x-ref="pdfSubmit"></button>
        <details class="group rounded-3xl border border-gray-200 bg-white shadow-sm transition-all dark:border-gray-800 dark:bg-gray-900">
            <summary class="flex cursor-pointer select-none items-center justify-between gap-4 px-6 py-4 text-left">
                <div>
                    <p class="text-base font-semibold text-gray-900 dark:text-white">
                        <span class="text-primary-600 dark:text-primary-300">Reports Filter</span>
                        <span class="text-gray-900 dark:text-white"> — {{ $filterSummaryTitle }}</span>
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $filterSummarySubtext }}</p>
                </div>
                <x-filament::icon icon="heroicon-o-chevron-down" class="h-5 w-5 text-gray-500 transition-transform duration-200 group-open:rotate-180 dark:text-gray-300" />
            </summary>

            <div class="border-t border-gray-100 px-6 pb-6 pt-4 dark:border-gray-800">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Quick range:</span>
                    @foreach ($datePresets as $presetKey => $presetLabel)
                        <a href="{{ request()->fullUrlWithQuery(['preset' => $presetKey, 'from_date' => null, 'to_date' => null, 'page' => null]) }}"
                           class="rounded-full px-3 py-1.5 text-sm font-medium ring-1 transition {{ $activePreset === $presetKey
                               ? 'bg-primary-100 text-primary-700 ring-primary-300 dark:bg-primary-500/20 dark:text-primary-200 dark:ring-primary-500/40'
                               : 'bg-gray-50 text-gray-700 ring-gray-200 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-700' }}">
                            {{ $presetLabel }}
                        </a>
                    @endforeach
                </div>
                <div class="grid grid-cols-1 gap-y-6 md:grid-cols-2 md:gap-x-10 lg:gap-x-12">
                    <div class="space-y-3" style="margin-right:10px!important;">
                        <label class="fi-input-label">From date</label>
                        <input type="date" name="from_date" value="{{ $filters['from_date'] }}" class="fi-input block w-full rounded-xl border-gray-300 text-sm focus:border-primary-600 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900" />
                    </div>
                <div class="space-y-3" style="margin-left:10px!important;">
                    <label class="fi-input-label">To date</label>
                    <input type="date" name="to_date" value="{{ $filters['to_date'] }}" class="fi-input block w-full rounded-xl border-gray-300 text-sm focus:border-primary-600 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900" />
                </div>
                <div class="space-y-3 report-filter-field report-filter-field--single filter-group filter-group--left" style="margin-right:10px!important;">
                    <label class="fi-input-label">Delivery mode</label>
                    <select
                        name="delivery_mode"
                        class="fi-input report-select--single block w-full rounded-xl border-gray-300 text-sm focus:border-primary-600 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900"
                    >
                        <option value="all" @selected($deliveryMode === 'all')>All modes</option>
                        <option value="in_person" @selected($deliveryMode === 'in_person')>In person</option>
                        <option value="online" @selected($deliveryMode === 'online')>Online</option>
                        
                    </select>
                </div>
                <div class="space-y-3 report-filter-field report-filter-field--single filter-group filter-group--right" style="margin-left:10px!important;">
                    <label class="fi-input-label">Calendars</label>
                    <select
                        name="calendar_ids[]"
                        class="fi-input report-select--single block w-full rounded-xl border-gray-300 text-sm focus:border-primary-600 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900"
                    >
                        <option value="" @selected(empty($selectedCalendars))>All calendars</option>
                        @foreach ($calendarOptions as $calendar)
                            <option value="{{ $calendar }}" @selected(in_array($calendar, $selectedCalendars, true))>{{ $calendar }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Choose a calendar to filter the report.</p>
                </div>
                <div class="space-y-3 report-filter-field report-filter-field--single" style="margin-right:10px!important;">
                    <label class="fi-input-label">Appointment types</label>
                    <select
                        name="appointment_type_ids[]"
                        class="fi-input report-select--single block w-full rounded-xl border-gray-300 text-sm focus:border-primary-600 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900"
                    >
                        <option value="" @selected(empty($selectedAppointmentTypes))>All appointment types</option>
                        @foreach ($appointmentTypeOptions as $type)
                            <option value="{{ $type }}" @selected(in_array($type, $selectedAppointmentTypes, true))>{{ $type }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Select an appointment type or leave as-is for all.</p>
                </div>
                <div class="space-y-3 report-filter-field report-filter-field--single" style="margin-left:10px!important;">
                    <label class="fi-input-label">Appointment category</label>
                    <select
                        name="acuity_category"
                        class="fi-input report-select--single block w-full rounded-xl border-gray-300 text-sm focus:border-primary-600 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900"
                    >
                        <option value="" @selected(! $selectedCategory)>All categories</option>
                        @foreach ($categoryOptions as $category)
                            <option value="{{ $category }}" @selected($selectedCategory === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Filter by Acuity category (UK only).</p>
                </div>
                <div class="space-y-3 report-filter-field report-filter-field--single" style="margin-right:10px!important;">
                    <label class="fi-input-label">Teacher</label>
                    <select
                        name="teacher_id"
                        class="fi-input report-select--single block w-full rounded-xl border-gray-300 text-sm focus:border-primary-600 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900"
                    >
                        <option value="">All teachers</option>
                        @foreach ($teacherOptions as $id => $name)
                            <option value="{{ $id }}" @selected((string) $selectedTeacher === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-3 report-filter-field report-filter-field--single" style="margin-left:10px!important;">
                    <label class="fi-input-label">Records per page</label>
                    <select
                        name="per_page"
                        class="fi-input report-select--single block w-full rounded-xl border-gray-300 text-sm focus:border-primary-600 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900"
                    >
                        @foreach ([10, 15, 25, 50, 100] as $option)
                            <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="include_cancelled" value="1" @checked($includeCancelled) class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-700" />
                        Include cancelled sessions
                    </label>
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button type="submit" color="gray" icon="heroicon-o-arrow-down-tray" formaction="{{ route('admin.uk-reports.export') }}" formmethod="GET">
                            Export CSV
                        </x-filament::button>
                        <x-filament::button type="button" color="gray" icon="heroicon-o-document-text" x-on:click="openPdfModal">
                            Export PDF
                        </x-filament::button>
                        <x-filament::button tag="a" href="{{ route('filament.admin.pages.uk-reports') }}" color="gray" outlined>
                            Reset
                        </x-filament::button>
                        <x-filament::button type="submit" color="primary" icon="heroicon-o-adjustments-horizontal">
                            Apply filters
                        </x-filament::button>
                    </div>
                </div>
                </div>
            </div>
        </details>

        <!-- <div>{{ $activeTab }}</div> -->

        <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-2">
            <button
                type="button"
                x-on:click.prevent="switchTab('attendance')"
                :class="currentTab === 'attendance' ? 'border-b-2 border-primary-600 text-primary-600' : 'text-gray-500'"
                class="px-4 py-2 text-sm font-medium transition"
            >
                Attendance
            </button>
            <button
                type="button"
                x-on:click.prevent="switchTab('development')"
                :class="currentTab === 'development' ? 'border-b-2 border-primary-600 text-primary-600' : 'text-gray-500'"
                class="px-4 py-2 text-sm font-medium transition"
            >
                Development
            </button>
            @if ($canViewEmployment)
                <button
                    type="button"
                    x-on:click.prevent="switchTab('outcomes')"
                    :class="currentTab === 'outcomes' ? 'border-b-2 border-primary-600 text-primary-600' : 'text-gray-500'"
                    class="px-4 py-2 text-sm font-medium transition"
                >
                    Employment
                </button>
            @endif
        </div>
        <div
            x-cloak
            x-show="pdfModalOpen"
            class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4 py-8"
        >
            <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-6 shadow-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Export PDF</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Choose which sections to include before downloading.</p>
                    </div>
                    <button type="button" class="text-gray-400 transition hover:text-gray-600" x-on:click="cancelPdfExport">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>
                <div class="mt-4 space-y-3 max-h-[320px] overflow-y-auto pr-2">
                    <template x-if="! sectionOptions.length">
                        <p class="text-sm text-gray-500 dark:text-gray-400">No sections available.</p>
                    </template>
                    <template x-for="section in sectionOptions" :key="section.key">
                        <label class="flex items-center gap-3 rounded-xl border border-gray-200 px-3 py-2 text-sm text-gray-800 shadow-sm transition hover:border-primary-500 dark:border-gray-700 dark:text-gray-100">
                            <input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600" :value="section.key" x-model="pdfSections">
                            <span x-text="section.label"></span>
                        </label>
                    </template>
                </div>
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-filament::button type="button" color="gray" outlined x-on:click="cancelPdfExport">Cancel</x-filament::button>
                    <x-filament::button type="button" color="primary" icon="heroicon-o-document-text" x-on:click="confirmPdfExport">
                        Download PDF
                    </x-filament::button>
                </div>
            </div>
        </div>
    </form>

    <div class="mx-auto w-full max-w-screen-2xl px-4 sm:px-6 space-y-6">
        @if ($activeTab === 'attendance')

            @if ($showTopPriorities && !empty($topPriorities ?? []))
                @php
                    if (! function_exists('ukReportsSeverityColor')) {
                        function ukReportsSeverityColor(string $severity): string
                        {
                            return match ($severity) {
                                'critical' => '#dc2626',
                                'high' => '#ea580c',
                                'medium' => '#d97706',
                                default => '#6b7280',
                            };
                        }
                    }
                @endphp
                <div class="rounded-3xl border border-rose-200 bg-white/90 p-6 shadow-sm dark:border-rose-500/30 dark:bg-rose-500/5">
                    <div class="mb-4">
                        <p class="text-sm font-semibold uppercase tracking-wide text-rose-600 dark:text-rose-200">🔥 Top Priorities Today</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Immediate actions ranked by severity</p>
                    </div>
                    <div class="space-y-3">
                        @foreach ($topPriorities as $index => $priority)
                            @php
                                $rank = $index + 1;
                                $severity = $priority['severity'] ?? 'info';
                                $color = ukReportsSeverityColor($severity);
                            @endphp
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-100 bg-gray-50/60 p-4 dark:border-gray-800 dark:bg-gray-900/40">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white text-sm font-semibold text-gray-900 dark:bg-gray-800 dark:text-gray-100">{{ $rank }}</span>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $priority['title'] ?? 'Priority' }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            Severity:
                                            <span style="color: {{ e($color) }};" class="font-semibold">
                                                {{ strtoupper($severity) }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                @if (! empty($priority['actions']))
                                    <div class="flex flex-wrap gap-2">
                                        @foreach (array_slice($priority['actions'], 0, 2) as $action)
                                            <a href="{{ $action['url'] }}" class="rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700 transition hover:border-primary-500 hover:text-primary-600 dark:border-gray-700 dark:text-gray-200 dark:hover:border-primary-400">
                                                {{ $action['label'] ?? 'View' }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($kpiCards as $card)
            <x-filament::card class="animate-fade-in" style="animation-delay: {{ $loop->index * 0.05 }}s">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br {{ $card['tone'] }} text-primary-700 dark:text-primary-200">
                        <x-filament::icon icon="{{ $card['icon'] }}" class="h-6 w-6" />
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $card['value'] }}</p>
                    </div>
                </div>
            </x-filament::card>
        @endforeach
            </section>

    <section class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-filament::card class="animate-fade-in md:h-[260px]" style="animation-delay: 0.05s">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Attendance % over time</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $dateRangeLabel }} ({{ $rangeDays }} days)</p>
                </div>
            </div>
            <div class="relative mt-4 aspect-[16/9]">
                @if ($hasAttendanceSeries && $attendanceLinePath)
                    <svg viewBox="0 0 {{ $attendanceChartWidth }} {{ $attendanceChartHeight }}" class="h-full w-full overflow-visible text-primary-600 dark:text-primary-400">
                        <defs>
                            <linearGradient id="attendanceAreaGradient" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="currentColor" stop-opacity="0.35" />
                                <stop offset="100%" stop-color="currentColor" stop-opacity="0.02" />
                            </linearGradient>
                        </defs>
                        <g stroke-width="1" stroke="rgba(148,163,184,0.25)" fill="none">
                            @foreach ($attendanceYAxisTicks as $tick)
                                <line x1="{{ $attendanceChartMarginLeft }}" x2="{{ $attendanceChartWidth - $attendanceChartMarginRight }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}"></line>
                            @endforeach
                        </g>
                        <g stroke-width="1.5" stroke="rgba(148,163,184,0.6)" fill="none">
                            <line x1="{{ $attendanceChartMarginLeft }}" y1="{{ $attendanceChartMarginTop + $attendanceInnerHeight }}" x2="{{ $attendanceChartWidth - $attendanceChartMarginRight }}" y2="{{ $attendanceChartMarginTop + $attendanceInnerHeight }}" />
                            <line x1="{{ $attendanceChartMarginLeft }}" y1="{{ $attendanceChartMarginTop }}" x2="{{ $attendanceChartMarginLeft }}" y2="{{ $attendanceChartMarginTop + $attendanceInnerHeight }}" />
                        </g>
                        @if ($attendanceAreaPath)
                            <path d="{{ $attendanceAreaPath }}" fill="url(#attendanceAreaGradient)" stroke="none" />
                        @endif
                        <path d="{{ $attendanceLinePath }}" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></path>
                        @foreach ($attendancePointsCoordinates as $point)
                            <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="2.5" fill="currentColor" />
                        @endforeach
                        <g font-size="10" fill="rgba(55, 65, 81, 0.8)" class="text-[rgba(55,65,81,0.8)] dark:fill-gray-400">
                            @foreach ($attendanceYAxisTicks as $tick)
                                <text x="{{ $attendanceChartMarginLeft - 6 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end">{{ $tick['value'] }}%</text>
                            @endforeach
                            @foreach ($attendanceXAxisLabels as $label)
                                <text x="{{ $label['x'] }}" y="{{ $attendanceChartMarginTop + $attendanceInnerHeight + 18 }}" text-anchor="middle">{{ $label['text'] }}</text>
                                <line x1="{{ $label['x'] }}" x2="{{ $label['x'] }}" y1="{{ $attendanceChartMarginTop + $attendanceInnerHeight }}" y2="{{ $attendanceChartMarginTop + $attendanceInnerHeight + 4 }}" stroke="rgba(148, 163, 184, 0.6)"></line>
                            @endforeach
                        </g>
                    </svg>
                @else
                    <div class="flex h-full w-full items-center justify-center text-sm font-medium text-gray-600 dark:text-gray-300">
                        No attendance data available for this period.
                    </div>
                @endif
            </div>
            @if (! $hasAttendanceSeries)
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    There are no attendance records within the selected filters. Try expanding the date range or removing filters to view historical data.
                </p>
            @endif
        </x-filament::card>
                <x-filament::card class="animate-fade-in md:h-[260px]" style="animation-delay: 0.1s">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Sessions per day</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $dateRangeLabel }} ({{ $rangeDays }} days)</p>
                </div>
            </div>
            <div class="relative mt-4 aspect-[16/9]">
                @if ($hasSessionsSeries && $sessionsBars->isNotEmpty())
                    <svg viewBox="0 0 {{ $sessionsChartWidth }} {{ $sessionsChartHeight }}" class="h-full w-full overflow-visible text-primary-600 dark:text-primary-400">
                        <g stroke-width="1" stroke="rgba(148,163,184,0.25)" fill="none">
                            @foreach ($sessionsYAxisTicks as $tick)
                                <line x1="{{ $sessionsChartMarginLeft }}" x2="{{ $sessionsChartWidth - $sessionsChartMarginRight }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}"></line>
                            @endforeach
                        </g>
                        <g stroke-width="1.5" stroke="rgba(148,163,184,0.6)" fill="none">
                            <line x1="{{ $sessionsChartMarginLeft }}" y1="{{ $sessionsChartMarginTop + $sessionsInnerHeight }}" x2="{{ $sessionsChartWidth - $sessionsChartMarginRight }}" y2="{{ $sessionsChartMarginTop + $sessionsInnerHeight }}" />
                            <line x1="{{ $sessionsChartMarginLeft }}" y1="{{ $sessionsChartMarginTop }}" x2="{{ $sessionsChartMarginLeft }}" y2="{{ $sessionsChartMarginTop + $sessionsInnerHeight }}" />
                        </g>
                        <g fill="rgba(198, 11, 11, 0.15)">
                            @foreach ($sessionsBars as $bar)
                                <rect x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ max($bar['width'], 1) }}" height="{{ max($bar['height'], 1) }}" rx="4"></rect>
                            @endforeach
                        </g>
                        <g fill="rgba(198, 11, 11, 0.75)">
                            @foreach ($sessionsBars as $bar)
                                <rect x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ max($bar['width'], 1) }}" height="{{ max($bar['height'], 1) }}" rx="4"></rect>
                            @endforeach
                        </g>
                        <g font-size="10" fill="rgba(55, 65, 81, 0.8)" class="text-[rgba(55,65,81,0.8)] dark:fill-gray-400">
                            @foreach ($sessionsYAxisTicks as $tick)
                                <text x="{{ $sessionsChartMarginLeft - 6 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end">{{ $tick['value'] }}</text>
                            @endforeach
                            @foreach ($sessionsXAxisLabels as $label)
                                <text x="{{ $label['x'] }}" y="{{ $sessionsChartMarginTop + $sessionsInnerHeight + 18 }}" text-anchor="middle">{{ $label['text'] }}</text>
                                <line x1="{{ $label['x'] }}" x2="{{ $label['x'] }}" y1="{{ $sessionsChartMarginTop + $sessionsInnerHeight }}" y2="{{ $sessionsChartMarginTop + $sessionsInnerHeight + 4 }}" stroke="rgba(148, 163, 184, 0.6)"></line>
                            @endforeach
                        </g>
                        <g font-size="10" fill="rgba(55, 65, 81, 0.85)" class="text-[rgba(55,65,81,0.85)] dark:fill-gray-300">
                            @foreach ($sessionsBars as $bar)
                                @if ($bar['value'] > 0)
                                    <text x="{{ $bar['x'] + ($bar['width'] / 2) }}" y="{{ max($bar['y'] - 6, 12) }}" text-anchor="middle">{{ $bar['value'] }}</text>
                                @endif
                            @endforeach
                        </g>
                    </svg>
                @else
                    <div class="flex h-full w-full items-center justify-center text-sm font-medium text-gray-600 dark:text-gray-300">
                        No session data available for this period.
                    </div>
                @endif
            </div>
            @unless ($hasSessionsSeries)
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    There are no sessions within the selected filters. Try expanding the date range or removing filters.
                </p>
            @endunless
        </x-filament::card>
    </section>

    <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <div class="space-y-4">
            <x-filament::card class="animate-fade-in md:h-[260px]" style="animation-delay: 0.05s">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">By calendar</h3>
                <div class="grid gap-10 justify-items-center md:grid-cols-2">
                    @forelse ($breakdowns['byCalendar'] as $row)
                        @php
                            $attendancePct = $row['attendance_pct'];
                            $clamped = max(0, min(100, $attendancePct ?? 0));
                            $innerLabel = ! is_null($attendancePct) ? number_format($attendancePct, 0) . '%' : '—';
                        @endphp
                        <div style="padding-top: 40px; padding-right: 20px; padding-bottom: 40px; margin: 10px;" class="flex w-full max-w-[28rem] items-center gap-12 rounded-3xl border border-gray-100 bg-gray-50 p-12 shadow-lg dark:border-gray-800 dark:bg-gray-900/40">
                            <div class="relative flex-none" style="margin:auto; height:8.5rem;width:8.5rem;">
                                <div class="absolute inset-0 rounded-full" style="background: conic-gradient(#2563EB {{ $clamped }}%, rgba(148, 163, 184, 0.15) 0)"></div>
                                <div class="absolute inset-[15%] flex items-center justify-center rounded-full bg-white text-xl font-semibold text-gray-900 shadow-md dark:bg-gray-900 dark:text-gray-100">
                                    {{ $innerLabel }}
                                </div>
                            </div>
                            <div class="flex flex-col items-start gap-2 text-left" style="margin:auto;">
                                <p class="text-xl font-semibold text-gray-800 dark:text-gray-100">{{ $row['calendar_name'] }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['sessions']) }} sessions · {{ number_format($row['students']) }} students</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Avg attendance: {{ ! is_null($attendancePct) ? number_format($attendancePct, 1) . '%' : '—' }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">No calendar data.</p>
                    @endforelse
                </div>
            </x-filament::card>

            <x-filament::card class="animate-fade-in md:h-[260px]" style="animation-delay: 0.1s">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">By appointment type</h3>
                <div class="flex flex-wrap gap-2">
                    @forelse ($breakdowns['byAppointmentType'] as $row)
                        <span class="inline-flex items-center gap-3 rounded-full bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 shadow-sm dark:bg-gray-800 dark:text-gray-200">
                            {{ $row['name'] }}
                            <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-primary-600 shadow dark:bg-gray-900 dark:text-primary-200">
                                {{ ! is_null($row['attendance_pct']) ? number_format($row['attendance_pct'], 0) . '%' : '—' }}
                            </span>
                        </span>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">No appointment type data.</p>
                    @endforelse
                </div>
            </x-filament::card>
        </div>

        <div class="space-y-4">
            <x-filament::card class="animate-fade-in md:h-[260px]" style="animation-delay: 0.05s">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">By delivery mode</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @forelse ($breakdowns['byDeliveryMode'] as $row)
                        @php
                            $percentage = $totalDeliverySessions > 0 ? round(($row['sessions'] / $totalDeliverySessions) * 100, 1) : 0;
                        @endphp
                        <div style="padding-top: 40px; padding-right: 20px; padding-bottom: 40px; margin: 10px;" class="flex items-center gap-12 rounded-3xl border border-gray-100 bg-gray-50 p-12 shadow-lg dark:border-gray-800 dark:bg-gray-900/40">
                            <div class="relative flex-none" style=" margin:auto; height:8.5rem;width:8.5rem;">
                                <div class="absolute inset-0 rounded-full" style="background: conic-gradient(#C60B0B {{ $percentage }}%, rgba(148, 163, 184, 0.12) 0)"></div>
                                <div class="absolute inset-[15%] rounded-full border border-white/60 bg-white/80 shadow-inner backdrop-blur-sm dark:border-gray-800/60 dark:bg-gray-900/70"></div>
                            </div>
                            <div class="flex flex-col items-start gap-3 text-left" style=" margin:auto;" >
                                <span class="text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ $percentage }}%</span>
                                <div>
                                    <p class="text-xl font-semibold text-gray-700 dark:text-gray-200">{{ $row['mode_label'] }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ number_format($row['sessions']) }} sessions</p>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">No delivery data available.</p>
                    @endforelse
                </div>
            </x-filament::card>

            <x-filament::card class="animate-fade-in md:h-[260px]" style="animation-delay: 0.1s">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">By teacher</h3>
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    @php
                        $totalTeacherSessions = max(1, array_sum(array_map(fn ($teacher) => $teacher['sessions'] ?? 0, $breakdowns['byTeacher'] ?? [])));
                    @endphp
                    @forelse ($breakdowns['byTeacher'] ?? [] as $row)
                        @php
                            $sessions = $row['sessions'];
                            $totalShare = $sessions > 0 ? round(($sessions / $totalTeacherSessions) * 100, 1) : null;
                            $clampedShare = $totalShare !== null ? max(0, min(100, $totalShare)) : 0;
                            $innerLabel = $totalShare !== null ? number_format($totalShare, 1) . '%' : '—';
                        @endphp
                        <div class="flex w-full items-center gap-8 rounded-3xl border border-gray-100 bg-gray-50 p-8 shadow-lg dark:border-gray-800 dark:bg-gray-900/40" style="padding-top: 40px; padding-right: 20px; padding-bottom: 40px;">
                            <div class="relative flex-none" style=" margin:auto; height:8.5rem;width:8.5rem;">
                                <div class="absolute inset-0 rounded-full" style="background: conic-gradient(#16A34A {{ $clampedShare }}%, rgba(148, 163, 184, 0.15) 0)"></div>
                                <div class="absolute inset-[15%] flex items-center justify-center rounded-full bg-white text-xl font-semibold text-gray-900 shadow-md dark:bg-gray-900 dark:text-gray-100">
                                    {{ $innerLabel }}
                                </div>
                            </div>
                            <div class="flex flex-col items-start gap-2 text-left">
                                <p class="text-xl font-semibold text-gray-800 dark:text-gray-100">{{ $row['teacher_name'] }}</p>
                               <p class="text-sm text-gray-500 dark:text-gray-400">{{ number_format($sessions) }} sessions</p>
                               <p class="text-sm text-gray-500 dark:text-gray-400">Share of total sessions: {{ $totalShare !== null ? number_format($totalShare, 1) . '%' : '—' }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Normal: {{ number_format($row['normal'] ?? 0) }} · Covering: {{ number_format($row['covering'] ?? 0) }} · Sick: {{ number_format($row['sick'] ?? 0) }}</p>
                           </div>
                       </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">No teacher data.</p>
                    @endforelse
                </div>
                <div class="mt-4 text-xs text-gray-500 dark:text-gray-400" style="padding-top: 50px;">
                    <p><strong>Legend:</strong> Each ring shows a teacher's percentage of total sessions in the selected range.</p>
                </div>
            </x-filament::card>

            @if(!empty($breakdowns['coverByTeacher'] ?? []))
                <x-filament::card class="animate-fade-in" style="animation-delay: 0.15s">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Covering teachers</h3>
                    <div class="space-y-4">
                        @foreach ($breakdowns['coverByTeacher'] as $row)
                            <div class="rounded-3xl border border-gray-100 bg-gray-50 p-6 shadow-lg dark:border-gray-800 dark:bg-gray-900/40">
                                <div class="flex items-center justify-between">
                                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $row['teacher_name'] }}</p>
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-gray-600 shadow dark:bg-gray-800 dark:text-gray-300">{{ number_format($row['sessions']) }} sessions</span>
                                </div>
                                @if (!empty($row['assigned_teachers']))
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Covering for: {{ implode(', ', $row['assigned_teachers']) }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-filament::card>
            @endif
        </div>
    </section>

    @if (! $isUkManager)
    <section class="space-y-4">
        <x-filament::card class="border-amber-200 bg-amber-50/80 dark:border-amber-500/40 dark:bg-amber-500/10 animate-fade-in" style="animation-delay: 0.05s">
            <div class="flex items-center gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-amber-600 dark:text-amber-200" />
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-200">Sessions needing attention</h3>
                    <p class="text-xs text-amber-700/80 dark:text-amber-200/80">Lowest attendance in the current selection.</p>
                </div>
            </div>
            <div class="mt-4 space-y-3">
                @if ($sessionsNeedingAttention)
                    @php
                        $s = $sessionsNeedingAttention;
                        $attendance = $s['attendance_rate'] !== null ? number_format($s['attendance_rate'], 1) . '%' : '—';
                    @endphp
                    <div class="rounded-2xl border border-amber-200 bg-white p-4 shadow-sm dark:border-amber-400/40 dark:bg-amber-500/10">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm font-semibold text-amber-800 dark:text-amber-100">
                            <span>{{ $s['session_date'] }} • {{ $s['calendar_label'] }}</span>
                            <span>{{ $attendance }}</span>
                        </div>
                        <p class="text-xs text-amber-700/80 dark:text-amber-200/80">{{ $s['appointment_type'] ?? '—' }} • {{ $s['teacher']['name'] ?? 'Unassigned' }}</p>
                        <p class="mt-2 text-xs text-amber-700/80 dark:text-amber-200/80">P {{ $s['present_count'] }} / L {{ $s['late_count'] }} / A {{ $s['absent_count'] }}</p>
                    </div>
                @else
                    <p class="text-sm text-amber-700/80 dark:text-amber-200/80">No sessions fall below the attendance threshold.</p>
                @endif
            </div>
        </x-filament::card>

        <x-filament::card class="border-sky-200 bg-sky-50/80 dark:border-sky-500/40 dark:bg-sky-500/10 animate-fade-in" style="animation-delay: 0.1s">
            <div class="flex items-center gap-3">
                <x-filament::icon icon="heroicon-o-envelope-open" class="h-5 w-5 text-sky-600 dark:text-sky-200" />
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-200">Pending follow-ups</h3>
                    <p class="text-xs text-sky-700/80 dark:text-sky-200/80">Sessions awaiting absence emails.</p>
                </div>
            </div>
            <div class="mt-4 space-y-3">
                @forelse ($pendingFollowUps as $row)
                    <div class="rounded-2xl border border-sky-200 bg-white p-4 shadow-sm dark:border-sky-400/40 dark:bg-sky-500/10">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm font-semibold text-sky-800 dark:text-sky-100">
                            <span>{{ $row['session_date'] }} • {{ $row['calendar_label'] }}</span>
                            <span>{{ number_format($row['pending_absence_count']) }} pending</span>
                        </div>
                        <p class="text-xs text-sky-700/80 dark:text-sky-200/80">{{ $row['appointment_type'] ?? '—' }} • {{ $row['teacher']['name'] ?? 'Unassigned' }}</p>
                    </div>
                @empty
                    <p class="text-sm text-sky-700/80 dark:text-sky-200/80">All absence emails are up to date.</p>
                @endforelse
            </div>
    </x-filament::card>
</section>
    @endif

    @php
        $spotlightRows = $spotlight
            ->filter(fn ($row) => ! is_null($row['attendance_rate']) && $row['attendance_rate'] < 90)
            ->sortBy('attendance_rate')
            ->take(5);
    @endphp

    @if ($currentUser?->hasRole('admin', 'super_admin'))
    <x-filament::card class="animate-fade-in" style="animation-delay: 0.05s">
        <div class="flex items-center gap-3">
            <x-filament::icon icon="heroicon-o-briefcase" class="h-5 w-5 text-primary-600 dark:text-primary-300" />
            <div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Session spotlight</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Key sessions with attendance below 90%.</p>
            </div>
        </div>

        @if ($spotlightRows->isEmpty())
            <div class="mt-5 rounded-2xl border border-gray-100 bg-gray-50/70 p-6 text-sm text-gray-500 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-300">
                All monitored sessions are above the 90% attendance threshold right now.
            </div>
        @else
            <div class="mt-5 space-y-3">
                @foreach ($spotlightRows as $row)
                    @php
                        $attendance = $row['attendance_rate'] !== null ? number_format($row['attendance_rate'], 1) . '%' : '—';
                        $timeRange = trim(($row['start_time'] ?: '—') . ' – ' . ($row['end_time'] ?: '—'));
                    @endphp
                    <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-gray-800 dark:bg-gray-900/40">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="space-y-1">
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ $row['session_date'] }} • {{ $row['calendar_label'] }}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row['appointment_type'] ?? '—' }} • {{ $timeRange }} • {{ $row['teacher']['name'] ?? 'Unassigned' }}
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-2 rounded-full bg-primary-100 px-3 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-500/20 dark:text-primary-200">
                                    Attendance {{ $attendance }}
                                </span>
                                <span class="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                    P {{ $row['present_count'] }} / L {{ $row['late_count'] }} / A {{ $row['absent_count'] }}
                                </span>
                                <x-filament::button tag="a" href="{{ $row['show_url'] }}" size="xs" color="gray" icon="heroicon-o-arrow-top-right-on-square">
                                    View
                                </x-filament::button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::card>
    @endif
        @elseif ($activeTab === 'development')
            <section>
                <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Skill development averages</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Most recent FINAL assessment per UK student in the selected range. {{ number_format($skillStats['student_count'] ?? 0) }} students included.</p>
                </div>

                <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
                    @foreach ($skillCards as $card)
                        <x-filament::card class="animate-fade-in" style="animation-delay: {{ $loop->index * 0.05 }}s">
                            <div class="flex items-center gap-4">
                                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br {{ $card['tone'] }} text-primary-700 dark:text-primary-200">
                                    <x-filament::icon icon="{{ $card['icon'] }}" class="h-6 w-6" />
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                                    <p class="mt-1 text-2xl font-semibold {{ $card['is_empty'] ? 'text-gray-400 dark:text-gray-500' : 'text-gray-900 dark:text-white' }}">{{ $card['value'] }}</p>
                                </div>
                            </div>
                        </x-filament::card>
                    @endforeach
                </div>
            </section>
        @elseif ($activeTab === 'outcomes')
            @if ($canViewEmployment && ($employmentKpis['total_students'] ?? 0) > 0)
                @php
                    $funnel = $employmentKpis['retention_funnel'] ?? [];
                    $attendanceOutcomes = $employmentKpis['attendance_outcomes'] ?? [];
                    $teacherImpact = $employmentKpis['teacher_impact'] ?? [];
                @endphp

                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Employment Outcomes</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Track job placements and retention for the current selection.</p>
                    </div>

                    <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($employmentKpiCards as $card)
                            <x-filament::card class="animate-fade-in" style="animation-delay: {{ $loop->index * 0.05 }}s">
                                <div class="flex items-center gap-4">
                                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br {{ $card['tone'] }} text-primary-700 dark:text-primary-200">
                                        <x-filament::icon :icon="$card['icon']" class="h-6 w-6" />
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                                        <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $card['value'] }}</p>
                                    </div>
                                </div>
                            </x-filament::card>
                        @endforeach
                    </section>

                    <section class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-filament::card class="md:h-[260px]" style="animation-delay: 0.05s">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Retention funnel</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Progress through the employability journey</p>
                                </div>
                            </div>
                            <div class="mt-6 grid grid-cols-1 gap-4 text-center sm:grid-cols-4">
                                @php
                                    $steps = [
                                        ['label' => 'Started', 'value' => $funnel['started'] ?? 0],
                                        ['label' => 'Week 2', 'value' => $funnel['week2'] ?? 0],
                                        ['label' => 'Week 4', 'value' => $funnel['week4'] ?? 0],
                                        ['label' => 'Completed', 'value' => $funnel['completed'] ?? 0],
                                    ];
                                    $baseline = max(1, $steps[0]['value']);
                                @endphp
                                @foreach ($steps as $step)
                                    @php $percent = $baseline > 0 ? round(($step['value'] / $baseline) * 100) : 0; @endphp
                                    <div class="space-y-1">
                                        <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($step['value']) }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $step['label'] }} ({{ $percent }}%)</p>
                                    </div>
                                @endforeach
                            </div>
                        </x-filament::card>

                        <x-filament::card class="md:h-[260px]" style="animation-delay: 0.1s">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Attendance vs outcomes</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Comparing high vs. low attendance cohorts</p>
                                </div>
                            </div>
                            <div class="mt-6 grid grid-cols-2 gap-4 text-center">
                                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/60">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">≥ 85% attendance</p>
                                    <p class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($attendanceOutcomes['high']['placement_rate'] ?? 0, 1) }}%</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($attendanceOutcomes['high']['placements'] ?? 0) }} / {{ number_format($attendanceOutcomes['high']['students'] ?? 0) }} placed</p>
                                </div>
                                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/60">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">&lt; 60% attendance</p>
                                    <p class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($attendanceOutcomes['low']['placement_rate'] ?? 0, 1) }}%</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($attendanceOutcomes['low']['placements'] ?? 0) }} / {{ number_format($attendanceOutcomes['low']['students'] ?? 0) }} placed</p>
                                </div>
                            </div>
                        </x-filament::card>
                    </section>

                    @if (! empty($teacherImpact))
                        <x-filament::card class="animate-fade-in" style="animation-delay: 0.15s">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Teacher impact</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Placement success by teacher cohort</p>
                                </div>
                            </div>
                            <div class="mt-4 overflow-x-auto">
                                <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                                    <thead>
                                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            <th class="py-2">Teacher</th>
                                            <th class="py-2 text-right">Students</th>
                                            <th class="py-2 text-right">Placement rate</th>
                                            <th class="py-2 text-right">Avg attendance</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                        @foreach ($teacherImpact as $row)
                                            <tr>
                                                <td class="py-2 font-semibold text-gray-900 dark:text-gray-100">{{ $row['teacher_name'] }}</td>
                                                <td class="py-2 text-right text-gray-500 dark:text-gray-400">{{ number_format($row['student_count']) }}</td>
                                                <td class="py-2 text-right text-gray-500 dark:text-gray-400">{{ number_format($row['placement_rate'], 1) }}%</td>
                                                <td class="py-2 text-right text-gray-500 dark:text-gray-400">{{ $row['avg_attendance'] !== null ? number_format($row['avg_attendance'], 1).'%' : '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </x-filament::card>
                    @endif
                </div>
            @elseif (! $canViewEmployment)
                <p class="text-sm text-gray-500 dark:text-gray-400">You do not have permission to view employment outcomes.</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Employment outcomes are unavailable for this selection.</p>
            @endif
        @endif



    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('ukReportFilterForm', ({ sectionOptions, defaultSections, initialTab = 'attendance' }) => ({
                pdfModalOpen: false,
                sectionOptions,
                pdfSections: [...defaultSections],
                currentTab: initialTab || 'attendance',
                openPdfModal() {
                    this.pdfModalOpen = true;
                },
                cancelPdfExport() {
                    this.pdfModalOpen = false;
                },
                confirmPdfExport() {
                    if (! this.pdfSections.length) {
                        this.pdfSections = this.sectionOptions.map(section => section.key);
                    }

                    if (this.$refs.pdfSectionsField) {
                        this.$refs.pdfSectionsField.value = this.pdfSections.join(',');
                    }

                    if (this.$refs.pdfSubmit) {
                        this.$refs.pdfSubmit.click();
                    }

                    setTimeout(() => {
                        if (this.$refs.pdfSectionsField) {
                            this.$refs.pdfSectionsField.value = '';
                        }
                    }, 0);

                    this.pdfModalOpen = false;
                },
                switchTab(tabName) {
                    this.currentTab = tabName;

                    if (this.$refs.tabField) {
                        this.$refs.tabField.value = tabName;
                    }

                    const form = this.$refs.filtersForm;
                    if (! form) {
                        return;
                    }

                    const formData = new FormData(form);
                    formData.set('tab', tabName);
                    const query = new URLSearchParams(formData);
                    const action = form.getAttribute('action') ?? window.location.pathname;
                    const queryString = query.toString();
                    window.location.href = queryString ? `${action}?${queryString}` : action;
                },
            }));
        });
    </script>

        </section>
    </main>
</x-filament-panels::layout>

@push('styles')
    <style>
        .uk-reports-page {
            font-size: 0.7rem;
        }

        .uk-reports-page .text-xs {
            font-size: 0.55rem !important;
        }

        .uk-reports-page .text-sm {
            font-size: 0.7rem !important;
        }

        .uk-reports-page .text-base {
            font-size: 0.8rem !important;
        }

        .uk-reports {
            scroll-behavior: smooth;
        }

        .uk-reports .report-filter-field {
            position: relative;
        }

        .uk-reports .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (min-width: 768px) {
            .uk-reports .filter-group--left {
                padding-right: 1.5rem;
            }

            .uk-reports .filter-group--right {
                padding-left: 1.5rem;
            }
        }

        @media (min-width: 1024px) {
            .uk-reports .filter-group--left {
                padding-right: 2rem;
            }

            .uk-reports .filter-group--right {
                padding-left: 2rem;
            }
        }

        .uk-reports .report-select--single {
            appearance: none !important;
            -webkit-appearance: none !important;
            padding-right: 2.5rem !important;
        }

        .uk-reports .report-filter-field--single::after {
            content: '';
            position: absolute;
            top: 50%;
            right: 1rem;
            width: 1rem;
            height: 1rem;
            pointer-events: none;
            transform: translateY(-50%);
            background-repeat: no-repeat;
            background-size: 1rem 1rem;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' viewBox='0 0 24 24'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
        }

        [data-theme='dark'] .uk-reports .report-filter-field--single::after {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' stroke='%23d1d5db' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' viewBox='0 0 24 24'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
        }

        @keyframes fade-up {
            from {
                opacity: 0;
                transform: translateY(18px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fade-up 0.45s ease-out both;
        }
    </style>
@endpush
