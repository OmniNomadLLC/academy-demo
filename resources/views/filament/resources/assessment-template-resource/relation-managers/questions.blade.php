<div class="space-y-4">
    <x-filament::tabs>
        @foreach ($this->skillCategoryTabs as $tab)
            <x-filament::tabs.item
                :active="$tab['value'] === $this->activeSkillCategory"
                wire:click="$set('activeSkillCategory', {{ \Illuminate\Support\Js::from($tab['value']) }})"
            >
                {{ $tab['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</div>
