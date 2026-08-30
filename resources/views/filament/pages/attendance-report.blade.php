@php
    $fmt = function ($n) { return number_format((float)$n); };
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div>
            {{ $this->form }}
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="p-4 rounded border bg-white">
                <div class="text-xs text-gray-500">Present</div>
                <div class="text-2xl font-semibold text-emerald-700">{{ $fmt($present) }}</div>
            </div>
            <div class="p-4 rounded border bg-white">
                <div class="text-xs text-gray-500">Late</div>
                <div class="text-2xl font-semibold text-amber-700">{{ $fmt($late) }}</div>
            </div>
            <div class="p-4 rounded border bg-white">
                <div class="text-xs text-gray-500">Absent</div>
                <div class="text-2xl font-semibold text-rose-700">{{ $fmt($absent) }}</div>
            </div>
            <div class="p-4 rounded border bg-white">
                <div class="text-xs text-gray-500">Attendance rate</div>
                <div class="text-2xl font-semibold {{ $rate < 75 ? 'text-rose-700' : 'text-emerald-700' }}">{{ number_format($rate, 2) }}%</div>
            </div>
        </div>

        <div class="p-4 rounded border bg-white">
            <div class="text-sm font-semibold mb-2">Top absences</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th class="py-1 pr-3">Student</th>
                            <th class="py-1 pr-3">Absences</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($topAbsences as $row)
                            @php $student = \App\Models\Student::find($row->student_id); @endphp
                            <tr class="border-t">
                                <td class="py-1 pr-3">
                                    @if($student)
                                        <a href="{{ route('filament.admin.resources.student-resource.view', ['record' => $student->id]) }}" class="text-primary-600 hover:underline">
                                            {{ $student->full_name ?: $student->email }}
                                        </a>
                                    @else
                                        ID {{ $row->student_id }}
                                    @endif
                                </td>
                                <td class="py-1 pr-3">{{ $row->absences }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>

