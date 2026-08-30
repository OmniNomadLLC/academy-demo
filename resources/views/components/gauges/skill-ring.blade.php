@props([
    'label',
    'value' => 0,
    'percent' => null,
    'max' => 5,
    'centerLabel' => null,
])

@php
    $max = (float) ($max ?: 1);
    $value = max(0, min($max, (float) $value));
    $percent = $percent !== null
        ? max(0, min(100, (float) $percent))
        : ($max > 0 ? ($value / $max) * 100 : 0);

    $color = match (true) {
        $percent >= 80 => '#10b981',
        $percent >= 60 => '#22c55e',
        $percent >= 40 => '#f59e0b',
        $percent >= 20 => '#f97316',
        default => '#f87171',
    };

    $circumference = 2 * pi() * 40;
    $offset = $circumference * (1 - $percent / 100);
    $centerLabel = $centerLabel ?? number_format($value, 1);
@endphp

<div class="relative h-32 w-32 sm:h-36 sm:w-36 lg:h-40 lg:w-40">
    <svg class="h-full w-full" viewBox="0 0 100 100">
        <circle cx="50" cy="50" r="40" stroke="#e5e7eb" stroke-width="10" fill="none" class="dark:stroke-gray-700" />
        <circle
            cx="50"
            cy="50"
            r="40"
            stroke="{{ $color }}"
            stroke-width="10"
            fill="none"
            stroke-dasharray="{{ $circumference }}"
            stroke-dashoffset="{{ $offset }}"
            stroke-linecap="round"
            transform="rotate(-90 50 50)"
            style="transition: stroke-dashoffset 400ms ease-out;"
        />
    </svg>
    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
        <span class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $centerLabel }}</span>
        @if(! empty($label))
            <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</span>
        @endif
    </div>
</div>
