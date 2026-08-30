<form wire:submit.prevent="save" class="space-y-3">
    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Student notes</label>
    <textarea wire:model.defer="notes" rows="5" @disabled($readOnly) class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
    @error('notes') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
    @if($readOnly)
        <p class="text-xs text-gray-500 dark:text-gray-400">Read-only access – note updates are disabled.</p>
    @else
        <div class="flex justify-end">
            <x-filament::button type="submit" color="primary" size="sm">Save notes</x-filament::button>
        </div>
    @endif
</form>
