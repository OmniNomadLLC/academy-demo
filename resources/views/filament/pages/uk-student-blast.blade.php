<x-filament::page>
    <div class="space-y-4">
        <x-filament::card class="space-y-4">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <h3 class="text-base font-semibold text-primary-600 dark:text-primary-400">Attendance-based filters</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Filter by timeframe, attendance statuses, and optional calendar / appointment type.</p>
                </div>
                <button type="button" class="inline-flex items-center justify-center rounded-full border border-transparent p-1 text-primary-600 hover:text-primary-800 focus:outline-none focus:ring-2 focus:ring-primary-500" wire:click="toggleAttendanceFilters" aria-expanded="{{ $attendanceFiltersOpen ? 'true' : 'false' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6 transition-transform duration-200 {{ $attendanceFiltersOpen ? 'rotate-180' : '' }}">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                    <span class="sr-only">Toggle attendance filters</span>
                </button>
            </div>
            @if ($attendanceFiltersOpen)
            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-3 items-end lg:grid-cols-12">
                    <div class="lg:col-span-3 space-y-2">
                        <label class="fi-input-label">Timeframe</label>
                        <select class="fi-input block w-full rounded-lg border-gray-300 py-2" wire:model="timeframe">
                            <option value="today">Today</option>
                            <option value="this_week">This week</option>
                            <option value="custom">Custom dates</option>
                        </select>
                    </div>
                    <div class="lg:col-span-6">
                        <div class="flex flex-col sm:flex-row sm:items-end sm:gap-3">
                            <div class="w-full space-y-2">
                                <label class="fi-input-label">From</label>
                                <x-filament::input type="date" wire:model="fromDate" class="py-2" />
                            </div>
                            <div class="w-full space-y-2">
                                <label class="fi-input-label">To</label>
                                <x-filament::input type="date" wire:model="toDate" class="py-2" />
                            </div>
                        </div>
                    </div>
                    <div class="lg:col-span-3 space-y-2">
                        <label class="fi-input-label">Attendance statuses</label>
                        <div class="flex flex-wrap gap-3 text-sm">
                            @foreach($this->statusOptions() as $value => $label)
                                <label class="inline-flex items-center gap-1">
                                    <input type="checkbox" class="rounded border-gray-300" wire:model="statuses" value="{{ $value }}" />
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="lg:col-span-6">
                        <div class="flex flex-col sm:flex-row sm:items-end sm:gap-3">
                            <div class="w-full space-y-2">
                                <label class="fi-input-label">Calendar (optional)</label>
                                <select class="fi-input block w-full rounded-lg border-gray-300 py-2" wire:model="calendar" wire:change="$refresh">
                                    <option value="">All</option>
                                    @foreach($this->availableUkCalendars() as $cal => $label)
                                        <option value="{{ $cal }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="w-full space-y-2">
                                <label class="fi-input-label">Appointment type (optional)</label>
                                <select class="fi-input block w-full rounded-lg border-gray-300 py-2" wire:model="appointmentType">
                                    <option value="">All appointment types</option>
                                    @foreach($appointmentTypeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                    <p class="text-xs text-gray-500">Attendance filters drive the existing absent/present outreach list.</p>
                    <x-filament::button type="button" color="primary" wire:click="applyAttendanceFilters" wire:loading.attr="disabled" icon="heroicon-m-funnel">Apply attendance filters</x-filament::button>
                </div>
                @if ($activeSource === 'attendance')
                    <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="text-xs uppercase font-semibold tracking-wide text-gray-500 dark:text-gray-400">Attendance recipients</p>
                                <p>{{ $matchingStudentCount }} student(s) matched across {{ $matchingAttendanceCount }} attendance record(s).</p>
                            </div>
                            <x-filament::button type="button" color="gray" size="sm" wire:click="toggleSummary" wire:loading.attr="disabled" :disabled="$matchingStudentCount === 0"
                                icon="heroicon-m-chart-bar">
                                {{ $showSummary ? 'Hide breakdown' : 'Show breakdown' }}
                            </x-filament::button>
                        </div>
                        @if ($showSummary && ! empty($matchingSummary['students']['list'] ?? []))
                            @php
                                $totalSessions = $matchingSummary['sessions']['total'] ?? 0;
                                $statusCounts = $matchingSummary['sessions']['status_counts'] ?? [];
                                $cards = [
                                    ['label' => 'Total sessions', 'value' => $totalSessions],
                                    ['label' => 'Present', 'value' => $statusCounts['present'] ?? 0],
                                    ['label' => 'Absent', 'value' => $statusCounts['absent'] ?? 0],
                                ];
                            @endphp
                            <div class="mt-4 grid grid-cols-1 gap-3 text-gray-700 dark:text-gray-200 sm:grid-cols-3">
                                @foreach ($cards as $card)
                                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 text-center shadow-sm dark:border-gray-700 dark:bg-gray-800">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                                        <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($card['value']) }}</div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-4">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Students in scope ({{ $matchingSummary['students']['total'] ?? 0 }})</h4>
                                <div class="mt-2 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                                    <table class="w-full min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-300">
                                            <tr>
                                                <th class="px-4 py-2 text-left">Student</th>
                                                <th class="px-4 py-2 text-left">Email</th>
                                                <th class="px-4 py-2 text-right">Sessions</th>
                                                <th class="px-4 py-2 text-right">Present</th>
                                                <th class="px-4 py-2 text-right">Late</th>
                                                <th class="px-4 py-2 text-right">Absent</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                            @foreach (($matchingSummary['students']['list'] ?? []) as $student)
                                                <tr class="text-gray-700 dark:text-gray-200">
                                                    <td class="px-4 py-2 font-medium">{{ $student['name'] }}</td>
                                                    <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ $student['email'] }}</td>
                                                    <td class="px-4 py-2 text-right">{{ number_format($student['sessions'] ?? 0) }}</td>
                                                    <td class="px-4 py-2 text-right">{{ number_format($student['present'] ?? 0) }}</td>
                                                    <td class="px-4 py-2 text-right">{{ number_format($student['late'] ?? 0) }}</td>
                                                    <td class="px-4 py-2 text-right">{{ number_format($student['absent'] ?? 0) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @if (! empty($matchingSummary['students']['truncated']))
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Showing first {{ count($matchingSummary['students']['list'] ?? []) }} students.</p>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>
            @endif
        </x-filament::card>

        <x-filament::card class="space-y-4">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <h3 class="text-base font-semibold text-primary-600 dark:text-primary-400">Upcoming class targeting</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Skip attendance logic and pull every student with an upcoming (next 45 days) class on a specific calendar or appointment type.</p>
                </div>
                <button type="button" class="inline-flex items-center justify-center rounded-full border border-transparent p-1 text-primary-600 hover:text-primary-800 focus:outline-none focus:ring-2 focus:ring-primary-500" wire:click="toggleUpcomingFilters" aria-expanded="{{ $upcomingFiltersOpen ? 'true' : 'false' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6 transition-transform duration-200 {{ $upcomingFiltersOpen ? 'rotate-180' : '' }}">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                    <span class="sr-only">Toggle upcoming filters</span>
                </button>
            </div>
            @if ($upcomingFiltersOpen)
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <div class="space-y-2">
                    <label class="fi-input-label">Calendar</label>
                    <select class="fi-input block w-full rounded-lg border-gray-300 py-2" wire:model="upcomingCalendar" wire:change="$refresh">
                        <option value="">Select calendar</option>
                        @foreach($this->availableUkCalendars() as $cal => $label)
                            <option value="{{ $cal }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="fi-input-label">Appointment type</label>
                    <select class="fi-input block w-full rounded-lg border-gray-300 py-2" wire:model="upcomingAppointmentType">
                        <option value="">Select appointment type</option>
                        @foreach($upcomingAppointmentTypeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between" style="padding-top:25px; padding-bottom:25px;">
                <p class="text-xs text-gray-500">Choose at least one option above to target future rosters without attendance filters.</p>
                <x-filament::button type="button" color="primary" wire:click="applyUpcomingFilters" wire:loading.attr="disabled" icon="heroicon-m-funnel">Apply upcoming filters</x-filiment::button>
            </div>
            @if ($activeSource === 'upcoming')
                <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-xs uppercase font-semibold tracking-wide text-gray-500 dark:text-gray-400">Upcoming recipients</p>
                            <p>{{ $matchingStudentCount }} student(s) matched across {{ $matchingAttendanceCount }} upcoming booking(s).</p>
                        </div>
                        <x-filament::button type="button" color="gray" size="sm" wire:click="toggleSummary" wire:loading.attr="disabled" :disabled="$matchingStudentCount === 0"
                            icon="heroicon-m-chart-bar">
                            {{ $showSummary ? 'Hide breakdown' : 'Show breakdown' }}
                        </x-filament::button>
                    </div>
                    @if ($showSummary && ! empty($matchingSummary['students']['list'] ?? []))
                        @php
                            $calendarCounts = array_slice($matchingSummary['sessions']['calendar_counts'] ?? [], 0, 5);
                            $appointmentCounts = array_slice($matchingSummary['sessions']['appointment_counts'] ?? [], 0, 5);
                        @endphp
                        <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 text-center shadow-sm dark:border-gray-700 dark:bg-gray-800">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Upcoming sessions</div>
                                <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($matchingSummary['sessions']['total'] ?? 0) }}</div>
                                <p class="mt-1 text-xs text-gray-500">Next 45 days</p>
                            </div>
                            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Top calendars</div>
                                <ul class="mt-2 space-y-1 text-sm">
                                    @forelse($calendarCounts as $calendar)
                                        <li class="flex items-center justify-between">
                                            <span>{{ $calendar['label'] }}</span>
                                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ number_format($calendar['count']) }}</span>
                                        </li>
                                    @empty
                                        <li class="text-xs text-gray-500">No calendar data</li>
                                    @endforelse
                                </ul>
                            </div>
                            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Top appointment types</div>
                                <ul class="mt-2 space-y-1 text-sm">
                                    @forelse($appointmentCounts as $type)
                                        <li class="flex items-center justify-between">
                                            <span>{{ $type['label'] }}</span>
                                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ number_format($type['count']) }}</span>
                                        </li>
                                    @empty
                                        <li class="text-xs text-gray-500">No appointment data</li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>
                        <div class="mt-4">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Students in scope ({{ $matchingSummary['students']['total'] ?? 0 }})</h4>
                            <div class="mt-2 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                                <table class="w-full min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-300">
                                        <tr>
                                            <th class="px-4 py-2 text-left">Student</th>
                                            <th class="px-4 py-2 text-left">Email</th>
                                            <th class="px-4 py-2 text-right">Sessions</th>
                                            <th class="px-4 py-2 text-left">Next session</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @foreach (($matchingSummary['students']['list'] ?? []) as $student)
                                            <tr class="text-gray-700 dark:text-gray-200">
                                                <td class="px-4 py-2 font-medium">{{ $student['name'] }}</td>
                                                <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ $student['email'] }}</td>
                                                <td class="px-4 py-2 text-right">{{ number_format($student['sessions'] ?? 0) }}</td>
                                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ $student['next_session'] ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if (! empty($matchingSummary['students']['truncated']))
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Showing first {{ count($matchingSummary['students']['list'] ?? []) }} students.</p>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
            @endif
        </x-filament::card>

        <x-filament::card>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div class="space-y-4">
                    <div class="space-y-2">
                        <label class="fi-input-label">Recipients</label>
                        <textarea class="fi-input block w-full rounded-lg border-gray-300 min-h-[120px] py-2" wire:model.defer="recipientList" readonly></textarea>
                    </div>
                    <div class="space-y-2">
                        <label class="fi-input-label">Subject</label>
                        <div class="rounded-lg border border-gray-300 shadow-sm">
                            <x-filament::input type="text" wire:model.defer="subject" class="py-2 border-0 shadow-none" />
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="fi-input-label">Message</label>
                        <div
                            class="space-y-2"
                            x-data="studentBlastEditor()"
                            x-init="init()"
                        >
                        <div class="flex flex-wrap gap-2 text-sm">
                            <button type="button" x-on:click.prevent="format('bold')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50 font-semibold" title="Bold"><span class="font-bold">B</span></button>
                            <button type="button" x-on:click.prevent="format('italic')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50 italic" title="Italic">I</button>
                            <button type="button" x-on:click.prevent="format('underline')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Underline"><span class="underline">U</span></button>
                            <button type="button" x-on:click.prevent="format('strikeThrough')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Strikethrough"><span class="line-through">S</span></button>
                            <button type="button" x-on:click.prevent="insertList('unordered')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Bullet list">• • •</button>
                            <button type="button" x-on:click.prevent="insertList('ordered')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Numbered list">1.2.3.</button>
                            <button type="button" x-on:click.prevent="formatBlock('blockquote')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Quote">“”</button>
                            <button type="button" x-on:click.prevent="formatBlock('p')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Paragraph">¶</button>
                            <button type="button" x-on:click.prevent="insertLink()" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Insert link">🔗</button>
                            <button type="button" x-on:click.prevent="clear()" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50 text-red-600" title="Clear formatting">Clear</button>
                        </div>
                        <div
                            x-ref="editor"
                            class="fi-input block w-full rounded-lg border-gray-300 min-h-[220px] p-3 bg-white focus:outline-none focus:ring-2 focus:ring-primary-500 student-blast-editor border border-gray-300"
                            contenteditable="true"
                            x-on:input="updateContent"
                            x-on:blur="sync"
                            x-on:paste="onPaste"
                        ></div>
                        <textarea x-ref="hiddenField" class="hidden" wire:model.defer="message"></textarea>
                    </div>
                </div>
            </div>
            <div class="mt-3 text-right">
                <x-filament::button color="primary" wire:click="send" wire:loading.attr="disabled" icon="heroicon-m-paper-airplane">Send</x-filament::button>
            </div>
        </x-filament::card>
    </div>
