<div>
    @if (! $formVisible)
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-600 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300">
            <p class="font-medium">No employment profile yet.</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Capture interests, availability, and notes to help employment teams.</p>
            @unless ($readOnly)
                <div class="mt-4">
                    <x-filament::button size="sm" wire:click="startCreating">Create profile</x-filament::button>
                </div>
            @endunless
        </div>
    @else
        <form wire:submit.prevent="save" class="space-y-6">
            <div class="grid gap-6 sm:grid-cols-2">
                <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800 dark:shadow-none">
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">Work experience</p>
                    <label class="mt-3 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model.defer="hasWorkExperience" @disabled($readOnly) class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600" />
                        <span>Has prior work experience</span>
                    </label>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800 dark:shadow-none">
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-200">Preferred hours</label>
                    <select wire:model.defer="preferredHours" @disabled($readOnly) class="mt-2 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="full_time">Full time</option>
                        <option value="part_time">Part time</option>
                        <option value="either">Either</option>
                    </select>
                    @error('preferredHours') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>

            @if (config('luminaworks.enabled'))
                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800 dark:shadow-none">
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-200">Postcode (for job matching)</label>
                        <input type="text" wire:model.defer="postcode" @disabled($readOnly) placeholder="e.g. EN1 1YU" class="mt-2 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                        @error('postcode') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800 dark:shadow-none">
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-200">Max travel distance (km)</label>
                        <input type="number" min="1" max="100" wire:model.defer="maxTravelKm" @disabled($readOnly) class="mt-2 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                        @error('maxTravelKm') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            <div>
                <label class="text-sm font-semibold text-gray-700 dark:text-gray-200">Notes</label>
                <textarea wire:model.defer="notes" rows="4" @disabled($readOnly) class="mt-2 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                @error('notes') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div>
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">Employment interests</p>
                    <div class="mt-3 space-y-2">
                        @forelse($employmentInterests as $interest)
                            <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                                <input
                                    type="checkbox"
                                    class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"
                                    value="{{ $interest['id'] }}"
                                    wire:model="selectedInterestIds"
                                    @disabled($readOnly)
                                />
                                <span>{{ $interest['name'] }}</span>
                            </label>
                        @empty
                            <p class="text-xs text-gray-500">No interests defined.</p>
                        @endforelse
                    </div>
                    @error('selectedInterestIds') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">Availability</p>
                    <div class="mt-3 space-y-2">
                        @forelse($employmentAvailabilityOptions as $option)
                            <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                                <input
                                    type="checkbox"
                                    class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"
                                    value="{{ $option['id'] }}"
                                    wire:model="selectedAvailabilityIds"
                                    @disabled($readOnly)
                                />
                                <span>{{ $option['name'] }}</span>
                            </label>
                        @empty
                            <p class="text-xs text-gray-500">No availability options defined.</p>
                        @endforelse
                    </div>
                    @error('selectedAvailabilityIds') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>

            @if($readOnly)
                <p class="text-xs text-gray-500 dark:text-gray-400">Read-only: your role cannot update employment profiles.</p>
            @else
                <div class="flex justify-end">
                    <x-filament::button type="submit" color="primary" size="sm">Save employment profile</x-filament::button>
                </div>
            @endif
        </form>
    @endif
    <div class="mt-8 rounded-xl bg-gray-50 p-5 shadow-sm dark:bg-transparent dark:shadow-none">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">🔥 Top Matches</h3>
        </div>
        @if (! empty($topMatches))
            <div class="mt-4 space-y-4">
                @foreach ($topMatches as $index => $match)
                    @php
                        $isTop = $index === 0;
                        $score = $match['score'];

                        if ($score >= 70) {
                            $barClass = 'bg-green-500';
                            $badgeClass = 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300';
                            $scoreLabel = 'Good match';
                        } elseif ($score >= 40) {
                            $barClass = 'bg-yellow-500';
                            $badgeClass = 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300';
                            $scoreLabel = 'Possible match';
                        } else {
                            $barClass = 'bg-red-500';
                            $badgeClass = 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
                            $scoreLabel = 'Low match';
                        }
                    @endphp
                    <div class="rounded-xl border p-4 shadow-sm transition hover:shadow-md hover:-translate-y-[1px]
                        {{ $isTop
                            ? 'border-green-200 bg-green-50 dark:border-green-700/60 dark:bg-green-900/15'
                            : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800' }}">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $match['job']['title'] }}
                                </p>
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                    ⭐ Top Match #{{ $index + 1 }}
                                </span>
                                @if ($isTop)
                                    <span class="block text-xs font-semibold text-green-600 dark:text-green-400">🔥 Recommended for this student</span>
                                @endif
                            </div>

                            <span class="px-3 py-1 text-sm font-bold rounded-full {{ $badgeClass }}">
                                {{ $match['score'] }}% · {{ $scoreLabel }}
                            </span>
                        </div>

                        <span class="mt-3 block text-xs text-gray-500">Match confidence</span>
                        <div class="mt-3 w-full h-3 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                            <div
                                class="h-3 rounded-full transition-all duration-500 {{ $barClass }}"
                                style="width: {{ $match['score'] }}%">
                            </div>
                        </div>

                        @if (! empty($match['reasons']))
                            <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                                @foreach ($match['reasons'] as $reason)
                                    <li class="flex items-center gap-2">
                                        <span class="text-primary-500 text-xs">✔</span>
                                        <span>{{ $reason }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                No matches yet — complete the employment profile to see job recommendations.
            </p>
        @endif
    </div>
</div>
