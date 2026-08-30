<div class="space-y-4">
    <div class="rounded-xl border border-gray-200 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h4 class="text-base font-semibold text-gray-800 dark:text-gray-100">Add progress note</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400">Record how the student is progressing, what was covered, or next steps.</p>
            </div>
            <x-filament::button type="button" size="xs" color="gray" wire:click="showImportModal" wire:loading.attr="disabled">
                <span class="inline-flex items-center gap-1">
                    <x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                    <span>Import</span>
                </span>
            </x-filament::button>
        </div>
        <div class="mt-3">
            <form wire:submit.prevent="save" class="space-y-3">
                {{ $this->form }}
                @error('data.note')
                    <p class="text-xs text-rose-500">{{ $message }}</p>
                @enderror
                <div class="flex justify-end">
                    <x-filament::button type="submit" size="sm" wire:loading.attr="disabled">
                        <span class="inline-flex items-center gap-1">
                            <x-filament::icon icon="heroicon-o-check" class="h-4 w-4" />
                            <span>Save note</span>
                        </span>
                    </x-filament::button>
                </div>
            </form>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h4 class="text-base font-semibold text-gray-800 dark:text-gray-100">Progress history</h4>
        @if(empty($notes))
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">No progress notes recorded yet.</p>
        @else
            <div class="mt-3 space-y-3">
                @foreach($notes as $entry)
                    <div class="rounded-lg border border-gray-100 bg-gray-50 p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-300">{{ $entry['recorded_at'] }}</div>
                            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-300">
                                @if($entry['author'])
                                    <span>{{ $entry['author'] }}</span>
                                @endif
                                <button type="button" wire:click="delete({{ $entry['id'] }})" class="text-rose-500 hover:text-rose-600">
                                    <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <div class="mt-2 prose prose-sm max-w-none text-gray-800 dark:prose-invert dark:text-gray-200">{!! $entry['body'] !!}</div>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 flex justify-center">
                @if($hasMore)
                    <x-filament::button type="button" size="sm" color="gray" wire:click="loadMore" wire:loading.attr="disabled">
                        <span class="inline-flex items-center gap-1">
                            <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                            <span>Load more notes</span>
                        </span>
                    </x-filament::button>
                @elseif($showingAll && $page > 1)
                    <x-filament::button type="button" size="sm" color="gray" wire:click="showLess" wire:loading.attr="disabled">
                        <span class="inline-flex items-center gap-1">
                            <x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                            <span>Show latest only</span>
                        </span>
                    </x-filament::button>
                @endif
            </div>
        @endif
    </div>
    <x-filament::modal id="student-note-import" width="md">
        <x-slot name="heading">Import progress note text</x-slot>

        <form wire:submit.prevent="importUpload" class="space-y-4">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Choose a document and we will pull its text into the editor. Supported formats: .txt, .doc, .docx (max 2&nbsp;MB).
            </p>
            <input type="file" wire:model="upload" accept=".txt,.doc,.docx" class="text-sm">
            @error('upload')
                <p class="text-xs text-rose-500">{{ $message }}</p>
            @enderror
            <div class="flex justify-end gap-2">
                <x-filament::button type="button" color="gray" wire:click="cancelImport" wire:loading.attr="disabled">
                    Cancel
                </x-filament::button>
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="importUpload,upload">
                    <span class="inline-flex items-center gap-1">
                        <x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                        <span>Import text</span>
                    </span>
                </x-filament::button>
            </div>
        </form>
    </x-filament::modal>
</div>
