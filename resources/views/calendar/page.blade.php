@php
    $initialDate = $this->date ?? now()->toDateString();
    $initialView = $this->viewMode ?? 'day';
@endphp

<style>
    .fc-daygrid-event,
    .fc-timegrid-event,
    .fc-list-event {
        cursor: pointer;
    }
</style>

<div
    wire:ignore
    x-data="portalCalendar({
        endpoint: @js(route('portal.api.calendar.events')),
        timezone: @js($timezone ?? config('app.timezone')),
        initialDate: @js($initialDate),
        initialView: @js($initialView),
    })"
    x-init="init()"
    class="space-y-4"
>
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <button
                type="button"
                class="px-2.5 py-1.5 text-sm font-semibold rounded-lg border border-gray-200 bg-white text-gray-900 hover:bg-gray-50"
                @click.prevent="goToday()"
            >
                Today
            </button>
            <button
                type="button"
                class="px-2.5 py-1.5 text-sm font-semibold rounded-lg border border-gray-200 bg-white text-gray-900 hover:bg-gray-50"
                @click.prevent="setView('day', $event)"
            >
                Day
            </button>
            <button
                type="button"
                class="px-2.5 py-1.5 text-sm font-semibold rounded-lg border border-gray-200 bg-white text-gray-900 hover:bg-gray-50"
                @click.prevent="setView('week', $event)"
            >
                Week
            </button>
            <button
                type="button"
                class="px-2.5 py-1.5 text-sm font-semibold rounded-lg border border-gray-200 bg-white text-gray-900 hover:bg-gray-50"
                @click.prevent="setView('month', $event)"
            >
                Month
            </button>
        </div>
    </div>

    <div id="calendar" class="bg-white rounded-xl shadow-sm border p-2"></div>

    <div
        x-cloak
        x-show="modalOpen"
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
        role="dialog"
        aria-modal="true"
    >
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold" x-text="modalEvent.title"></h2>
                    <p class="text-sm text-gray-500" x-text="modalEvent.calendar"></p>
                </div>
                <button type="button" class="text-gray-400 hover:text-gray-600" x-on:click="closeModal()" aria-label="Close">
                    <span class="text-xl leading-none">&times;</span>
                </button>
            </div>

            <dl class="space-y-2 text-sm text-gray-700">
                <div class="flex items-start justify-between gap-3">
                    <dt class="font-medium">When</dt>
                    <dd class="text-right">
                        <p x-text="modalEvent.when"></p>
                        <p class="text-xs text-gray-500" x-text="modalEvent.ends"></p>
                    </dd>
                </div>
                <div class="flex items-start justify-between gap-3" x-show="modalEvent.location">
                    <dt class="font-medium">Location</dt>
                    <dd class="text-right" x-text="modalEvent.location"></dd>
                </div>
                <div class="flex items-start justify-between gap-3" x-show="modalEvent.notes">
                    <dt class="font-medium">Notes</dt>
                    <dd class="text-right whitespace-pre-line" x-text="modalEvent.notes"></dd>
                </div>
            </dl>

            <div class="flex items-center justify-end gap-3">
                <button
                    type="button"
                    class="px-3 py-1.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200"
                    x-on:click="closeModal()"
                >
                    Close
                </button>
                <template x-if="modalEvent.url">
                    <a
                        class="px-3 py-1.5 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700"
                        x-bind:href="modalEvent.url"
                    >
                        View details
                    </a>
                </template>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
    (function registerPortalCalendar() {
        const register = () => {
            Alpine.data('portalCalendar', (config = {}) => ({
                fc: null,
                endpoint: '',
                timezone: 'UTC',
                initialDate: null,
                initialView: config.initialView ?? 'day',
                viewMode: config.initialView ?? 'day',
                ...config,
                modalOpen: false,
                modalEvent: {
                    title: '',
                    when: '',
                    ends: '',
                    location: '',
                    notes: '',
                    calendar: '',
                    url: '',
                },
                init() {
                    const el = document.getElementById('calendar');
                    if (! el) {
                        return;
                    }

                    const plugins = [];
                    if (window.FullCalendar) {
                        if (window.FullCalendar.dayGridPlugin) {
                            plugins.push(window.FullCalendar.dayGridPlugin);
                        }
                        if (window.FullCalendar.timeGridPlugin) {
                            plugins.push(window.FullCalendar.timeGridPlugin);
                        }
                        if (window.FullCalendar.listPlugin) {
                            plugins.push(window.FullCalendar.listPlugin);
                        }
                        if (window.FullCalendar.interactionPlugin) {
                            plugins.push(window.FullCalendar.interactionPlugin);
                        }
                    }

                    this.fc = new FullCalendar.Calendar(el, {
                        height: 'auto',
                        headerToolbar: { left: 'prev,next', center: 'title', right: '' },
                        initialDate: this.initialDate,
                        initialView: this.resolveView(this.initialView),
                        nowIndicator: true,
                        timeZone: this.timezone || 'UTC',
                        plugins,
                        slotMinTime: '08:00:00',
                        slotMaxTime: '22:00:00',
                        eventDidMount: (info) => {
                            if (! info.el) {
                                return;
                            }

                            info.el.classList.add('cursor-pointer');
                        },
                        eventClick: (info) => {
                            if (info.jsEvent) {
                                info.jsEvent.preventDefault();
                            }
                            this.showEvent(info.event);
                        },
                        events: (info, success, failure) => {
                            this.fetchEvents(info.startStr, info.endStr)
                                .then(success)
                                .catch(failure);
                        },
                    });

                    this.fc.render();
                },
                fetchEvents(start, end) {
                    const params = new URLSearchParams({ start, end });
                    const url = `${this.endpoint}?${params.toString()}`;

                    return fetch(url, {
                        method: 'GET',
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    }).then(response => {
                        if (! response.ok) {
                            throw new Error('Unable to load events');
                        }

                        return response.json();
                    });
                },
                goToday() {
                    if (! this.fc) {
                        return;
                    }

                    this.fc.today();
                    this.fc.refetchEvents();

                    if (this.$wire?.today) {
                        this.$wire.today();
                    }
                },
                setView(mode, event = null) {
                    if (event) {
                        event.preventDefault();
                        event.stopPropagation();
                    }

                    if (! this.fc) {
                        return;
                    }

                    const resolved = ['day', 'week', 'month'].includes(mode) ? mode : 'day';

                    this.viewMode = resolved;
                    this.initialView = resolved;

                    try {
                        if (this.$wire?.setViewMode) {
                            this.$wire.setViewMode(resolved);
                        }
                    } catch (error) {
                        console.warn('Livewire setViewMode failed; continuing client-side', error);
                    }

                    const viewName = this.resolveView(resolved);

                    if (this.fc.view?.type !== viewName) {
                        this.fc.changeView(viewName);
                    }

                    this.fc.refetchEvents();
                },
                resolveView(mode) {
                    return mode === 'day'
                        ? 'timeGridDay'
                        : (mode === 'month' ? 'dayGridMonth' : 'timeGridWeek');
                },
                showEvent(event) {
                    if (! event) {
                        return;
                    }

                    const props = event.extendedProps || {};
                    const startLabel = props.start_label || (event.start ? event.start.toLocaleString() : '');
                    const endLabel = props.end_label ? `Ends ${props.end_label}` : (event.end ? `Ends ${event.end.toLocaleTimeString()}` : '');

                    this.modalEvent = {
                        title: event.title || 'Lesson',
                        when: startLabel,
                        ends: endLabel,
                        location: props.location || '',
                        notes: props.notes || '',
                        calendar: props.calendar || '',
                        url: props.roster_url || '',
                    };

                    this.modalOpen = true;
                },
                closeModal() {
                    this.modalOpen = false;
                    this.modalEvent = {
                        title: '',
                        when: '',
                        ends: '',
                        location: '',
                        notes: '',
                        calendar: '',
                        url: '',
                    };
                },
            }));
        };

        document.addEventListener('alpine:init', register);

        if (window.Alpine && typeof window.Alpine.data === 'function') {
            register();
        }
    })();
</script>
