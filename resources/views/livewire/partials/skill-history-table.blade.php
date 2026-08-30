<div class="space-y-3">
    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">Progress history</h3>
    <div class="w-full overflow-x-auto">
        <table class="min-w-full w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-3 py-2 text-center">Writing</th>
                    <th class="px-3 py-2 text-center">Reading</th>
                    <th class="px-3 py-2 text-center">Speaking</th>
                    <th class="px-3 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700 dark:bg-gray-900">
                @forelse($history as $entry)
                    <tr>
                        <td class="px-4 text-gray-700 dark:text-gray-200">{{ $entry['date'] }}</td>
                        <td class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100">{{ $entry['writing'] }}/5</td>
                        <td class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100">{{ $entry['reading'] }}/5</td>
                        <td class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100">{{ $entry['speaking'] }}/5</td>
                        <td class="px-3 py-2 text-right">
                            @empty($readOnly)
                                <x-filament::icon-button
                                    icon="heroicon-m-trash"
                                    color="danger"
                                    size="sm"
                                    wire:click="deleteEntry({{ $entry['id'] }})"
                                    wire:loading.attr="disabled"
                                    :label="'Delete record'"
                                />
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endempty
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-3 text-center text-sm text-gray-500 dark:text-gray-400">No progress records yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
