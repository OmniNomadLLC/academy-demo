<x-filament-panels::page>
    @php
        $funnel = $this->getFunnel();
        $placements = $this->getPlacements();
        $steps = [
            ['label' => 'Active employment profiles', 'value' => $funnel['profiles'], 'icon' => '👤'],
            ['label' => 'Job matches surfaced', 'value' => $funnel['matches'], 'icon' => '🎯'],
            ['label' => 'Applications', 'value' => $funnel['applications'], 'icon' => '📨'],
            ['label' => 'Interview stage+', 'value' => $funnel['interviews'], 'icon' => '🗣️'],
            ['label' => 'Employer-confirmed', 'value' => $funnel['interviews_confirmed'], 'icon' => '✅'],
            ['label' => 'Hired', 'value' => $funnel['hired'], 'icon' => '💼'],
        ];
    @endphp

    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
        @foreach ($steps as $step)
            <div class="rounded-2xl border border-gray-200 bg-white p-4 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xl">{{ $step['icon'] }}</div>
                <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $step['value'] }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $step['label'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">Sustained-outcome tracker (16 hrs/week × 26 weeks)</h2>
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">Provider-tracked — not HMRC-verified</span>
        </div>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            The official sustained outcome is verified by DWP via HMRC PAYE data. This tracker shows employer-confirmed and participant-reported progress toward that threshold.
        </p>

        @if ($placements === [])
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No placements yet — hires appear here with their 26-week progress.</p>
        @else
            <div class="mt-4 space-y-3">
                @foreach ($placements as $placement)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $placement['student'] }}</span>
                                <span class="text-sm text-gray-600 dark:text-gray-300">— {{ $placement['job'] }} @ {{ $placement['employer'] ?? '?' }}</span>
                                @if ($placement['employer_confirmed'])
                                    <span class="ml-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">✓ employer-confirmed</span>
                                @endif
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">started {{ $placement['started'] }} · target {{ $placement['target_date'] }}</span>
                        </div>
                        <div class="mt-2 h-3 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-3 rounded-full {{ $placement['weeks'] >= 26 ? 'bg-emerald-500' : 'bg-primary-600' }}" style="width: {{ round($placement['weeks'] / 26 * 100) }}%"></div>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $placement['weeks'] }} / 26 weeks in work</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">Compliance evidence</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            {{ number_format($funnel['evidence_events']) }} tamper-evident evidence events recorded (hash-chained, verifiable via <code class="text-xs">luminaworks:verify-evidence</code>).
            Per-participant bundles export via <code class="text-xs">luminaworks:export-evidence</code>; the funnel exports via <code class="text-xs">luminaworks:export-funnel</code>.
        </p>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            The platform never decides sanctions or benefits — it raises compliance doubts with supporting evidence for a DWP Decision Maker.
        </p>
    </div>
</x-filament-panels::page>
