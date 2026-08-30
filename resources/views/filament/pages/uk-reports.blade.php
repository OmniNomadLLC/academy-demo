<x-filament::page class="mx-auto w-full max-w-7xl">
    @php
        $summary = $this->summaryMetrics();
        $attentionCards = $this->attentionRequiredCards();
        $kpiStrip = $this->kpiStripMetrics();
        $classOutlook = $this->classOutlook();
        $topPriorities = $this->topPriorities();
        $attendanceTrend = $this->attendanceTrendInsight();
        $studentRisks = $this->studentRiskInsight();
        $classRisksData = $this->classRiskInsight();
        $attendanceTrendUrl = $this->attendanceTrendUrl();
        $studentRiskUrl = $this->highRiskStudentsUrl();
        $classRiskUrl = $this->classRiskUrl();
        $calendarBreakdown = $this->calendarBreakdown();
        $appointmentBreakdown = $this->appointmentTypeBreakdown();
        $deliveryBreakdown = $this->deliveryModeBreakdown();
        $teacherBreakdown = $this->teacherBreakdown();
        $sessions = $this->paginatedSessions();
        $lowAttendance = $this->lowAttendanceSessions();
        $followUps = $this->pendingFollowUps();
        $availableCalendars = $this->availableCalendars();
        $availableTeachers = $this->availableTeachers();
        $availableAppointmentTypes = $this->availableAppointmentTypes();
        $presetOptions = [
            'last7' => 'Last 7 days',
            'last30' => 'Last 30 days',
            'current_month' => 'Current month',
            'previous_month' => 'Previous month',
            'year_to_date' => 'Year to date',
        ];
        $isUkManager = auth()->user()?->isUkManager();
        $attentionToneClasses = [
            'danger' => [
                'border' => 'border-red-200/80 dark:border-red-500/40',
                'bg' => 'bg-red-50 dark:bg-red-500/10',
                'text' => 'text-red-900 dark:text-red-100',
                'muted' => 'text-red-600 dark:text-red-300',
            ],
            'warning' => [
                'border' => 'border-amber-200/80 dark:border-amber-500/40',
                'bg' => 'bg-amber-50 dark:bg-amber-500/10',
                'text' => 'text-amber-900 dark:text-amber-100',
                'muted' => 'text-amber-600 dark:text-amber-200',
            ],
            'info' => [
                'border' => 'border-blue-200/80 dark:border-blue-500/40',
                'bg' => 'bg-blue-50 dark:bg-blue-500/10',
                'text' => 'text-blue-900 dark:text-blue-100',
                'muted' => 'text-blue-600 dark:text-blue-200',
            ],
        ];
        $prioritySeverityClasses = [
            'critical' => ['text' => 'text-red-700 dark:text-red-300', 'bullet' => 'bg-red-500'],
            'warning' => ['text' => 'text-amber-700 dark:text-amber-300', 'bullet' => 'bg-amber-500'],
            'info' => ['text' => 'text-blue-700 dark:text-blue-300', 'bullet' => 'bg-blue-500'],
        ];
    @endphp

    <div class="space-y-6">
        <pre>{{ print_r($topPriorities, true) }}</pre>

        @if (!empty($topPriorities))
            <x-filament::section>
                <x-slot name="heading">
                    🔥 Top Priorities Today
                </x-slot>
                <x-slot name="description">
                    Immediate actions ranked by severity.
                </x-slot>
                @foreach ($topPriorities as $priority)
                    <div class="mb-2 rounded border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                        <strong>{{ $priority['title'] ?? 'Priority' }}</strong>
                        <div class="text-xs text-gray-500">{{ $priority['severity'] ?? 'info' }}</div>
                    </div>
                @endforeach
            </x-filament::section>
        @endif

        @if (!empty($topPriorities))
            <x-filament::card class="border border-red-100 bg-red-50/40 dark:border-red-500/30 dark:bg-red-500/10">
                <div class="mb-3 flex items-center justify-between">
                    <div>
                        <div class="text-sm font-semibold uppercase tracking-wide text-red-700 dark:text-red-200">🔥 Top Priorities Today</div>
                        <p class="text-xs text-red-600 dark:text-red-300">Instant actions ranked by impact + urgency.</p>
                    </div>
                </div>
                <ol class="space-y-2">
                    @foreach ($topPriorities as $index => $priority)
                        @php
                            $rank = $index + 1;
                            $severity = $priority['severity'] ?? 'info';
                            $colors = $prioritySeverityClasses[$severity] ?? $prioritySeverityClasses['info'];
                        @endphp
                        <li>
                            <div class="flex items-start gap-3 rounded-2xl border border-white/50 bg-white/80 p-3 text-sm shadow-sm dark:border-gray-800/60 dark:bg-gray-900/70">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white dark:bg-white dark:text-gray-900">{{ $rank }}</span>
                                <div class="flex-1">
                                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $priority['title'] }}</div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400 capitalize">Severity: <span class="font-semibold {{ $colors['text'] }}">{{ $priority['severity'] }}</span> · Urgency: {{ $priority['urgency'] }}</div>
                                    @if (!empty($priority['actions']))
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach (array_slice($priority['actions'], 0, 2) as $action)
                                                <a href="{{ $action['url'] ?? '#' }}" class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-700 transition hover:border-primary-200 hover:text-primary-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:text-primary-300">
                                                    {{ $action['label'] ?? 'View' }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-filament::card>
        @endif

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-4">
            @foreach ($attentionCards as $card)
                @php
                    $tone = $attentionToneClasses[$card['tone']] ?? $attentionToneClasses['info'];
                @endphp
                <a
                    href="{{ $card['url'] }}"
                    class="group flex flex-col rounded-2xl border-2 {{ $tone['border'] }} {{ $tone['bg'] }} p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                >
                    <div class="flex items-center justify-between text-xs font-semibold uppercase tracking-wide {{ $tone['muted'] }}">
                        <div class="flex items-center gap-2">
                            <x-filament::icon :icon="$card['icon']" class="h-5 w-5" />
                            <span>{{ $card['label'] }}</span>
                        </div>
                        <span class="text-[11px]">Tap to drill down</span>
                    </div>
                    <div class="mt-3 text-4xl font-bold {{ $tone['text'] }}">
                        {{ number_format($card['count']) }}
                    </div>
                    <p class="mt-2 text-sm {{ $tone['muted'] }}">{{ $card['description'] }}</p>
                </a>
            @endforeach
        </div>

        <x-filament::card class="border border-amber-100 bg-amber-50/40 dark:border-amber-500/40 dark:bg-amber-500/5">
            <div class="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                <span>⚠️ Insights</span>
                <span class="text-xs font-normal uppercase text-amber-500">Automated checks</span>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <a href="{{ $attendanceTrendUrl }}" class="rounded-2xl border border-white/60 bg-white/70 p-4 shadow-sm transition hover:border-primary-200 hover:bg-white dark:border-gray-700/70 dark:bg-gray-900/60">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Attendance trend</div>
                    <div class="mt-2 flex items-baseline gap-2">
                        <div class="text-3xl font-semibold text-gray-900 dark:text-gray-100">
                            @if(isset($attendanceTrend['current_average']))
                                {{ number_format($attendanceTrend['current_average'], 1) }}%
                            @else
                                —
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            vs {{ isset($attendanceTrend['previous_average']) ? number_format($attendanceTrend['previous_average'], 1).'%' : '—' }}
                        </div>
                    </div>
                    @php
                        $trendDelta = $attendanceTrend['delta'] ?? null;
                        $trendIsDrop = $trendDelta !== null && $trendDelta < 0;
                        $trendColor = $trendDelta === null ? 'text-gray-500 dark:text-gray-400' : ($trendIsDrop ? 'text-red-600 dark:text-red-300' : 'text-emerald-600 dark:text-emerald-300');
                    @endphp
                    <div class="mt-2 text-sm font-semibold {{ $trendColor }}">
                        @if($trendDelta === null)
                            Not enough data
                        @else
                            {{ $trendIsDrop ? '▼' : '▲' }} {{ number_format(abs($trendDelta), 1) }} pts
                        @endif
                    </div>
                    @if(isset($attendanceTrend['alert']))
                        <div class="mt-2 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700 dark:bg-red-500/20 dark:text-red-200">
                            Drop {{ abs($attendanceTrend['alert']['value']) }}%
                        </div>
                    @endif
                </a>

                <a href="{{ $studentRiskUrl }}" class="rounded-2xl border border-white/60 bg-white/70 p-4 shadow-sm transition hover:border-primary-200 hover:bg-white dark:border-gray-700/70 dark:bg-gray-900/60">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">High risk students</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($studentRisks['count'] ?? 0) }}</div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Flagged by attendance, missing rosters, or recent absences.</p>
                    <div class="mt-3 space-y-1">
                        @foreach (collect($studentRisks['students'] ?? [])->take(3) as $student)
                            <div class="flex items-center justify-between text-sm text-gray-800 dark:text-gray-100">
                                <span class="truncate">{{ $student['name'] }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Score {{ $student['risk_score'] }}</span>
                            </div>
                        @endforeach
                        @if(empty($studentRisks['students']))
                            <p class="text-sm text-gray-500 dark:text-gray-400">No students currently at risk.</p>
                        @endif
                    </div>
                </a>

                <a href="{{ $classRiskUrl }}" class="rounded-2xl border border-white/60 bg-white/70 p-4 shadow-sm transition hover:border-primary-200 hover:bg-white dark:border-gray-700/70 dark:bg-gray-900/60">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Classes at risk (48h)</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($classRisksData['count'] ?? 0) }}</div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Empty rosters, low enrollment, or missing teachers.</p>
                    <div class="mt-3 space-y-1">
                        @foreach (collect($classRisksData['classes'] ?? [])->take(3) as $class)
                            <div class="flex items-center justify-between text-sm text-gray-800 dark:text-gray-100">
                                <span class="truncate">{{ \Illuminate\Support\Carbon::parse($class['date'])->format('d M') }} · {{ $class['calendar'] }}</span>
                                <span class="text-xs {{ $class['risk_level'] === 'critical' ? 'text-red-600 dark:text-red-300' : 'text-amber-600 dark:text-amber-300' }}">{{ ucfirst($class['risk_level']) }}</span>
                            </div>
                        @endforeach
                        @if(empty($classRisksData['classes']))
                            <p class="text-sm text-gray-500 dark:text-gray-400">No risky classes detected.</p>
                        @endif
                    </div>
                </a>
            </div>
        </x-filament::card>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-gray-200/70 bg-white/80 p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900/40">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Active students</div>
                <div class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($kpiStrip['active_students'] ?? 0) }}</div>
            </div>
            <div class="rounded-2xl border border-gray-200/70 bg-white/80 p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900/40">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Avg attendance</div>
                <div class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">
                    {{ isset($kpiStrip['average_attendance']) ? number_format($kpiStrip['average_attendance'], 1).'%' : '—' }}
                </div>
            </div>
            <div class="rounded-2xl border border-gray-200/70 bg-white/80 p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900/40">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Classes today</div>
                <div class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($kpiStrip['classes_today'] ?? 0) }}</div>
            </div>
            <div class="rounded-2xl border border-gray-200/70 bg-white/80 p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900/40">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Fill rate</div>
                <div class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">
                    {{ isset($kpiStrip['fill_rate']) ? number_format($kpiStrip['fill_rate'], 1).'%' : '—' }}
                </div>
            </div>
        </div>

        <x-filament::card>
            <div class="mb-4 flex flex-col gap-1">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Today & this week</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Grouped classes, flagged gaps, and links to the underlying rosters.</p>
            </div>
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                @foreach (['today', 'upcoming'] as $blockKey)
                    @php
                        $block = $classOutlook[$blockKey] ?? null;
                    @endphp
                    <div class="rounded-2xl border border-gray-200/70 bg-white/70 p-4 shadow-sm dark:border-gray-700/60 dark:bg-gray-900/40">
                        @if ($block)
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ $block['label'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $block['range_label'] }}</div>
                                </div>
                                <a href="{{ $block['url'] }}" class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">Open table</a>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                                <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-red-700 dark:bg-red-500/10 dark:text-red-200">Empty {{ $block['issues']['empty'] ?? 0 }}</span>
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200">Overbooked {{ $block['issues']['overbooked'] ?? 0 }}</span>
                                <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-blue-700 dark:bg-blue-500/10 dark:text-blue-200">Missing teacher {{ $block['issues']['missing_teacher'] ?? 0 }}</span>
                            </div>
                            <div class="mt-4 space-y-3">
                                @forelse (($block['groups'] ?? collect())->take(5) as $class)
                                    @php
                                        $classDate = isset($class['session_date']) && $class['session_date']
                                            ? \Illuminate\Support\Carbon::parse($class['session_date'])
                                            : null;
                                        $startTime = isset($class['start_time']) && $class['start_time']
                                            ? \Illuminate\Support\Str::of($class['start_time'])->substr(0, 5)
                                            : '—';
                                        $endTime = isset($class['end_time']) && $class['end_time']
                                            ? \Illuminate\Support\Str::of($class['end_time'])->substr(0, 5)
                                            : '—';
                                    @endphp
                                    <a href="{{ $class['url'] }}" class="flex items-start justify-between rounded-xl border border-gray-200/70 bg-white/90 px-3 py-2 text-sm text-gray-800 transition hover:border-primary-200 hover:bg-primary-50/50 dark:border-gray-700/60 dark:bg-white/5 dark:text-gray-100">
                                        <div class="space-y-1">
                                            <div class="font-semibold">{{ $classDate ? $classDate->format('D d M') : $class['session_date'] }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $startTime }} – {{ $endTime }}</div>
                                            <div class="text-xs text-gray-600 dark:text-gray-300">{{ $class['calendar_label'] }} · {{ $class['appointment_label'] }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $class['teacher_label'] }}</div>
                                        </div>
                                        <div class="flex flex-col items-end gap-1 text-right">
                                            <div class="text-lg font-semibold">
                                                {{ $class['student_count'] }}
                                                @if ($class['capacity'])
                                                    <span class="text-sm text-gray-500 dark:text-gray-400">/ {{ $class['capacity'] }}</span>
                                                @endif
                                            </div>
                                            <div class="flex flex-wrap justify-end gap-1">
                                                @if ($class['is_empty'])
                                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700 dark:bg-red-500/10 dark:text-red-200">Empty</span>
                                                @endif
                                                @if ($class['is_overbooked'])
                                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-200">Overbooked</span>
                                                @endif
                                                @if ($class['is_missing_teacher'])
                                                    <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-200">Assign teacher</span>
                                                @endif
                                            </div>
                                        </div>
                                    </a>
                                @empty
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No classes in this window.</p>
                                @endforelse
                            </div>
                        @else
                            <p class="text-sm text-gray-500 dark:text-gray-400">No data.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::card>

        <x-filament::card class="bg-gradient-to-br from-white via-white to-gray-50 shadow-sm dark:from-gray-800 dark:via-gray-800 dark:to-gray-900">
            <div class="flex flex-col gap-6">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Quick ranges</span>
                    @foreach ($presetOptions as $value => $label)
                        <x-filament::button
                            size="xs"
                            color="{{ $this->rangePreset === $value ? 'primary' : 'gray' }}"
                            wire:click="applyPreset('{{ $value }}')"
                            :icon=" $this->rangePreset === $value ? 'heroicon-m-clock' : null "
                        >
                            {{ $label }}
                        </x-filament::button>
                    @endforeach
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <div class="space-y-1.5">
                        <label class="fi-input-label">From date</label>
                        <x-filament::input type="date" wire:model.defer="fromDate" />
                    </div>
                    <div class="space-y-1.5">
                        <label class="fi-input-label">To date</label>
                        <x-filament::input type="date" wire:model.defer="toDate" />
                    </div>
                    <div class="space-y-1.5">
                        <label class="fi-input-label">Delivery mode</label>
                        <select wire:model.defer="deliveryMode" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900">
                            <option value="all">All modes</option>
                            <option value="in_person">Presential</option>
                            <option value="virtual">Online</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <label class="fi-input-label">Calendars</label>
                        <select multiple size="6" wire:model.defer="selectedCalendars" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900">
                            @forelse ($availableCalendars as $calendar)
                                <option value="{{ $calendar }}">{{ $calendar }}</option>
                            @empty
                                <option disabled>No calendars detected</option>
                            @endforelse
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Ctrl/Cmd-click to multi-select.</p>
                    </div>
                    <div class="space-y-1.5">
                        <label class="fi-input-label">Appointment types</label>
                        <select multiple size="6" wire:model.defer="selectedAppointmentTypes" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900">
                            @forelse ($availableAppointmentTypes as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @empty
                                <option disabled>No appointment types detected in range</option>
                            @endforelse
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Leave empty to include every type.</p>
                    </div>
                    <div class="space-y-1.5">
                        <label class="fi-input-label">Teacher</label>
                        <select wire:model.defer="teacherId" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900">
                            <option value="">All teachers</option>
                            @foreach ($availableTeachers as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <label class="fi-input-label">Records per page</label>
                        <select wire:model.defer="perPage" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900">
                            @foreach ([10, 15, 25, 50, 100] as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <label class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model.defer="onlyLowAttendance" class="rounded border-gray-300 text-primary-600" />
                        Only show sessions below {{ \App\Filament\Pages\UkReports::LOW_ATTENDANCE_THRESHOLD }}% attendance
                    </label>
                    <label class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model.defer="includeCancelled" class="rounded border-gray-300 text-primary-600" />
                        Include sessions marked as cancelled
                    </label>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ number_format($sessions->total()) }} session rows available for the selected filters.
                    </div>
                    <div class="flex gap-2">
                        <x-filament::button color="gray" outlined wire:click="resetFilters" icon="heroicon-m-arrow-path">Reset</x-filament::button>
                        <x-filament::button wire:click="applyFilters" icon="heroicon-m-adjustments-vertical">Apply filters</x-filament::button>
                    </div>
                </div>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">UK attendance overview</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Insights across sessions in the selected timeframe.</p>
                </div>
                <div class="flex items-center gap-3">
                    <div wire:loading.flex class="items-center gap-2 text-sm text-primary-600 dark:text-primary-400">
                        <x-filament::loading-indicator class="h-4 w-4" /> Updating…
                    </div>
                    <x-filament::button wire:click="downloadCsv" icon="heroicon-m-arrow-down-tray" color="gray">Export CSV</x-filament::button>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-gray-200/80 p-4 dark:border-gray-700/60">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Total sessions</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($summary['total_sessions'] ?? 0) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200/80 p-4 dark:border-gray-700/60">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Average attendance</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        @if (!is_null($summary['average_attendance']))
                            {{ number_format($summary['average_attendance'], 1) }}%
                        @else
                            <span class="text-base text-gray-500 dark:text-gray-400">—</span>
                        @endif
                    </div>
                </div>
                <div class="rounded-xl border border-gray-200/80 p-4 dark:border-gray-700/60">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Unique students</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($summary['unique_students'] ?? 0) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200/80 p-4 dark:border-gray-700/60">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pending absence emails</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($summary['pending_absence'] ?? 0) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($summary['low_attendance_sessions'] ?? 0) }} sessions below {{ \App\Filament\Pages\UkReports::LOW_ATTENDANCE_THRESHOLD }}%</div>
                </div>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">By calendar</h3>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Top {{ count($calendarBreakdown) }}</span>
                    </div>
                    <div class="overflow-hidden rounded-xl border border-gray-200/70 dark:border-gray-700/60">
                        <table class="w-full table-fixed text-sm">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Calendar</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500">Sessions</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500">Students</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500">Attendance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700/70">
                                @forelse ($calendarBreakdown as $row)
                                    <tr>
                                        <td class="px-3 py-2 text-sm text-gray-800 dark:text-gray-100">{{ $row['label'] }}</td>
                                        <td class="px-3 py-2 text-right text-sm">{{ number_format($row['sessions']) }}</td>
                                        <td class="px-3 py-2 text-right text-sm">{{ number_format($row['students']) }}</td>
                                        <td class="px-3 py-2 text-right text-sm">
                                            @if (!is_null($row['attendance_rate']))
                                                {{ number_format($row['attendance_rate'], 1) }}%
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No data for the selected filters.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div>
                    <div class="mb-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">By appointment type</h3>
                            <div class="mt-2 space-y-2">
                                @forelse ($appointmentBreakdown as $item)
                                    <div class="flex items-center justify-between rounded-lg border border-gray-200/70 px-3 py-2 text-sm dark:border-gray-700/60">
                                        <span class="text-gray-800 dark:text-gray-100">{{ $item['label'] }}</span>
                                        <span class="text-gray-600 dark:text-gray-300">
                                            @if (!is_null($item['attendance_rate']))
                                                {{ number_format($item['attendance_rate'], 0) }}%
                                            @else
                                                —
                                            @endif
                                        </span>
                                    </div>
                                @empty
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No appointment types in view.</p>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">By delivery mode</h3>
                            <div class="mt-2 space-y-2">
                                @forelse ($deliveryBreakdown as $item)
                                    <div class="flex items-center justify-between rounded-lg border border-gray-200/70 px-3 py-2 text-sm dark:border-gray-700/60">
                                        <span class="text-gray-800 dark:text-gray-100">{{ $item['label'] }}</span>
                                        <span class="text-gray-600 dark:text-gray-300">{{ number_format($item['sessions']) }} sessions</span>
                                    </div>
                                @empty
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No delivery data available.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">By teacher</h3>
                        @if (empty($teacherBreakdown))
                            <p class="text-sm text-gray-500 dark:text-gray-400">No teacher breakdown available.</p>
                        @else
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($teacherBreakdown as $item)
                                    <div class="flex items-center justify-between rounded-xl border border-gray-200/70 bg-white px-3 py-3 text-sm shadow-sm dark:border-gray-700/60 dark:bg-gray-900/40">
                                        <div class="flex flex-col">
                                            <span class="font-semibold text-gray-800 dark:text-gray-100">{{ $item['label'] }}</span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Sessions in view</span>
                                        </div>
                                        <span class="text-base font-semibold text-gray-700 dark:text-gray-200">{{ number_format($item['sessions']) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </x-filament::card>

        @if (! $isUkManager)
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-filament::card>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Sessions needing attention</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Lowest attendance in the current selection.</p>
                <div class="mt-4 space-y-3">
                    @forelse ($lowAttendance as $row)
                        <div class="rounded-lg border border-amber-200/70 bg-amber-50 px-3 py-2 text-sm dark:border-amber-500/40 dark:bg-amber-500/10">
                            <div class="flex items-center justify-between">
                                <span class="font-semibold text-amber-800 dark:text-amber-200">{{ optional($row->session_date_carbon)->format('d M Y') ?? $row->session_date }}</span>
                                <span class="text-amber-700 dark:text-amber-200">{{ number_format($row->attendance_rate ?? 0, 1) }}%</span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-amber-800/80 dark:text-amber-200/80">
                                <span>{{ $row->calendar_label }}</span>
                                @if ($row->appointment_type)
                                    <span>• {{ $row->appointment_type }}</span>
                                @endif
                                <span>• P {{ $row->present_count }}/L {{ $row->late_count }}/A {{ $row->absent_count }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">No sessions below the threshold.</p>
                    @endforelse
                </div>
            </x-filament::card>

            <x-filament::card>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Pending follow-ups</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Sessions with students still awaiting absence emails.</p>
                <div class="mt-4 space-y-3">
                    @forelse ($followUps as $row)
                        <div class="rounded-lg border border-sky-200/70 bg-sky-50 px-3 py-2 text-sm dark:border-sky-500/40 dark:bg-sky-500/10">
                            <div class="flex items-center justify-between">
                                <span class="font-semibold text-sky-800 dark:text-sky-200">{{ optional($row->session_date_carbon)->format('d M Y') ?? $row->session_date }}</span>
                                <span class="text-sky-700 dark:text-sky-200">{{ $row->pending_absence_count }} pending</span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-sky-800/80 dark:text-sky-200/80">
                                <span>{{ $row->calendar_label }}</span>
                                @if ($row->appointment_type)
                                    <span>• {{ $row->appointment_type }}</span>
                                @endif
                                <span>• Teacher: {{ $row->teacher_label }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">No outstanding absence notifications.</p>
                    @endforelse
                </div>
            </x-filament::card>
        </div>
        <x-filament::card>
            <div class="mb-4 flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Session spotlight</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Detailed attendance records for UK sessions in scope.</p>
                </div>
                <div wire:loading.flex class="items-center gap-2 text-sm text-primary-600 dark:text-primary-400">
                    <x-filament::loading-indicator class="h-4 w-4" /> Recalculating…
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200/70 dark:border-gray-700/60">
                <table class="w-full table-fixed divide-y divide-gray-200 text-sm dark:divide-gray-700/70">
                    <colgroup>
                        <col style="width: 15%" />
                        <col style="width: 16%" />
                        <col style="width: 15%" />
                        <col style="width: 14%" />
                        <col style="width: 10%" />
                        <col style="width: 10%" />
                        <col style="width: 10%" />
                        <col style="width: 10%" />
                    </colgroup>
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Session</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Calendar</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Appointment</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Teacher / Delivery</th>
                            <th class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">P / L / A</th>
                            <th class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Attendance</th>
                            <th class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Pending</th>
                            <th class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700/70">
                        @forelse ($sessions as $row)
                            <tr class="bg-white/60 dark:bg-white/5">
                                <td class="px-3 py-3 text-sm text-gray-800 dark:text-gray-100">
                                    <div class="font-semibold">{{ optional($row->session_date_carbon)->format('d M Y') ?? $row->session_date }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row->start_time }} – {{ $row->end_time }}</div>
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-800 dark:text-gray-100">
                                    {{ $row->calendar_label }}
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-800 dark:text-gray-100">
                                    {{ $row->appointment_type ?? '—' }}
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-800 dark:text-gray-100">
                                    <div>{{ $row->teacher_label }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row->delivery_label }}</div>
                                </td>
                                <td class="px-3 py-3 text-center text-sm text-gray-800 dark:text-gray-100">
                                    <span class="inline-flex items-center justify-center rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                        {{ $row->present_count }} / {{ $row->late_count }} / {{ $row->absent_count }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-center text-sm text-gray-800 dark:text-gray-100">
                                    @if (!is_null($row->attendance_rate))
                                        <span class="inline-flex items-center justify-center rounded-full bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-200">
                                            {{ number_format($row->attendance_rate, 1) }}%
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-center text-sm text-gray-800 dark:text-gray-100">
                                    @if ($row->pending_absence_count > 0)
                                        <span class="inline-flex items-center justify-center rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-200">
                                            {{ $row->pending_absence_count }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-500 dark:text-gray-400">0</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right text-sm text-gray-800 dark:text-gray-100">
                                    <x-filament::button
                                        size="xs"
                                        color="gray"
                                        icon="heroicon-m-arrow-top-right-on-square"
                                        tag="a"
                                        :href="route('filament.admin.pages.take-attendance', ['sessionId' => $row->session_id])"
                                    >
                                        View
                                    </x-filament::button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No sessions match the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3">
                {{ $sessions->links() }}
            </div>
        </x-filament::card>
        @endif
    </div>
</x-filament::page>
