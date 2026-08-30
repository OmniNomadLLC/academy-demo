{{--
    Variables expected:
      $student  — Student model (or null)
      $label    — string, e.g. "Existing student"
      $accent   — 'green' | 'amber'  (left border color)
--}}
@php
    $borderColor = $accent === 'green'
        ? 'border-l-green-400 dark:border-l-green-600'
        : 'border-l-amber-400 dark:border-l-amber-500';
@endphp

<div class="w-full rounded-lg border border-gray-200 border-l-4 {{ $borderColor }}
            bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">

    {{-- Label --}}
    <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">
        {{ $label }}
    </p>

    @if ($student)

        {{-- Name --}}
        <p class="text-sm font-bold text-gray-900 dark:text-white">
            {{ $student->first_name }} {{ $student->last_name }}
        </p>

        {{-- Email --}}
        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
            {{ $student->email ?: '—' }}
        </p>

    @else
        <p class="mt-1 text-xs italic text-gray-400 dark:text-gray-600">
            Student not found (may have been deleted)
        </p>
    @endif

</div>
