@if ($suggestion)
@php
    $studentA = $suggestion->studentA;
    $studentB = $suggestion->studentB;

    // keepStudentId is a public Livewire property on the widget component.
    // $this refers to the widget because this view is rendered inside its DOM.
    $keepId = $this->keepStudentId ?: $suggestion->student_a_id;

    $students = [
        ['student' => $studentA, 'label' => 'Student A', 'default' => true],
        ['student' => $studentB, 'label' => 'Student B', 'default' => false],
    ];
@endphp

<div class="space-y-4 text-sm">

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Click a card to choose which student record to keep.
        All sessions, assessments, attendance and other data will be transferred to the kept record.
        The other record will be soft-deleted. <strong class="text-gray-700 dark:text-gray-300">This cannot be undone.</strong>
    </p>

    {{-- ── Selectable student cards ──────────────────────────────────────── --}}
    <div class="grid grid-cols-2 gap-4">

        @foreach ($students as $item)
        @php
            $s      = $item['student'];
            $isKept = $s && ($s->id === $keepId);
        @endphp

        <div
            wire:click="setKeepStudent({{ $s?->id ?? 0 }})"
            class="relative cursor-pointer rounded-lg border-2 p-4 transition-all
                   {{ $isKept
                       ? 'border-green-400 bg-green-50 dark:border-green-500 dark:bg-green-950/25'
                       : 'border-red-300 bg-red-50 dark:border-red-500 dark:bg-red-950/20 hover:border-red-400' }}"
        >
            {{-- Keep / Delete badge --}}
            <div class="mb-2 flex items-center gap-1.5">
                @if ($isKept)
                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-semibold text-green-800 dark:text-green-300">
                        <x-heroicon-m-check-circle class="h-3.5 w-3.5" />
                        Keep this student
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 rounded-full bg-red-100 dark:bg-red-900 px-2 py-0.5 text-xs font-semibold text-red-700 dark:text-red-300">
                        <x-heroicon-m-x-circle class="h-3.5 w-3.5" />
                        Will be deleted
                    </span>
                @endif
            </div>

            @if ($s)
                <p class="font-semibold text-gray-900 dark:text-white">
                    {{ $s->first_name }} {{ $s->last_name }}
                    <span class="text-xs font-normal text-gray-400 dark:text-gray-500">#{{ $s->id }}</span>
                </p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $s->email ?? '—' }}</p>
                @if ($s->acuity_client_id)
                    <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">Acuity: {{ $s->acuity_client_id }}</p>
                @endif
            @else
                <p class="mt-1 text-xs italic text-gray-400 dark:text-gray-600">Record not found</p>
            @endif
        </div>

        @endforeach

    </div>

    {{-- ── What gets moved notice ────────────────────────────────────────── --}}
    <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800
                dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300">
        <strong>Data transferred to the kept record:</strong>
        class sessions, attendance records, assessments, progress notes,
        enrolment messages, employment profiles &amp; outcomes.
        @php
            // Identify which student is the "duplicate" based on current keepId
            $deletedStudent = ($keepId === $studentA?->id) ? $studentB : $studentA;
            $keptStudent    = ($keepId === $studentA?->id) ? $studentA : $studentB;
        @endphp
        @if (empty($keptStudent?->acuity_client_id) && !empty($deletedStudent?->acuity_client_id))
            <br>Acuity client ID <code>{{ $deletedStudent->acuity_client_id }}</code> will also be copied to the kept record.
        @endif
    </div>

</div>
@else
<p class="text-sm text-gray-500">Suggestion not found.</p>
@endif
