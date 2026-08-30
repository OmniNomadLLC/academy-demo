<div class="space-y-6 w-full">
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
        @foreach($skills as $skill)
            <div class="rounded-2xl border border-gray-200 bg-white p-4 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-col items-center">
                    <x-gauges.skill-ring
                        label=""
                        :value="$skill['is_empty'] ? 0 : $skill['score']"
                        :percent="$skill['is_empty'] ? 0 : $skill['percentage']"
                        :max="10"
                        :center-label="number_format($skill['score'], 1)"
                    />
                </div>
                <p class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $skill['label'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $skill['is_empty'] ? 'No final assessments yet' : $skill['percentage'] . '%' }}
                </p>
            </div>
        @endforeach
    </div>

    @if(! $hasData)
        <p class="text-sm text-gray-500 dark:text-gray-400">No FINAL assessments yet. Complete at least one assessment to unlock skill intelligence.</p>
    @endif

    @if(! empty($skillHistory))
        <div class="w-full overflow-x-auto">
            <table class="w-full divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white text-sm dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-900">
                <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-600 dark:bg-gray-900/60 dark:text-gray-300">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left">Assessment date</th>
                        @foreach($skillColumns as $label)
                            <th scope="col" class="px-4 py-3 text-center">{{ $label }}</th>
                        @endforeach
                        <th scope="col" class="px-4 py-3 text-center">Avg</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($skillHistory as $entry)
                        <tr class="text-gray-800 dark:text-gray-100">
                            <td class="px-4 py-3 font-medium">{{ $entry['assessed_at'] }}</td>
                            @foreach(array_keys($skillColumns) as $skillKey)
                                @php $value = $entry['scores'][$skillKey] ?? null; @endphp
                                <td class="px-4 py-3 text-center text-sm text-gray-700 dark:text-gray-200">
                                    {{ $value === null ? '—' : number_format($value, 1) }}
                                </td>
                            @endforeach
                            <td class="px-4 py-3 text-center text-sm font-semibold text-gray-800 dark:text-gray-100">
                                {{ $entry['average'] === null ? '—' : number_format($entry['average'], 1) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
