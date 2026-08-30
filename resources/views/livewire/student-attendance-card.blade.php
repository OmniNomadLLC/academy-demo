@php
    $safeRate = max(0, min(100, (int) $rate));
    $strokeColor = $totals['total'] === 0 ? '#9ca3af' : ($safeRate >= 75 ? '#10b981' : '#ef4444');
    $radius = 90;
    $circumference = pi() * $radius;
    $offset = $totals['total'] > 0 ? $circumference * (1 - $safeRate / 100) : $circumference;
    $needleAngle = -90 + 180 * ($totals['total'] > 0 ? $safeRate / 100 : 0);
@endphp
<div wire:poll.15s="refreshData" class="flex flex-col items-center gap-4 text-gray-900 dark:text-gray-100">
    <div class="flex flex-col items-center gap-4">
        <div class="text-3xl font-semibold" style="color: {{ $strokeColor }};">{{ $totals['total'] > 0 ? $safeRate : 0 }}%</div>
        <div class="relative h-32 w-64">
            <svg viewBox="0 0 220 120" class="h-full w-full">
                <path d="M20 110 A90 90 0 0 1 200 110" fill="transparent" stroke="#e5e7eb" stroke-width="16" stroke-linecap="round" class="dark:stroke-gray-700" />
                <path d="M20 110 A90 90 0 0 1 200 110" fill="transparent" stroke="{{ $strokeColor }}" stroke-width="16" stroke-linecap="round" stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $offset }}" style="transition: stroke-dashoffset 600ms ease-out;" />
            </svg>
            <div class="pointer-events-none absolute inset-0 flex items-end justify-center">
                <div class="h-16 origin-bottom rounded-t-md" style="width: 4px; background-color: {{ $strokeColor }}; transform: rotate({{ $needleAngle }}deg); transition: transform 600ms ease-out;"></div>
            </div>
        </div>
        <div class="text-sm text-gray-600 dark:text-gray-300">
            Present: <span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $totals['present'] }}</span>
            · Late: <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $totals['late'] }}</span>
            · Absent: <span class="font-semibold text-rose-600 dark:text-rose-400">{{ $totals['absent'] }}</span>
            · Sessions tracked: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $totals['total'] }}</span>
        </div>
    </div>
    <div class="w-full border-t border-dashed border-gray-200 pt-3 text-center text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
        Attendance history remains available below in the detailed records table.
    </div>
</div>
