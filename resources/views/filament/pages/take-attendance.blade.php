
<x-filament::page>
    <style>
        .attendance-icon-emerald svg { color: #047857; }
        .attendance-icon-amber svg { color: #B45309; }
        .attendance-icon-rose svg { color: #B91C1C; }

        .attendance-status-present {
            background-color: #047857 !important;
            border-color: #065f46 !important;
            color: #ffffff !important;
        }

        .attendance-status-late {
            background-color: #B45309 !important;
            border-color: #92400E !important;
            color: #ffffff !important;
        }

        .attendance-status-absent {
            background-color: #B91C1C !important;
            border-color: #991B1B !important;
            color: #ffffff !important;
        }
    </style>
    @php
        $meta = $this->sessionMeta();
    @endphp
        <div class="space-y-4">
        <x-filament::card class="w-full">
            @if(!$meta)
                <div class="text-sm text-rose-600">Invalid session or not a UK session.</div>
            @else
                @php
                    $sessionDate = optional($meta->session_date ? \Illuminate\Support\Carbon::parse($meta->session_date) : null)->format('d-m-Y');
                    try {
                        $startTime = \Illuminate\Support\Carbon::createFromFormat('H:i:s', $meta->start_time)->format('H:i');
                    } catch (\Throwable $e) {
                        $startTime = substr((string) $meta->start_time, 0, 5);
                    }
                    try {
                        $endTime = \Illuminate\Support\Carbon::createFromFormat('H:i:s', $meta->end_time)->format('H:i');
                    } catch (\Throwable $e) {
                        $endTime = substr((string) $meta->end_time, 0, 5);
                    }
                    $timeRange = trim($startTime . ' - ' . $endTime);
                    $payload = is_string($meta->acuity_data) ? json_decode($meta->acuity_data, true) : (array) ($meta->acuity_data ?? []);
                    $appointmentType = data_get($payload, 'type')
                        ?? data_get($payload, 'appointmentType')
                        ?? data_get($payload, 'appointmentType.name');
                    $headerPieces = collect([
                        $meta->calendar_name ?: null,
                        $meta->location ?: null,
                        $appointmentType ?: null,
                    ])->filter()->all();
                    $headerLine = empty($headerPieces) ? null : implode(' - ', $headerPieces);
                    $headerText = $headerLine ?: null;
                @endphp

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <div class="text-base font-semibold text-gray-900">Date: {{ $sessionDate ?? '—' }}</div>
                        @if($headerText)
                            <div class="text-sm text-gray-600">{{ $headerText }}</div>
                        @endif
                    </div>

                    @if(auth()->user()?->hasRole('admin', 'super_admin'))
                        @php
                            $routeName = str_starts_with(request()->route()->getName(), 'filament.portal')
                                ? 'filament.portal.resources.u-k-upcomings.manage'
                                : 'filament.admin.resources.u-k-upcomings.manage';
                            $url = route($routeName, ['record' => $meta->id]);
                        @endphp
                        <x-filament::button tag="a" color="gray" icon="heroicon-o-user-group" href="{{ $url }}" target="_blank">
                            Manage cover teacher
                        </x-filament::button>
                    @endif
                </div>

            @endif
        </x-filament::card>

        <x-filament::card class="max-w-none">
            @if($meta)
                @php
                    $roster = $this->roster();
                @endphp
                @if(empty($roster))
                    <div class="text-sm text-gray-600">No students matched this session. Ensure students are linked by email.</div>
                @else
                    @php
                        $total = count($roster);
                        $present = $this->presentCount;
                        $late = $this->lateCount;
                        $absent = $this->absentCount;
                        $attended = $present + $late;
                        $marked = $present + $late + $absent;
                        $overall = ($total && $marked === $total) ? round(($attended / $total) * 100, 1) : null;
                    @endphp

                    <div class="flex flex-col gap-4">
                        {{-- ── Stats bar / collapse toggle ──────────────────────────── --}}
                        <div
                            wire:click="toggleRoster"
                            class="flex cursor-pointer flex-wrap items-center justify-between gap-4 rounded-lg bg-gray-50 px-4 py-3 transition-colors hover:bg-gray-100 select-none"
                        >
                            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-700">
                                <span>Marked: <span class="font-semibold text-gray-900">{{ $marked }}/{{ $total }}</span></span>
                                <span>Total students: <span class="font-semibold text-gray-900">{{ $total }}</span></span>
                                <span>Present: <span class="font-semibold text-emerald-600">{{ $present }}</span></span>
                                <span>Late: <span class="font-semibold text-amber-600">{{ $late }}</span></span>
                                <span>Absent: <span class="font-semibold text-rose-600">{{ $absent }}</span></span>
                            </div>
                            <div class="flex items-center gap-3">
                                @if(!is_null($overall))
                                    <div class="text-lg font-semibold text-gray-500">{{ number_format($overall, 1) }}% attendance</div>
                                @else
                                    <div class="text-sm font-medium text-gray-500">Attendance % updates once everyone is marked.</div>
                                @endif
                                <x-heroicon-o-chevron-down class="h-4 w-4 text-gray-400 transition-transform {{ $this->rosterExpanded ? 'rotate-180' : '' }}" />
                            </div>
                        </div>

                        @if($this->rosterExpanded)

                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm text-gray-500">Mark each learner with their status below, or use quick actions.</div>
                            <div class="flex flex-wrap gap-2">
                                <x-filament::button size="sm" icon="heroicon-o-check-circle" wire:click="markAll('present')" wire:loading.attr="disabled" wire:target="markAll,save,submit">
                                    Mark all present
                                </x-filament::button>
                                <x-filament::button size="sm" color="danger" icon="heroicon-o-x-circle" wire:click="markAll('absent')" wire:loading.attr="disabled" wire:target="markAll,save,submit">
                                    Mark all absent
                                </x-filament::button>
                            </div>
                        </div>

                        <div class="space-y-3 md:hidden">
                            @foreach($roster as $row)
                                @php
                                    $current = $this->statuses[$row['id']] ?? null;
                                    $statusLabel = $current ? ucfirst($current) : 'Not marked';
                                    $statusStyles = match($current) {
                                        'present' => 'attendance-status-present border',
                                        'late' => 'attendance-status-late border',
                                        'absent' => 'attendance-status-absent border',
                                        default => 'bg-gray-100 text-gray-600 border border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700',
                                    };
                                    $iconBase = 'inline-flex h-10 w-10 items-center justify-center rounded-full border transition focus:outline-none focus:ring-2 focus:ring-offset-1';
                                    $presentBtn = $current === 'present'
                                        ? $iconBase.' border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700 focus:ring-emerald-500 dark:bg-emerald-500 attendance-icon-emerald'
                                        : $iconBase.' border-emerald-200 text-emerald-600 hover:border-emerald-300 hover:bg-emerald-50 focus:ring-emerald-200 dark:bg-gray-900/40 dark:text-emerald-300 attendance-icon-emerald';
                                    $lateBtn = $current === 'late'
                                        ? $iconBase.' border-amber-500 bg-amber-500 text-white hover:bg-amber-600 focus:ring-amber-500 dark:bg-amber-400 attendance-icon-amber'
                                        : $iconBase.' border-amber-200 text-amber-600 hover:border-amber-300 hover:bg-amber-50 focus:ring-amber-200 dark:bg-gray-900/40 dark:text-amber-300 attendance-icon-amber';
                                    $absentBtn = $current === 'absent'
                                        ? $iconBase.' border-rose-600 bg-rose-600 text-white hover:bg-rose-700 focus:ring-rose-500 dark:bg-rose-500 attendance-icon-rose'
                                        : $iconBase.' border-rose-200 text-rose-600 hover-border-rose-300 hover:bg-rose-50 focus:ring-rose-200 dark:bg-gray-900/40 dark:text-rose-300 attendance-icon-rose';
                                @endphp
                                <div class="rounded-xl border border-gray-200 p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="text-base font-semibold text-gray-900">
                                            @if(!empty($row['id']))
                                                @php
                                                    $user = auth()->user();
                                                    $isTeacher = $user && in_array(strtolower((string) ($user->role ?? '')), \App\Models\User::TEACHING_ROLES, true);
                                                    $studentUrl = $isTeacher
                                                        ? route('filament.portal.pages.student-profile', ['record' => $row['id']])
                                                        : route('filament.admin.resources.u-k-students.view', $row['id']);
                                                @endphp
                                                <a
                                                    href="{{ $studentUrl }}"
                                                    class="text-primary-600 transition hover:text-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500/60"
                                                >
                                                    {{ $row['name'] }}
                                                </a>
                                            @else
                                                {{ $row['name'] }}
                                            @endif
                                        </div>
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap {{ $statusStyles }}">{{ $statusLabel }}</span>
                                    </div>
                                    <div class="mt-3 flex items-center justify-start gap-3">
                                        <button type="button" class="{{ str_replace('h-10 w-10', 'h-9 w-9', $presentBtn) }}" wire:click="setStatus({{ $row['id'] }}, 'present')" wire:loading.attr="disabled" wire:target="setStatus,markAll,save,submit" aria-label="Mark present">
                                            <x-filament::icon icon="heroicon-m-check" class="h-5 w-5" />
                                        </button>
                                        <button type="button" class="{{ str_replace('h-10 w-10', 'h-9 w-9', $lateBtn) }}" wire:click="setStatus({{ $row['id'] }}, 'late')" wire:loading.attr="disabled" wire:target="setStatus,markAll,save,submit" aria-label="Mark late">
                                            <x-filament::icon icon="heroicon-m-clock" class="h-5 w-5" />
                                        </button>
                                        <button type="button" class="{{ str_replace('h-10 w-10', 'h-9 w-9', $absentBtn) }}" wire:click="setStatus({{ $row['id'] }}, 'absent')" wire:loading.attr="disabled" wire:target="setStatus,markAll,save,submit" aria-label="Mark absent">
                                            <x-filament::icon icon="heroicon-m-x-circle" class="h-5 w-5" />
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="overflow-x-auto w-full hidden md:block">
                        <div class="overflow-hidden rounded-xl border border-gray-200 shadow-sm">
                            <table class="w-full table-fixed divide-y divide-gray-200 text-sm min-w-[750px]">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">Student</th>
                                        <th scope="col" class="w-44 px-4 py-3 text-left font-semibold text-gray-700 hidden xl:table-cell">Email</th>
                                        <th scope="col" class="w-44 px-4 py-3 text-left font-semibold text-gray-700 hidden xl:table-cell">Manager Email</th>
                                        <th scope="col" class="w-32 px-4 py-3 text-left font-semibold text-gray-700">Selected Status</th>
                                        <th scope="col" class="w-24 px-4 py-3 text-left font-semibold text-gray-700">Update</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($roster as $row)
                                        @php
                                            $current = $this->statuses[$row['id']] ?? null;
                                            $statusLabel = $current ? ucfirst($current) : 'Not marked';
                                            $statusStyles = match($current) {
                                                'present' => 'attendance-status-present border',
                                                'late' => 'attendance-status-late border',
                                                'absent' => 'attendance-status-absent border',
                                                default => 'bg-gray-100 text-gray-600 border border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700',
                                            };
                                            $iconBase = 'inline-flex h-8 w-8 items-center justify-center rounded-full border transition focus:outline-none focus:ring-2 focus:ring-offset-1';
                                            $presentBtn = $current === 'present'
                                                ? $iconBase.' border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700 focus:ring-emerald-500 dark:bg-emerald-500 attendance-icon-emerald'
                                                : $iconBase.' border-emerald-200 text-emerald-600 hover:border-emerald-300 hover:bg-emerald-50 focus:ring-emerald-200 dark:bg-gray-900/40 dark:text-emerald-300 attendance-icon-emerald';
                                            $lateBtn = $current === 'late'
                                                ? $iconBase.' border-amber-500 bg-amber-500 text-white hover:bg-amber-600 focus:ring-amber-500 dark:bg-amber-400 attendance-icon-amber'
                                                : $iconBase.' border-amber-200 text-amber-600 hover:border-amber-300 hover:bg-amber-50 focus:ring-amber-200 dark:bg-gray-900/40 dark:text-amber-300 attendance-icon-amber';
                                            $absentBtn = $current === 'absent'
                                                ? $iconBase.' border-rose-600 bg-rose-600 text-white hover:bg-rose-700 focus:ring-rose-500 dark:bg-rose-500 attendance-icon-rose'
                                                : $iconBase.' border-rose-200 text-rose-600 hover:border-rose-300 hover:bg-rose-50 focus:ring-rose-200 dark:bg-gray-900/40 dark:text-rose-300 attendance-icon-rose';
                                        @endphp
                                        <tr wire:key="student-{{ $row['id'] }}">
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                                @if(!empty($row['id']))
                                                    @php
                                                        $user = auth()->user();
                                                        $isTeacher = $user && in_array(strtolower((string) ($user->role ?? '')), \App\Models\User::TEACHING_ROLES, true);
                                                        $studentUrl = $isTeacher
                                                            ? route('filament.portal.pages.student-profile', ['record' => $row['id']])
                                                            : route('filament.admin.resources.u-k-students.view', $row['id']);
                                                    @endphp
                                                    <a
                                                        href="{{ $studentUrl }}"
                                                        class="text-primary-600 transition hover:text-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500/60"
                                                    >
                                                        {{ $row['name'] }}
                                                    </a>
                                                @else
                                                    {{ $row['name'] }}
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 hidden xl:table-cell" title="{{ $row['email'] ?? '' }}">
                                                <span class="block truncate text-xs text-gray-600">{{ $row['email'] ?? '—' }}</span>
                                            </td>
                                            <td class="px-4 py-3 hidden xl:table-cell" title="{{ $row['manager_email'] ?? '' }}">
                                                <span class="block truncate text-xs text-gray-600">{{ $row['manager_email'] ?? '—' }}</span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-600">
                                                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $statusStyles }}">{{ $statusLabel }}</span>
                                            </td>
                                            <td class="px-4 py-3 w-24">
                                                <div class="flex flex-nowrap items-center gap-2">
                                                    <button
                                                        type="button"
                                                        class="{{ $presentBtn }}"
                                                        wire:click="setStatus({{ $row['id'] }}, 'present')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="setStatus,markAll,save,submit"
                                                        aria-label="Mark present"
                                                    >
                                                        <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="{{ $lateBtn }}"
                                                        wire:click="setStatus({{ $row['id'] }}, 'late')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="setStatus,markAll,save,submit"
                                                        aria-label="Mark late"
                                                    >
                                                        <x-filament::icon icon="heroicon-m-clock" class="h-4 w-4" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="{{ $absentBtn }}"
                                                        wire:click="setStatus({{ $row['id'] }}, 'absent')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="setStatus,markAll,save,submit"
                                                        aria-label="Mark absent"
                                                    >
                                                        <x-filament::icon icon="heroicon-m-x-circle" class="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="space-y-0.5">
                                <div class="text-xs text-gray-500">Save keeps the marks without emailing managers. Send notifies managers of absences.</div>
                                @if($emailSentAt)
                                    <div class="text-xs text-amber-600 font-medium">
                                        Emails sent on {{ $emailSentAt->format('d-m-Y H:i') }} — cannot send again.
                                    </div>
                                @endif
                            </div>
                            <div class="flex gap-2">
                                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="save" wire:loading.attr="disabled" wire:target="save,submit,markAll,setStatus">
                                    Save attendance
                                </x-filament::button>
                                @if($emailSentAt)
                                    <x-filament::button color="gray" icon="heroicon-m-paper-airplane" disabled title="Emails already sent on {{ $emailSentAt->format('d-m-Y H:i') }}">
                                        Send
                                    </x-filament::button>
                                @else
                                    <x-filament::button color="primary" icon="heroicon-m-paper-airplane" wire:click="submit" wire:loading.attr="disabled" wire:target="submit,save,markAll,setStatus">
                                        Send
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>

                        @endif {{-- rosterExpanded --}}
                    </div>
                @endif
            @endif
        </x-filament::card>
        @php
            $authUser       = auth()->user();
            $canSeeAudit    = $authUser?->hasRole('super_admin', 'admin', 'head_teacher');
            $isBasicTeacher = $authUser?->hasRole('teacher');
        @endphp

        {{-- Basic "last saved" note — regular teachers only --}}
        @if($isBasicTeacher && $meta && (($latestSaveAudit ?? null) || ($latestSubmitAudit ?? null)))
            <x-filament::card class="w-full">
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-600">
                    @if($latestSaveAudit ?? null)
                        <span>Last saved by <span class="font-medium text-gray-800">{{ $latestSaveAudit['user'] }}</span> at {{ $latestSaveAudit['formatted'] }}</span>
                    @endif
                    @if($latestSubmitAudit ?? null)
                        <span>Last submitted by <span class="font-medium text-gray-800">{{ $latestSubmitAudit['user'] }}</span> at {{ $latestSubmitAudit['formatted'] }}</span>
                    @endif
                </div>
            </x-filament::card>
        @endif

        @if($canSeeAudit && $meta && (($latestSaveAudit ?? null) || ($latestSubmitAudit ?? null)))
            <x-filament::card class="w-full">
                <div class="grid gap-3 text-sm text-gray-700 md:grid-cols-2">
                    @if($latestSaveAudit)
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Last saved</p>
                            <p class="font-medium text-gray-900">{{ $latestSaveAudit['user'] }}</p>
                            <p class="text-xs text-gray-500">{{ $latestSaveAudit['formatted'] }}</p>
                        </div>
                    @endif
                    @if($latestSubmitAudit)
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Last submitted</p>
                            <p class="font-medium text-gray-900">{{ $latestSubmitAudit['user'] }}</p>
                            <p class="text-xs text-gray-500">{{ $latestSubmitAudit['formatted'] }}</p>
                        </div>
                    @endif
                </div>

                @if(!empty($saveAuditHistory) || !empty($submitAuditHistory))
                    <details class="gap-3 mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 space-y-4" style="margin-top:30px;">
                        <summary class="cursor-pointer font-semibold text-gray-800">View full history</summary>
                        <div class="mt-3 grid gap-4 md:grid-cols-2">
                            @if(!empty($saveAuditHistory))
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Save history</p>
                                    <ul class="mt-2 space-y-2">
                                        @foreach($saveAuditHistory as $entry)
                                            <li class="rounded border border-gray-200 px-3 py-2">
                                                <p class="font-medium text-gray-900">{{ $entry['user'] }}</p>
                                                <p class="text-xs text-gray-500">{{ $entry['formatted'] }}</p>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            @if(!empty($submitAuditHistory))
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Submission history</p>
                                    <ul class="mt-2 space-y-2">
                                        @foreach($submitAuditHistory as $entry)
                                            <li class="rounded border border-gray-200 px-3 py-2">
                                                <p class="font-medium text-gray-900">{{ $entry['user'] }}</p>
                                                <p class="text-xs text-gray-500">{{ $entry['formatted'] }}</p>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </details>
                @endif
            </x-filament::card>
        @endif

        {{-- ── Attendance log (admin / head_teacher only) ───────────────────── --}}
        @if($canSeeAuditLog && $attendanceLogs->isNotEmpty())
            <x-filament::card class="w-full">
                {{-- Clickable header toggle --}}
                <div
                    wire:click="toggleLog"
                    class="flex cursor-pointer items-center gap-2 select-none transition-colors hover:text-gray-600"
                >
                    <x-heroicon-o-clipboard-document-list class="h-4 w-4 text-gray-400" />
                    <h3 class="text-sm font-semibold text-gray-800">Attendance Log</h3>
                    <span class="ml-1 text-xs text-gray-400">{{ $attendanceLogs->count() }} entries</span>
                    <x-heroicon-o-chevron-down class="ml-auto h-4 w-4 text-gray-400 transition-transform {{ $this->logExpanded ? 'rotate-180' : '' }}" />
                </div>

                @if($this->logExpanded)
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[600px] text-xs">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">When</th>
                                <th class="py-2 pr-4 font-medium">Who</th>
                                <th class="py-2 pr-4 font-medium">Role</th>
                                <th class="py-2 pr-4 font-medium">Action</th>
                                <th class="py-2 pr-4 font-medium">Student</th>
                                <th class="py-2 font-medium">Status change</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($attendanceLogs as $log)
                                @php
                                    $actionStyles = match($log->action) {
                                        'marked'     => 'bg-blue-50 text-blue-700',
                                        'updated'    => 'bg-amber-50 text-amber-700',
                                        'email_sent' => 'bg-emerald-50 text-emerald-700',
                                        default      => 'bg-gray-50 text-gray-600',
                                    };
                                    $statusColor = fn(?string $s) => match($s) {
                                        'present' => 'text-emerald-600',
                                        'late'    => 'text-amber-600',
                                        'absent'  => 'text-rose-600',
                                        default   => 'text-gray-400',
                                    };
                                @endphp
                                <tr class="text-gray-700">
                                    <td class="py-2 pr-4 whitespace-nowrap text-gray-500">
                                        {{ $log->performed_at?->format('d-m-Y H:i') ?? '—' }}
                                    </td>
                                    <td class="py-2 pr-4 whitespace-nowrap">
                                        {{ $log->user?->name ?? ('User #' . $log->user_id) }}
                                    </td>
                                    <td class="py-2 pr-4 whitespace-nowrap text-gray-500">
                                        {{ $log->user_role }}
                                    </td>
                                    <td class="py-2 pr-4 whitespace-nowrap">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $actionStyles }}">
                                            {{ $log->action }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if($log->student)
                                            {{ $log->student->first_name }} {{ $log->student->last_name }}
                                        @else
                                            <span class="text-gray-400">Student #{{ $log->student_id }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 whitespace-nowrap">
                                        @if($log->action === 'email_sent')
                                            <span class="text-emerald-600">email queued</span>
                                        @elseif($log->old_status && $log->new_status)
                                            <span class="{{ $statusColor($log->old_status) }}">{{ $log->old_status }}</span>
                                            <span class="mx-1 text-gray-400">→</span>
                                            <span class="{{ $statusColor($log->new_status) }} font-semibold">{{ $log->new_status }}</span>
                                        @elseif($log->new_status)
                                            <span class="{{ $statusColor($log->new_status) }} font-semibold">{{ $log->new_status }}</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif {{-- logExpanded --}}
            </x-filament::card>
        @endif
    </div>
</x-filament::page>
