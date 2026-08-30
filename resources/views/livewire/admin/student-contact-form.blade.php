<form wire:submit.prevent="save" class="space-y-4">
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Email</label>
            <input type="email" wire:model.defer="email" @disabled($readOnly) class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
            @error('email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Phone</label>
            <input type="text" wire:model.defer="phone" @disabled($readOnly) class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
            @error('phone') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Location</label>
            <input type="text" wire:model.defer="location" @disabled($readOnly) class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
            @error('location') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Emergency contact</label>
            <input type="text" wire:model.defer="emergency_contact_name" @disabled($readOnly) class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
            @error('emergency_contact_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Emergency phone</label>
            <input type="text" wire:model.defer="emergency_contact_phone" @disabled($readOnly) class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
            @error('emergency_contact_phone') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Address</label>
            <textarea wire:model.defer="address" rows="2" @disabled($readOnly) class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
            @error('address') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
    </div>

    @if($readOnly)
        <p class="text-xs text-gray-500 dark:text-gray-400">Read-only: updates are disabled for this role.</p>
    @else
        <div class="flex justify-end">
            <x-filament::button type="submit" color="primary" size="sm">Save contact info</x-filament::button>
        </div>
    @endif
</form>