</x-filament::page>

@once
    @push('scripts')
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('studentBlastEditor', () => ({
                    init() {
                        const html = this.$refs.hiddenField?.value ?? '';
                        this.setEditorContent(html);
                        this.syncHidden();

                        Livewire.hook('message.processed', () => {
                            const updated = this.$refs.hiddenField?.value ?? '';
                            if (this.normalize(this.$refs.editor.innerHTML) !== this.normalize(updated)) {
                                this.setEditorContent(updated);
                                this.syncHidden();
                            }
                        });
                    },
                    setEditorContent(html) {
                        this.$refs.editor.innerHTML = html || '';
                    },
                    syncHidden() {
                        if (this.$refs.hiddenField) {
                            this.$refs.hiddenField.value = this.$refs.editor.innerHTML ?? '';
                        }
                    },
                    normalize(html) {
                        return (html || '').replace(/\s+/g, ' ').trim();
                    },
                    focusEditor() {
                        this.$refs.editor.focus({ preventScroll: true });
                    },
                    format(command, value = null) {
                        this.focusEditor();
                        document.execCommand(command, false, value);
                        this.afterInput();
                    },
                    formatBlock(tag) {
                        this.focusEditor();
                        const block = tag?.startsWith('<') ? tag : `<${tag}>`;
                        document.execCommand('formatBlock', false, block);
                        this.afterInput();
                    },
                    insertLink() {
                        const url = prompt('Enter URL');
                        if (! url) {
                            return;
                        }
                        this.focusEditor();
                        document.execCommand('createLink', false, url);
                        this.afterInput();
                    },
                    insertList(type) {
                        this.focusEditor();
                        if (type === 'ordered') {
                            document.execCommand('insertOrderedList');
                        } else {
                            document.execCommand('insertUnorderedList');
                        }
                        this.afterInput();
                    },
                    clear() {
                        this.$refs.editor.innerHTML = '';
                        this.afterInput();
                    },
                    updateContent() {
                        this.afterInput();
                    },
                    sync() {
                        this.afterInput();
                    },
                    onPaste(event) {
                        event.preventDefault();
                        const text = (event.clipboardData || window.clipboardData)?.getData('text/plain') ?? '';
                        this.focusEditor();
                        document.execCommand('insertText', false, text);
                        this.afterInput();
                    },
                    afterInput() {
                        const html = this.$refs.editor.innerHTML ?? '';
                        this.syncHidden();
                        if (this.$refs.hiddenField) {
                            const hidden = this.$refs.hiddenField;
                            hidden.value = html;
                            hidden.dispatchEvent(new Event('input', { bubbles: true }));
                            hidden.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    },
                }));
            });
        </script>
    @endpush
    @push('styles')
        <style>
            .student-blast-editor ul,
            .student-blast-editor ol {
                margin: 0.5rem 0 0.75rem 1.5rem;
                padding: 0;
            }

            .student-blast-editor ul {
                list-style: disc outside;
            }

            .student-blast-editor ol {
                list-style: decimal outside;
            }

            .student-blast-editor li {
                margin-bottom: 0.35rem;
            }
        </style>
    @endpush
@endonce
