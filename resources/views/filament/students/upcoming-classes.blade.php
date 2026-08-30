@php
    $rows = $rows ?? collect();
@endphp
<div class="mt-2">
    @if($rows->isEmpty())
        <div class="text-sm text-gray-600">No upcoming classes in the next 90 days.</div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead>
                    <tr class="text-left text-gray-600">
                        <th class="py-1 pr-3">Date</th>
                        <th class="py-1 pr-3">Time</th>
                        <th class="py-1 pr-3">Calendar</th>
                        <th class="py-1 pr-3">Category</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        @php
                            $status = strtolower((string)($r->status ?? ''));
                        @endphp
                        <tr class="border-t">
                            <td class="py-1 pr-3">{{ $r->date }}</td>
                            <td class="py-1 pr-3">{{ $r->start }}–{{ $r->end }}</td>
                            <td class="py-1 pr-3">{{ $r->calendar ?: '—' }}</td>
                            <td class="py-1 pr-3">{{ $r->category ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
