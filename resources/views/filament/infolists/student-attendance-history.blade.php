@php
    // Resolve student ID from context: prefer explicit $studentId, fallback to Infolist $record
    $sid = $studentId ?? ($record->id ?? null);
    $perPage = 10;
    $currentPage = max(1, (int) request()->query('attendance_page', 1));
    $rows = collect();
    $total = 0;
    if ($sid) {
        try {
            $query = \DB::table('attendance_records as ar')
                ->join('class_sessions as cs','cs.id','=','ar.class_session_id')
                ->select('cs.session_date','cs.start_time','cs.end_time','cs.calendar_name','ar.status','ar.marked_at')
                ->where('ar.student_id', $sid)
                ->whereIn('ar.status', ['present','late','absent']);

            $total = $query->count();

            $rows = $query
                ->orderByDesc('ar.marked_at')
                ->forPage($currentPage, $perPage)
                ->get();
        } catch (\Throwable $e) {
            $rows = collect();
            $total = 0;
        }
    }
    $lastPage = max(1, (int) ceil($total / $perPage));
@endphp
@php
    $statusColors = [
        'present' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        'late' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200',
        'absent' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200',
    ];
@endphp

<div class="w-full space-y-3">
    <div class="flex items-center justify-between">
        <div>
            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Detailed records</h4>
            <p class="text-xs text-gray-500">Last 10 records</p>
        </div>
        <div class="text-xs text-gray-500">Showing {{ $rows->count() }} of {{ $total }} records</div>
    </div>
    <div class="w-full overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                <tr>
                    <th class="py-3 pl-4 pr-3 text-left font-semibold">Date</th>
                    <th class="py-3 px-3 text-left font-semibold">Start</th>
                    <th class="py-3 px-3 text-left font-semibold">End</th>
                    <th class="py-3 px-3 text-left font-semibold">Calendar</th>
                    <th class="py-3 px-3 text-left font-semibold">Status</th>
                    <th class="py-3 px-3 text-left font-semibold">Marked</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white text-gray-800 dark:divide-gray-700 dark:bg-gray-900 dark:text-gray-100">
                @forelse($rows as $r)
                    @php($status = strtolower((string) $r->status))
                    @php($date = $r->session_date ? \Illuminate\Support\Carbon::parse($r->session_date) : null)
                    @php($startTime = $r->start_time ? \Illuminate\Support\Carbon::parse($r->start_time)->format('H:i') : '—')
                    @php($endTime = $r->end_time ? \Illuminate\Support\Carbon::parse($r->end_time)->format('H:i') : '—')
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <td class="py-4 pl-4 pr-3 align-top text-sm">
                            <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $date?->format('d M Y') ?? '—' }}</div>
                        </td>
                        <td class="py-4 px-3 align-top text-sm">{{ $startTime }}</td>
                        <td class="py-4 px-3 align-top text-sm">{{ $endTime }}</td>
                        <td class="py-4 px-3 align-top text-sm">
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                {{ $r->calendar_name ?? '—' }}
                            </span>
                        </td>
                    <td class="py-4 px-3 align-top">
                        @php($badgeClass = match($status) {
                            'present' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300',
                            'absent' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/50 dark:text-rose-300',
                            'late' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200',
                            default => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-200',
                        })
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badgeClass }}">
                            {{ ucfirst($status) }}
                        </span>
                    </td>
                        <td class="py-4 px-3 align-top text-sm text-gray-500">
                            {{ optional($r->marked_at ? \Illuminate\Support\Carbon::parse($r->marked_at) : null)?->format('d M Y H:i') ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-4 text-center text-sm text-gray-500">No attendance records yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($lastPage > 1)
        <div class="flex items-center justify-between text-xs text-gray-500">
            <span>Page {{ $currentPage }} of {{ $lastPage }}</span>
            <div class="flex gap-2">
                <a
                    class="rounded border border-gray-200 px-2 py-1 {{ $currentPage === 1 ? 'opacity-40 pointer-events-none' : '' }}"
                    href="{{ request()->fullUrlWithQuery(['attendance_page' => max(1, $currentPage - 1)]) }}"
                >Prev</a>
                <a
                    class="rounded border border-gray-200 px-2 py-1 {{ $currentPage === $lastPage ? 'opacity-40 pointer-events-none' : '' }}"
                    href="{{ request()->fullUrlWithQuery(['attendance_page' => min($lastPage, $currentPage + 1)]) }}"
                >Next</a>
            </div>
        </div>
    @endif
</div>
