<div class="space-y-4">
    <div class="rounded-xl border border-gray-200 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Manager</h3>
            </div>
            @if($currentManager)
                <span class="inline-flex -space-x-2 overflow-hidden rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                    <x-filament::icon icon="heroicon-o-user-circle" class="h-4 w-4" />
                    {{ $currentManager['name'] }}
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <x-filament::icon icon="heroicon-o-user" class="h-4 w-4" />
                    Unassigned
                </span>
            @endif
        </div>

        <div class="mt-3 space-y-3 text-sm text-gray-700 dark:text-gray-200">
            @if($currentManager)
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-800">
                    <div class="font-medium text-gray-600 dark:text-gray-300">{{ $currentManager['name'] }}</div>
                    <div class="text-xs text-gray-600 dark:text-gray-300">{{ $currentManager['email'] }}</div>
                    @if($currentManager['phone'])
                        <div class="text-xs text-gray-600 dark:text-gray-300">{{ $currentManager['phone'] }}</div>
                    @endif
                </div>
                @unless($readOnly)
                    <div class="flex gap-2">
                        <x-filament::button size="sm" color="gray" wire:click="removeManager" wire:loading.attr="disabled">
                            <x-filament::icon icon="heroicon-o-user-minus" class="h-4 w-4" />
                            Remove
                        </x-filament::button>
                    </div>
                @endunless
            @else
                <div class="text-xs text-gray-500 dark:text-gray-400">No manager linked yet.</div>
            @endif
        </div>
    </div>

    @unless($isUkManagerViewer)
        <div class="rounded-xl border border-dashed border-gray-300 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Assign existing manager</h4>
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">Select from existing UK managers.</p>
            <div class="space-y-3">
                <div class="flex flex-col gap-3 md:flex-row md:items-center">
                    <div class="grow">
                        <select wire:model="selectedManagerId" @disabled($readOnly) class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">Select manager…</option>
                            @foreach($managers as $manager)
                                <option value="{{ $manager['id'] }}">{{ $manager['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    @unless($readOnly)
                        <x-filament::button wire:click="assignExisting" wire:loading.attr="disabled" color="primary" icon="heroicon-o-user-plus">
                            Assign
                        </x-filament::button>
                    @endunless
                </div>
            </div>
            @if($readOnly)
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Read-only access – assignment actions are disabled.</p>
            @endif
        </div>

        <div class="rounded-xl border border-dashed border-gray-300 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center justify-between">
                <div>
                    <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Create new manager</h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Add a new manager and link immediately.</p>
                </div>
                @unless($readOnly)
                    <x-filament::button size="sm" color="gray" wire:click="toggleCreateForm" wire:loading.attr="disabled">
                        {{ $showCreateForm ? 'Cancel' : 'Add manager' }}
                    </x-filament::button>
                @endunless
            </div>

            @if($showCreateForm && ! $readOnly)
                <div class="mt-4 space-y-3">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Name</label>
                            <input type="text" wire:model.defer="managerName" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            @error('managerName') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Email</label>
                            <input type="email" wire:model.defer="managerEmail" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            @error('managerEmail') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Phone (optional)</label>
                            <input type="text" wire:model.defer="managerPhone" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                            @error('managerPhone') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <x-filament::button color="success" wire:click="createAndAssign" wire:loading.attr="disabled" class="inline-flex items-center">
                        <x-filament::icon icon="heroicon-o-check" class="h-4 w-4" />
                        Save & assign
                    </x-filament::button>
                </div>
            @elseif($readOnly)
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Create & assign actions are disabled for read-only users.</p>
            @endif
        </div>
    @endunless
</div>
