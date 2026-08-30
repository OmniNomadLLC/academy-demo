<x-filament::page>
    <div class="mb-4 text-sm text-gray-600">
        <div>
            Class: {{ $record->schoolClass->name ?? '—' }}
        </div>
        <div>
            When: {{ optional($record->session_date)->format('Y-m-d') }} {{ $record->start_time }}–{{ $record->end_time }} ({{ config('app.timezone') }})
        </div>
    </div>

    {{ $this->table }}
</x-filament::page>

