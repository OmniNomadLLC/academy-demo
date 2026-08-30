@php
    $suggestions = $this->getPendingSuggestions();
@endphp

<x-filament-widgets::widget>
<div class="fi-wi-duplicate-students">

    @if ($suggestions->isNotEmpty())

        {{-- ── Alert banner (toggle) ──────────────────────────────────────── --}}
        <div
            wire:click="toggleExpanded"
            class="flex w-full cursor-pointer items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3
                   hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/40 dark:hover:bg-amber-900/50"
        >
            {{-- Icon --}}
            <div class="mt-0.5 flex-shrink-0 rounded-full bg-amber-200 p-1.5 dark:bg-amber-800">
                <x-heroicon-o-exclamation-triangle class="h-4 w-4 text-amber-700 dark:text-amber-300" />
            </div>

            {{-- Text --}}
            <div class="flex-1">
                <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                    {{ $suggestions->count() }} potential duplicate student {{ Str::plural('record', $suggestions->count()) }} detected
                </p>
                <p class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">
                    These records were created via Acuity sync and share the same name as an existing student.
                    Click to review and merge or dismiss.
                </p>
            </div>

            {{-- Chevron rotates when expanded --}}
            <div class="flex-shrink-0 transition-transform duration-200 {{ $expanded ? 'rotate-180' : '' }}">
                <x-heroicon-o-chevron-down class="h-5 w-5 text-amber-600 dark:text-amber-400" />
            </div>
        </div>

        {{-- ── Suggestion cards (shown when expanded) ─────────────────────── --}}
        @if ($expanded)
        <div class="mt-3 w-full space-y-3">

            @foreach ($suggestions as $suggestion)
            @php
                $studentA = $suggestion->studentA;
                $studentB = $suggestion->studentB;
            @endphp
            <div class="w-full rounded-xl border border-gray-200 bg-white shadow-sm
                        dark:border-gray-700 dark:bg-gray-900">

                {{-- Card header: reason badge + ID --}}
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5
                            dark:border-gray-800">
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5
                                 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-300">
                        <x-heroicon-m-exclamation-circle class="h-3 w-3" />
                        @if ($suggestion->reason === 'same_name') Same name
                        @elseif ($suggestion->reason === 'same_acuity_client_id') Same Acuity client ID
                        @else {{ $suggestion->reason }}
                        @endif
                    </span>
                    <span class="text-xs text-gray-400 dark:text-gray-600">suggestion #{{ $suggestion->id }}</span>
                </div>

                {{-- Card body: two student panels side by side --}}
                <div class="grid w-full grid-cols-2 gap-6 p-4">

                    {{-- Student A — existing (green left accent) --}}
                    @include('filament.widgets.partials.merge-student-panel', [
                        'student' => $studentA,
                        'label'   => 'Existing student',
                        'accent'  => 'green',
                    ])

                    {{-- Student B — new / duplicate (amber left accent) --}}
                    @include('filament.widgets.partials.merge-student-panel', [
                        'student' => $studentB,
                        'label'   => 'New (duplicate?)',
                        'accent'  => 'amber',
                    ])

                </div>

                {{-- Card footer: action buttons --}}
                <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-3
                            dark:border-gray-800">
                    <x-filament::button
                        wire:click="dismissSuggestion({{ $suggestion->id }})"
                        color="gray"
                        outlined
                        size="sm"
                        icon="heroicon-o-x-mark"
                    >
                        Dismiss
                    </x-filament::button>

                    <x-filament::button
                        wire:click="initiateMerge({{ $suggestion->id }})"
                        color="warning"
                        size="sm"
                        icon="heroicon-o-arrow-path"
                    >
                        Merge →
                    </x-filament::button>
                </div>

            </div>
            @endforeach

        </div>
        @endif

    @endif

    <x-filament-actions::modals />

</div>
</x-filament-widgets::widget>
