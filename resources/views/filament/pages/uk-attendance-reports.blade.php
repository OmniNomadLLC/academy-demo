<x-filament::page class="mx-auto w-full max-w-screen-2xl">
    <div class="space-y-4">
        <x-filament::card>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 md:items-end">
                <div>
                    <label class="fi-input-label">From</label>
                    <x-filament::input type="date" wire:model.defer="fromDate" />
                </div>
                <div>
                    <label class="fi-input-label">To</label>
                    <x-filament::input type="date" wire:model.defer="toDate" />
                </div>
                <div>
                    <label class="fi-input-label">Calendar</label>
                    <select class="fi-input block w-full rounded-lg border-gray-300" wire:model.defer="calendar">
                        <option value="">All calendars</option>
                        @foreach($this->availableUkCalendars() as $cal => $label)
                            <option value="{{ $cal }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="fi-input-label">Attendance band</label>
                    <select class="fi-input block w-full rounded-lg border-gray-300" wire:model.defer="attendanceBand">
                        <option value="">All</option>
                        <option value="lt75">&lt; 75%</option>
                        <option value="75to89">75% – 89%</option>
                        <option value="gte90">≥ 90%</option>
                    </select>
                </div>
                <div>
                    <label class="fi-input-label">Email status</label>
                    <select class="fi-input block w-full rounded-lg border-gray-300" wire:model.defer="emailStatus">
                        <option value="">All</option>
                        <option value="unsent">Needs emails</option>
                        <option value="sent">Emails sent</option>
                        <option value="none">No emails</option>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label class="fi-input-label">Only with absences</label>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex h-10 items-center">
                            <input type="checkbox" wire:model.defer="onlyAbsences" class="rounded border-gray-300" />
                        </div>
                        <div class="flex gap-2 justify-end">
                            <x-filament::button color="gray" outlined wire:click="resetFilters" icon="heroicon-m-arrow-path">Reset</x-filament::button>
                            <x-filament::button wire:click="applyFilters" icon="heroicon-m-magnifying-glass">Apply</x-filament::button>
                        </div>
                    </div>
                </div>
            </div>
        </x-filament::card>
        <x-filament::card>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <div class="text-lg font-semibold">Recent Attendance Submissions (UK)</div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Latest 100 submissions from UK sessions with attendance activity.</p>
                </div>
                <div wire:loading.flex class="items-center gap-2 text-sm text-primary-600 dark:text-primary-400">
                    <x-filament::loading-indicator class="h-4 w-4" /> Refreshing…
                </div>
            </div>
            @php
                $reports = $this->reports();
            @endphp
            <div class="overflow-x-auto rounded-xl border border-gray-200 shadow-sm dark:border-gray-700">
                <table class="w-full min-w-[700px] table-fixed text-sm">
                    <colgroup>
                        <col class="w-[120px]">
                        <col>
                        <col class="w-[110px]">
                        <col class="w-[70px]">
                        <col class="w-[60px]">
                        <col class="w-[70px]">
                        <col class="w-[130px]">
                    </colgroup>
                    <thead class="sticky top-0 z-10 border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-white/5">
                        <tr>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Session</th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Appointment Type</th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Calendar</th>
                            <th class="whitespace-nowrap px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Rate</th>
                            <th class="whitespace-nowrap px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Emails</th>
                            <th class="whitespace-nowrap px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Sent?</th>
                            <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                        @forelse($reports as $r)
                            @php
                                $sessionDate = $r->session_date ? \Illuminate\Support\Carbon::parse($r->session_date)->format('d-m-Y') : '—';
                                $startTime = $r->start_time ? \Illuminate\Support\Carbon::parse($r->start_time)->format('H:i') : '—';
                                $endTime = $r->end_time ? \Illuminate\Support\Carbon::parse($r->end_time)->format('H:i') : '—';
                                $attendanceRate = $r->attendance_rate;
                                $sentAny = (int) $r->c_sent > 0;
                                $canSendAbsent = ((int) ($r->c_absent_unsent ?? 0)) > 0;
                                $rateBand = is_null($attendanceRate) ? null
                                    : ($attendanceRate >= 90 ? 'green'
                                    : ($attendanceRate >= 75 ? 'amber' : 'red'));

                                $rawType = $r->appointment_type ?? '';
                                $typeParts = preg_split('/\s*[-–—]\s*/', $rawType, 2);
                                $typeName = trim($typeParts[0] ?? '') ?: '—';
                                $typeDetail = trim($typeParts[1] ?? '');
                            @endphp
                            @php $isAssessmentRow = (int) ($r->is_assessment ?? 0) === 1; @endphp
                            <tr @class([
                                'transition-colors',
                                'bg-white even:bg-gray-50/30 hover:bg-gray-50 dark:bg-gray-900 dark:even:bg-white/[0.02] dark:hover:bg-white/5' => ! $isAssessmentRow,
                                'bg-purple-100 hover:bg-purple-200 dark:bg-purple-900/20 dark:hover:bg-purple-900/30' => $isAssessmentRow,
                            ])
                                @if ($isAssessmentRow) title="Assessment — excluded from attendance averages" @endif
                            >
                                {{-- Session --}}
                                <td class="px-4 py-3">
                                    <div class="whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{{ $sessionDate }}</div>
                                    <div class="whitespace-nowrap text-xs text-gray-400 dark:text-gray-500">{{ $startTime }}–{{ $endTime }}</div>
                                </td>
                                {{-- Appointment Type --}}
                                <td class="px-4 py-3">
                                    <div class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200" title="{{ $rawType }}">{{ $typeName }}</div>
                                    @if ($typeDetail)
                                        <div class="max-w-[220px] truncate text-xs text-gray-400 dark:text-gray-500" title="{{ $typeDetail }}">{{ $typeDetail }}</div>
                                    @endif
                                </td>
                                {{-- Calendar --}}
                                <td class="px-4 py-3">
                                    <div class="truncate text-sm text-gray-700 dark:text-gray-300" title="{{ $r->calendar_name ?? '' }}">{{ $r->calendar_name ?? '—' }}</div>
                                </td>
                                {{-- Rate --}}
                                <td class="px-4 py-3 text-center">
                                    @if ($rateBand)
                                        @php
                                            $pillClasses = match($rateBand) {
                                                'green' => 'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-400',
                                                'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
                                                'red'   => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $pillClasses }}">
                                            {{ number_format($attendanceRate, 0) }}%
                                        </span>
                                    @else
                                        <span class="text-sm text-gray-300 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                                {{-- Emails --}}
                                <td class="px-4 py-3 text-center text-sm text-gray-600 dark:text-gray-400">
                                    {{ $r->c_sent }}
                                </td>
                                {{-- Sent? --}}
                                <td class="px-4 py-3 text-center">
                                    @if ($sentAny)
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-green-600 dark:text-green-400">
                                            <x-heroicon-m-check-circle class="h-3.5 w-3.5" /> Yes
                                        </span>
                                    @else
                                        <span class="text-sm text-gray-300 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                                {{-- Actions --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($canSendAbsent)
                                            <button
                                                type="button"
                                                wire:click="sendAbsentEmails({{ $r->sid }})"
                                                wire:loading.attr="disabled"
                                                wire:target="sendAbsentEmails"
                                                class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 transition hover:bg-red-100 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20"
                                            >
                                                <x-heroicon-m-paper-airplane class="h-3 w-3" /> Send
                                            </button>
                                        @endif
                                        <a
                                            href="{{ route('filament.admin.pages.take-attendance', ['sessionId' => $r->sid]) }}"
                                            class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-xs text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200"
                                        >
                                            <x-heroicon-m-arrow-top-right-on-square class="h-3 w-3" /> View
                                        </a>
                                        @if(auth()->user()?->hasRole('super_admin'))
                                            <button
                                                type="button"
                                                wire:click="deleteAttendanceRecords({{ $r->sid }})"
                                                wire:confirm="Are you sure you want to delete this attendance record? This will delete all attendance_records for this class_session. This cannot be undone."
                                                wire:loading.attr="disabled"
                                                wire:target="deleteAttendanceRecords"
                                                class="inline-flex h-6 w-6 items-center justify-center rounded-md text-gray-300 transition hover:bg-red-50 hover:text-red-500 dark:text-gray-600 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                                title="Delete attendance records"
                                            >
                                                <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center">
                                    <div class="text-sm text-gray-400 dark:text-gray-500">No attendance submissions found for the selected filters.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3">
                {{ $reports->links() }}
            </div>
        </x-filament::card>
    </div>
</x-filament::page>
