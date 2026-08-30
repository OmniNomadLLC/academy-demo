<div class="space-y-4">
    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">Update progress</h3>
    @if(!empty($readOnly))
        <p class="text-xs text-gray-500 dark:text-gray-400">Read-only access — UK managers can review progress but not edit the scores.</p>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">Tap a score from 0 (needs support) to 5 (excellent progress).</p>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <div>
                <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Writing</label>
                <div class="mt-2 flex flex-wrap gap-2 justify-start">
                    @foreach(range(0,5) as $value)
                        @php $isActive = (int) ($writing ?? -1) === (int) $value; @endphp
                        <button
                            type="button"
                            wire:click="$set('writing', {{ $value }})"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold transition-colors duration-150 {{ $isActive ? 'text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' }}"
                            style="{{ $isActive ? 'background-color: #dc2626;' : '' }}"
                        >
                            {{ $value }}
                        </button>
                    @endforeach
                </div>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Reading</label>
                <div class="mt-2 flex flex-wrap gap-2 justify-start">
                    @foreach(range(0,5) as $value)
                        @php $isActive = (int) ($reading ?? -1) === (int) $value; @endphp
                        <button
                            type="button"
                            wire:click="$set('reading', {{ $value }})"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold transition-colors duration-150 {{ $isActive ? 'text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' }}"
                            style="{{ $isActive ? 'background-color: #dc2626;' : '' }}"
                        >
                            {{ $value }}
                        </button>
                    @endforeach
                </div>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Speaking</label>
                <div class="mt-2 flex flex-wrap gap-2 justify-start">
                    @foreach(range(0,5) as $value)
                        @php $isActive = (int) ($speaking ?? -1) === (int) $value; @endphp
                        <button
                            type="button"
                            wire:click="$set('speaking', {{ $value }})"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold transition-colors duration-150 {{ $isActive ? 'text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' }}"
                            style="{{ $isActive ? 'background-color: #dc2626;' : '' }}"
                        >
                            {{ $value }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="mt-6 flex justify-center lg:justify-start">
            <x-filament::button color="primary" size="sm" wire:click="save" wire:loading.attr="disabled">
                <span class="inline-flex items-center gap-1">
                    <x-filament::icon icon="heroicon-o-check" class="h-4 w-4" />
                    <span>Save progress snapshot</span>
                </span>
            </x-filament::button>
        </div>
    @endif
</div>
