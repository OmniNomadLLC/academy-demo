<div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">💼 Lumina Works — Job matches</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Live regional vacancies matched on distance, hours and English level. The student applies themselves via the listing.</p>
        </div>
        @unless ($readOnly)
            <x-filament::button size="sm" wire:click="refreshMatches" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="refreshMatches">Refresh matches</span>
                <span wire:loading wire:target="refreshMatches">Matching…</span>
            </x-filament::button>
        @endunless
    </div>

    @if ($matches->isEmpty())
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            No matches yet. Add a postcode to the employment profile and press "Refresh matches".
        </p>
    @else
        <div class="mt-4 space-y-3">
            @foreach ($matches as $match)
                @php
                    $score = $match->score;
                    $badgeClass = $score >= 70
                        ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                        : ($score >= 40
                            ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300'
                            : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300');
                @endphp
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800 {{ $match->status === 'applied' ? 'opacity-80 ring-1 ring-green-400' : '' }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $match->job->title }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $match->job->employer_name ?? 'Unknown employer' }} · {{ $match->job->location_name }}
                                @if ($match->distance_km !== null) · {{ $match->distance_km }} km @endif
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold {{ $badgeClass }}">{{ $score }}%</span>
                    </div>
                    @if ($match->reason)
                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">✔ {{ $match->reason }}</p>
                    @endif
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <a href="{{ $match->job->apply_url }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">
                            View & apply on job site →
                        </a>
                        @unless ($readOnly)
                            @if ($packs->has($match->lumina_works_job_id))
                                <span class="text-xs font-medium text-indigo-500 dark:text-indigo-400">📖 Coach pack ready</span>
                            @else
                                <button type="button" wire:click="generateCoachPack({{ $match->lumina_works_job_id }})" wire:loading.attr="disabled" class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                    <span wire:loading.remove wire:target="generateCoachPack({{ $match->lumina_works_job_id }})">Generate coach pack</span>
                                    <span wire:loading wire:target="generateCoachPack({{ $match->lumina_works_job_id }})">Generating…</span>
                                </button>
                            @endif
                        @endunless
                        @unless ($readOnly)
                            @if ($match->status !== 'applied')
                                <button type="button" wire:click="markApplied({{ $match->id }})" class="text-xs font-medium text-green-600 hover:underline dark:text-green-400">Mark as applied</button>
                            @else
                                <span class="text-xs font-semibold text-green-600 dark:text-green-400">✓ Applied</span>
                            @endif
                            <button type="button" wire:click="dismiss({{ $match->id }})" class="text-xs font-medium text-gray-400 hover:text-gray-600 hover:underline dark:hover:text-gray-300">Dismiss</button>
                        @endunless
                    </div>
                </div>
            @endforeach
        </div>
        <p class="mt-4 text-[11px] text-gray-400 dark:text-gray-500">Jobs by <a href="https://www.adzuna.co.uk" target="_blank" rel="noopener noreferrer" class="underline">Adzuna</a></p>

        <div class="mt-4 rounded-lg border border-dashed border-gray-300 p-3 dark:border-gray-600">
            <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">📱 Student coach link (valid 7 days, no login needed)</p>
            <input type="text" readonly value="{{ $this->studentCoachLink }}" onclick="this.select()" class="mt-2 w-full rounded border-gray-300 bg-gray-50 text-[11px] text-gray-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300" />
            <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">Share via WhatsApp/SMS — the student sees their matches, apply links and coach packs in simple English.</p>
        </div>
    @endif

    @if ($applications->isNotEmpty())
        <div class="mt-8">
            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-100">📋 Applications</h4>
            <div class="mt-3 space-y-2">
                @foreach ($applications as $application)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $application->job->title }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $application->job->employer_name ?? 'Unknown employer' }}
                                · applied {{ $application->applied_at?->format('d M Y') }}
                                @if ($application->interview_at) · interview {{ $application->interview_at->format('d M Y') }} @endif
                            </p>
                        </div>
                        @php
                            $confirmed = \App\Models\LuminaWorksEmployerVerification::where('lumina_works_application_id', $application->id)->whereNotNull('confirmed_at')->latest('confirmed_at')->first();
                        @endphp
                        @if ($confirmed)
                            <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400" title="Confirmed by {{ $confirmed->contact_name }} on {{ $confirmed->confirmed_at->format('d M Y') }}">✓ employer: {{ str_replace('_', ' ', $confirmed->result) }}</span>
                        @elseif (! $readOnly)
                            <button type="button" wire:click="makeEmployerLink({{ $application->id }})" class="text-xs font-medium text-amber-600 hover:underline dark:text-amber-400">Employer link</button>
                        @endif
                        @if ($readOnly)
                            <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ str_replace('_', ' ', $application->status) }}</span>
                        @else
                            <select wire:change="advanceApplication({{ $application->id }}, $event.target.value)" class="rounded-lg border-gray-300 text-xs shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                @foreach (\App\Models\LuminaWorksApplication::STATUSES as $status)
                                    <option value="{{ $status }}" @selected($application->status === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endforeach
            </div>
            @if ($employerLink)
                <div class="mt-3 rounded-lg border border-dashed border-amber-300 p-3 dark:border-amber-600">
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">✉️ Employer verification link (valid 14 days, one-time use)</p>
                    <input type="text" readonly value="{{ $employerLink }}" onclick="this.select()" class="mt-2 w-full rounded border-gray-300 bg-gray-50 text-[11px] text-gray-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300" />
                    <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">Email this to the employer contact — they confirm attended / no-show / hired on one screen.</p>
                </div>
            @endif
            <p class="mt-2 text-[11px] text-gray-400 dark:text-gray-500">Every status change is recorded in the tamper-evident Lumina Works evidence log.</p>
        </div>
    @endif
</div>
