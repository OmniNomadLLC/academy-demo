@php
    $eventsJson = json_encode($events ?? []);
    $tz = $timezone ?? 'Europe/London';
    $isAcademic = $isAcademic ?? false;
@endphp

<x-filament::page>
    <div class="space-y-4">
        <div>
            {{ $this->form }}
        </div>

        <div class="rounded-xl border bg-white dark:bg-gray-900 p-2">
            <script id="calendar-events" type="application/json" data-tz="{{ $tz }}">{!! $eventsJson !!}</script>
            <style>
                /* Event card height and readability */
                #calendar .fc-timegrid-event { min-height: 100px !important; }
                #calendar .fc-timegrid-event .fc-event-main { padding: 8px 10px; }
                #calendar .fc-daygrid-event { white-space: normal; line-height: 1.3; padding: 6px 8px; min-height: 100px; }
                #calendar .event-card-line1 { font-size: 0.95rem; font-weight: 600; }
                #calendar .event-card-line2 { font-size: 0.875rem; }
                #calendar .event-card-badges { margin-top: 4px; display: block; }

                /* Increase vertical space per hour in timeGrid to prevent overlap */
                #calendar .fc-timegrid-slot { height: 60px !important; }
                #calendar .fc-timegrid-slot-label, 
                #calendar .fc-timegrid-slot-lane { height: 60px !important; }
            </style>
            <div id="calendar" wire:ignore class="w-full" style="min-height: 600px;" data-tz="{{ $tz }}"></div>
            <div class="px-2 py-1 text-xs text-gray-600 dark:text-gray-300">
                Loaded events: {{ is_array($events ?? null) ? count($events) : 0 }}
            </div>
        </div>
    </div>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/main.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/luxon@3/build/global/luxon.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/luxon3@6.1.11/index.global.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('calendar');
            if (!container) return;

            // Parse initial events from backend (from JSON script tag so it updates on Livewire refresh)
            function readEventsFromDom() {
                try {
                    const node = document.getElementById('calendar-events');
                    return node ? JSON.parse(node.textContent || '[]') : [];
                } catch (e) { return []; }
            }
            const initialEvents = readEventsFromDom();
            const isAcademic = @json($isAcademic);
            let tz = @json($tz);

            function currentTz() {
                // Read tz from the JSON script tag which Livewire re-renders
                const node = document.getElementById('calendar-events');
                const dz = node && node.dataset ? node.dataset.tz : null;
                return (dz && dz.trim() !== '') ? dz : tz;
            }

            // Helper to render badges
            function badge(text, colorClass) {
                return `<span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium ${colorClass}">${text}</span>`;
            }

            // Helpers for timezone-aware current date and earliest start time
            function todayDateStringInTz(tzid) {
                // Build a Date representing now in given timezone, then format YYYY-MM-DD
                const d = new Date(new Date().toLocaleString('en-US', { timeZone: tzid }));
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return `${y}-${m}-${day}`;
            }

            

            function earliestTimeForDate(events, dateStr) {
                let earliest = null; // 'HH:MM:SS'
                if (!Array.isArray(events)) return null;
                for (const ev of events) {
                    const s = ev && ev.start ? String(ev.start) : '';
                    if (!s.includes('T')) continue;
                    const [dPart, tPartFull] = s.split('T');
                    if (dPart !== dateStr) continue;
                    const tPart = (tPartFull || '').substring(0, 8);
                    if (!/^\d{2}:\d{2}:\d{2}$/.test(tPart)) continue;
                    if (earliest === null || tPart < earliest) earliest = tPart;
                }
                return earliest;
            }

            tz = currentTz();
            const todayStr = todayDateStringInTz(tz);
            const earliestToday = earliestTimeForDate(initialEvents, todayStr);
            let slotStart = '07:00:00';
            // Start at earliest of 07:00 or first class today
            if (earliestToday && earliestToday < slotStart) slotStart = earliestToday;

            // Robust Luxon adapter detection (different globals across builds)
            const luxonAdapters = [];
            if (typeof FullCalendarLuxon3 !== 'undefined') luxonAdapters.push(FullCalendarLuxon3);
            if (typeof FullCalendarLuxon !== 'undefined') luxonAdapters.push(FullCalendarLuxon);
            if (typeof FullCalendar !== 'undefined' && FullCalendar && FullCalendar.Luxon) luxonAdapters.push(FullCalendar.Luxon);
            const plugins = luxonAdapters.length ? [ luxonAdapters[0] ] : [];
            function computeNowForTz() {
                const tzid = currentTz();
                try {
                    if (typeof luxon !== 'undefined') {
                        return luxon.DateTime.now().setZone(tzid).toJSDate();
                    }
                } catch (e) {}
                try {
                    const s = new Date().toLocaleString('en-US', { timeZone: tzid });
                    return new Date(s);
                } catch (e) { return new Date(); }
            }

            let calendar;
            try {
            const calendarInit = {
                plugins,
                timeZone: tz,
                initialView: 'timeGridDay',
                // Anchor to selected-tz "today" to avoid off-by-one
                initialDate: todayDateStringInTz(tz),
                slotMinTime: slotStart,
                scrollTime: slotStart,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'timeGridDay,timeGridWeek,dayGridMonth,listWeek'
                },
                buttonText: {
                    today: 'Today',
                    timeGridDay: 'Day',
                    timeGridWeek: 'Week',
                    dayGridMonth: 'Month',
                    listWeek: 'List',
                },
                navLinks: true,
                nowIndicator: true,
                now: computeNowForTz,
                datesSet: function(info) {
                    // Only show the red now line on timeGrid views (Day/Week)
                    const type = info && info.view ? info.view.type : '';
                    const isTimeGrid = /^timeGrid/.test(type);
                    try { info.view.calendar.setOption('nowIndicator', isTimeGrid); } catch (e) {}
                },
                selectable: false,
                height: 'auto',
                events: initialEvents,
                eventClick: function(info) {
                    if (info.event.url && info.event.url !== '#') {
                        window.open(info.event.url, '_blank');
                        info.jsEvent.preventDefault();
                    }
                },
                eventContent: function(arg) {
                    const ev = arg.event;
                    const p = ev.extendedProps || {};
                    const status = (p.status || '').toString();
                    const region = (p.region || '').toString();
                    const calName = (p.calendar_name || ev.title || '').toString();
                    const groupCount = parseInt(p.group_count || 0, 10) || 0;
                    const apptType = (p.appointment_type || '').toString();
                    const student = (p.student_name || '').toString();
                    // Render start time in the selected timezone (not the browser's local timezone)
                    let timeStr = '';
                    try {
                        const tzid = currentTz();
                        if (typeof luxon !== 'undefined' && ev.start instanceof Date) {
                            timeStr = luxon.DateTime.fromJSDate(ev.start).setZone(tzid).toFormat('HH:mm');
                        } else if (ev.start) {
                            // Fallback to browser-local if Luxon unavailable
                            timeStr = ev.start.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                        }
                    } catch (e) { timeStr = ev.start ? ev.start.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''; }

                    // Line 1: HH:MM · Calendar name
            const line1 = document.createElement('div');
            line1.className = 'event-card-line1';
            line1.textContent = (timeStr ? timeStr + ' · ' : '') + calName;

            // Line 2: student name or, if group, appointment type
            const line2 = document.createElement('div');
            line2.className = 'event-card-line2';
            const label = groupCount > 1 ? (apptType || 'Group Session') : (student || apptType || '');
            line2.textContent = label;

            // Badges: status + region
            const badgeWrap = document.createElement('div');
            badgeWrap.className = 'event-card-badges space-x-1';
                    if (status) {
                        const span = document.createElement('span');
                        let color = 'bg-amber-100 text-amber-700';
                        const s = String(status).toLowerCase();
                        if (s === 'confirmed' || s === 'scheduled') color = 'bg-blue-100 text-blue-700';
                        if (s === 'in progress') color = 'bg-purple-100 text-purple-700';
                        if (s === 'completed') color = 'bg-emerald-100 text-emerald-700';
                        if (s === 'cancelled') color = 'bg-rose-100 text-rose-700';
                        span.className = `inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ${color}`;
                        span.textContent = status;
                        badgeWrap.appendChild(span);
                    }
                    if (region) {
                        const span = document.createElement('span');
                        const r = region.toLowerCase();
                        let rc = 'bg-gray-100 text-gray-700';
                        if (r === 'uk') rc = 'bg-indigo-100 text-indigo-700';
                        if (r === 'spain') rc = 'bg-orange-100 text-orange-700';
                        if (r === 'france') rc = 'bg-green-100 text-green-700';
                        span.className = `inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ${rc}`;
                        span.textContent = region;
                        badgeWrap.appendChild(span);
                    }

                    const root = document.createElement('div');
                    root.appendChild(line1);
                    if (label) root.appendChild(line2);
                    root.appendChild(badgeWrap);

                    return { domNodes: [root] };
                },
            };
            calendar = new FullCalendar.Calendar(container, calendarInit);
            calendar.render();
            // Ensure we are on today by default
            try { calendar.today(); } catch (e) {}

            function applyTimeZone(tzid) {
                if (!tzid) return;
                calendar.setOption('timeZone', tzid);
                try { calendar.setOption('now', computeNowForTz); } catch (e) {}
            }

            // Keep now-indicator aligned with selected tz
            setInterval(() => { try { calendar.setOption('now', computeNowForTz); } catch (e) {} }, 60000);

            // Rely on FullCalendar's own now-indicator refresh cadence
            } catch (e) {
                console.error('FullCalendar init failed:', e);
                // Fallback render to avoid blank page
                try {
                    const fb = new FullCalendar.Calendar(container, {
                        timeZone: 'UTC',
                        nowIndicator: true,
                        initialView: 'dayGridMonth',
                        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
                        events: initialEvents,
                    });
                    fb.render();
                } catch (ee) {
                    console.error('Fallback render failed:', ee);
                }
            }

            // Update events when the embedded JSON changes (filter changes via Livewire)
            function updateEventsFromDom() {
                const newEvents = readEventsFromDom();
                try {
                    // Update timezone from DOM attribute first
                    const tzid = currentTz();
                    applyTimeZone(tzid);
                    // Recompute slot start for today based on possibly new tz
                    const tStr = todayDateStringInTz(tzid);
                    const early = earliestTimeForDate(newEvents, tStr);
                    let newSlotStart = '07:00:00';
                    if (early && early < newSlotStart) newSlotStart = early;
                    calendar.setOption('slotMinTime', newSlotStart);
                    calendar.setOption('scrollTime', newSlotStart);
                    calendar.removeAllEvents();
                    if (Array.isArray(newEvents)) {
                        calendar.addEventSource(newEvents);
                        calendar.today();
                    }
                } catch (e) { console.warn('Calendar event refresh failed', e); }
            }

            let eventsNode = document.getElementById('calendar-events');
            const handleCalendarDataChange = () => {
                try { applyTimeZone && applyTimeZone(currentTz()); } catch (e) {}
                updateEventsFromDom();
            };
            const observer = new MutationObserver((mutations) => {
                for (const m of mutations) {
                    const node = document.getElementById('calendar-events');
                    if (node && node !== eventsNode) {
                        // Node was replaced by Livewire; rebind reference
                        eventsNode = node;
                    }
                    const isTarget = (m.target === eventsNode) || (eventsNode && eventsNode.contains(m.target));
                    if (!isTarget) continue;
                    if (m.type === 'attributes' && m.attributeName === 'data-tz') {
                        handleCalendarDataChange();
                        break;
                    }
                    if (m.type === 'characterData' || m.type === 'childList') {
                        handleCalendarDataChange();
                        break;
                    }
                }
            });
            if (eventsNode) observer.observe(document.body, { childList: true, characterData: true, subtree: true, attributes: true });
        });
    </script>
</x-filament::page>
