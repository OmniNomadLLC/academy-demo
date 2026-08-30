<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section class="max-w-none">
            <x-slot name="heading">
                {{ $meta['calendar'] ?? 'Class roster' }}
            </x-slot>

            <x-slot name="description">
                @if(($meta['hasSessions'] ?? false) === false)
                    We could not find any sessions matching this request.
                @else
                    @php
                        $timeLabel = collect([
                            $meta['start'] ?? null,
                            $meta['end'] ? __('to').' '.$meta['end'] : null,
                        ])->filter()->implode(' ');
                    @endphp
                    {{ $timeLabel ?: 'Scheduled time unavailable' }}
                    <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-gray-500">
                        @if(!empty($meta['location']))
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-globe-alt" class="h-4 w-4" />
                                {{ $meta['location'] }}
                            </span>
                        @endif
                        @if(!empty($meta['teacher']))
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-user" class="h-4 w-4" />
                                {{ $meta['teacher'] }}
                            </span>
                        @endif
                    </div>
                @endif
            </x-slot>
        </x-filament::section>

        @if(empty($members))
            <x-filament::section class="max-w-none text-center text-sm text-gray-500">
                <x-slot name="heading">No students yet</x-slot>
                <x-slot name="description">
                    Once learners are linked to this class, you’ll see them here for quick follow-up.
                </x-slot>
            </x-filament::section>
        @else
            <x-filament::section class="max-w-none">
                <x-slot name="heading">Learners</x-slot>
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <table class="w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/70">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">
                                <th scope="col" class="px-6 py-4">Student</th>
                                <th scope="col" class="px-6 py-4">Contact</th>
                                <th scope="col" class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                            @foreach($members as $member)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/80">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ $member['name'] }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                        @if(!empty($member['email']))
                                            <div class="flex items-center gap-2">
                                                <x-filament::icon icon="heroicon-o-envelope" class="h-4 w-4 text-gray-400" />
                                                <span>{{ $member['email'] }}</span>
                                            </div>
                                        @else
                                            <span class="text-gray-400 dark:text-gray-500">—</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        @if($member['profile_url'])
                                            <x-filament::button tag="a" href="{{ $member['profile_url'] }}" size="sm" color="primary" icon="heroicon-o-user-circle">
                                                Open profile
                                            </x-filament::button>
                                        @else
                                            <x-filament::badge color="gray">Not yet linked</x-filament::badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
