<x-filament::page>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Class overview</x-slot>
            <dl class="divide-y divide-gray-100 dark:divide-gray-800">
                <div class="flex items-center justify-between py-2">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Date</dt>
                    <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $sessionSummary['date'] ?? '—' }}</dd>
                </div>
                <div class="grid grid-cols-2 gap-4 py-2">
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Start</dt>
                        <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $sessionSummary['start'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">End</dt>
                        <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $sessionSummary['end'] ?? '—' }}</dd>
                    </div>
                </div>
                <div class="flex items-center justify-between py-2">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Calendar</dt>
                    <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $sessionSummary['calendar'] ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between py-2">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Appointment type</dt>
                    <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $sessionSummary['appointment'] ?? 'Class' }}</dd>
                </div>
                <div class="flex items-center justify-between py-2">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Assigned teacher</dt>
                    <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $sessionSummary['assigned'] ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between py-2">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Current teacher</dt>
                    <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $sessionSummary['current'] ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between py-2">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Students</dt>
                    <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $sessionSummary['count'] ?? 0 }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Cover teacher</x-slot>
            <div class="space-y-4">
                {{ $this->form }}
                <x-filament::button color="primary" wire:click="save">Save changes</x-filament::button>
            </div>
        </x-filament::section>
    </div>

    <x-filament::section class="mt-6">
        <x-slot name="heading">Students ({{ count($students) }})</x-slot>
        <div class="space-y-2">
            @forelse ($students as $student)
                <div class="flex items-center justify-between rounded-lg border border-gray-100 bg-white p-3 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $student['name'] }}</span>
                    <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $student['status'] }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No attendees found for this class.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament::page>
