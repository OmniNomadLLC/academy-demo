<x-filament::page>
    @push('styles')
        <style>
            .cp-page { width: 100%; }
            .cp-card .fi-card { border-radius: 14px; box-shadow: 0 6px 18px rgba(16,24,40,.05); }
            .cp-section-title { font-weight: 700; letter-spacing: .15px; }
            .cp-grid-label { color: #6b7280; font-size: .75rem; text-transform: uppercase; letter-spacing: .08em; }
            .cp-placeholder { border: 1px dashed #cbd5f5; border-radius: 12px; padding: 1rem; background: #f8fafc; }
            .cp-placeholder h4 { font-weight: 600; font-size: .9rem; }
            .cp-placeholder p { font-size: .75rem; color: #475569; }
        </style>
    @endpush

    @php($queueStatus = $this->queueStatus())
    <div class="cp-page space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm text-gray-500">Operate Acuity imports, queues, and health for this demo environment.</div>
            <div class="flex items-center gap-2">
                <x-filament::button size="xs" color="gray" wire:click="openWhyStuck">Why am I stuck?</x-filament::button>
            </div>
        </div>

        @if($alert = $this->queueAlert())
            <div class="rounded-lg border {{ $alert['level'] === 'danger' ? 'border-rose-300 bg-rose-50 text-rose-800' : ($alert['level'] === 'info' ? 'border-sky-300 bg-sky-50 text-sky-800' : 'border-amber-300 bg-amber-50 text-amber-800') }} p-3 space-y-2">
                <div class="font-semibold">{{ $alert['level'] === 'info' ? 'Demo note' : 'Queue warning' }}</div>
                <div class="text-sm">{{ $alert['message'] }}</div>
                @php($qs = $this->queueStatus())
                @if(($alert['level'] === 'danger') && (int)($qs['active_processes'] ?? 0) === 0)
                    <div class="flex flex-wrap gap-2 text-xs">
                        <x-filament::button size="xs" color="gray" href="#horizon-fix">Open setup guide</x-filament::button>
                        <x-filament::button size="xs" color="gray" wire:click="openWhyStuck">Why stuck?</x-filament::button>
                        <x-filament::button size="xs" color="danger" wire:click="restartHorizon" wire:loading.attr="disabled">Restart Horizon</x-filament::button>
                    </div>
                @endif
            </div>
        @endif

        @php($stats = $this->summaryStats())
        <x-filament::card class="cp-card">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                <div>
                    <div class="cp-grid-label">Local students</div>
                    <div class="text-2xl font-semibold">{{ number_format($stats['local_students'] ?? 0) }}</div>
                </div>
                <div>
                    <div class="cp-grid-label">Acuity clients (cached)</div>
                    <div class="text-2xl font-semibold">{{ number_format($stats['acuity_clients'] ?? 0) }}</div>
                    @if(!empty($stats['acuity_at']) && empty($stats['acuity_is_estimate']))
                        <div class="text-xs text-gray-500">Updated {{ \Carbon\Carbon::parse($stats['acuity_at'])->diffForHumans() }}</div>
                    @elseif(($stats['acuity_is_estimate'] ?? false) === true)
                        <div class="text-xs text-amber-600">Showing local count (cache not yet populated).</div>
                    @endif
                </div>
                <div>
                    <div class="cp-grid-label">App environment</div>
                    <div class="text-2xl font-semibold">{{ strtoupper($queueStatus['app_env'] ?? app()->environment()) }}</div>
                </div>
            </div>
        </x-filament::card>

        <x-filament::card class="cp-card" id="staging-guide">
            <div x-data="{ open: @entangle('guideOpen') }" class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-lg cp-section-title">How to load Acuity data (demo)</div>
                        <div class="text-xs text-gray-500">Docs last updated {{ \Carbon\Carbon::parse(\App\Filament\Pages\ControlPanel::GUIDE_LAST_UPDATED)->format('Y-m-d H:i T') }}</div>
                    </div>
                    <x-filament::button size="xs" color="gray" x-on:click="open = ! open">
                        <span x-show="!open">Open guide</span>
                        <span x-show="open">Hide guide</span>
                    </x-filament::button>
                </div>

                <div x-show="open" x-transition class="space-y-4">
                    <div class="space-y-3 text-sm" id="staging-run-mode">
                        <div class="font-semibold">Run Mode Checklist</div>
                        <ol class="list-decimal ml-5 space-y-3">
                            <li id="guide-step-queues">
                                <div class="font-medium">Prep queues</div>
                        <div class="text-gray-600">Check Horizon + Redis in <a href="#queue-health" class="underline text-indigo-600">Queue Health</a>. If you see “sync”, update <code>QUEUE_CONNECTION</code> before running imports.</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <x-filament::button size="xs" color="gray" wire:click="quickFillDelta('last-7')">Reset Delta Since</x-filament::button>
                                    <a href="#queue-health" class="fi-link text-xs text-indigo-600">Jump to Queue Health</a>
                                </div>
                            </li>
                            <li id="guide-step-clients">
                                <div class="font-medium">Run Clients Sync first</div>
                                <div class="text-gray-600">Use <a href="#sync-tools" class="underline text-indigo-600">Sync Tools → Clients Sync</a>. Limit = 0 (all), Page size ≤ 200.</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <x-filament::button size="xs" color="gray" wire:click="quickFillAppointments('this-month')">Prep Appointment Defaults</x-filament::button>
                                    <x-filament::button size="xs" color="gray" wire:click="quickFillDelta('last-7')">Keep Delta -7d</x-filament::button>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">Expected: toast + new <code>acuity:sync-clients</code> entry under Recent Sync Logs.</div>
                            </li>
                            <li id="guide-step-appointments">
                                <div class="font-medium">Run Appointments Window Sync</div>
                                <div class="text-gray-600">Set From = July 1, To = today, Slice = 7, Page = 100, Limit = 0, then queue. Confirm if “heavy run” prompt appears.</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <x-filament::button size="xs" color="gray" wire:click="quickFillAppointments('july-onward')">Quick Fill July → Today</x-filament::button>
                                    <x-filament::button size="xs" color="gray" wire:click="quickFillAppointments('this-month')">Quick Fill This Month</x-filament::button>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">Watch <code>acuity:sync-appointments</code> output for created/updated counts.</div>
                            </li>
                            <li id="guide-step-logs">
                                <div class="font-medium">Verify logs & metrics</div>
                                <div class="text-gray-600">Open <a href="#sync-logs" class="underline text-indigo-600">Recent Sync Logs</a> and inspect the latest entries. Confirm students count increases in the dashboard.</div>
                            </li>
                            <li id="guide-step-backfills">
                                <div class="font-medium">Run post-sync backfills</div>
                                <div class="text-gray-600">In <a href="#backfills" class="underline text-indigo-600">Backfills</a>, run Next Appointment, First/Last, Active Flag so dashboards refresh.</div>
                                <div class="text-xs text-gray-500 mt-1">If something fails, rerun from the same button or replay via logs.</div>
                            </li>
                        </ol>
                    </div>

                    <div class="space-y-2 text-sm" id="staging-guide-commands">
                        <div class="font-semibold">Helpful CLI commands</div>
                        <div class="space-y-2">
                            @foreach($this->commandsForGuide() as $label => $command)
                                <div x-data="{ copied: false }" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white/70 px-3 py-2">
                                    <div>
                                        <div class="font-medium">{{ $label }}</div>
                                        <div class="font-mono text-xs text-gray-600">{{ $command }}</div>
                                    </div>
                                    <button type="button" class="text-xs text-indigo-600 underline" x-on:click="navigator.clipboard.writeText('{{ $command }}').then(() => { copied = true; setTimeout(() => copied = false, 1500); })">
                                        <span x-show="!copied">Copy</span>
                                        <span x-show="copied">Copied!</span>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>

                <div class="space-y-2 text-sm" id="staging-guide-verification">
                        <div class="font-semibold">After each step, confirm</div>
                        <ul class="list-disc ml-5 space-y-1 text-gray-600">
                            <li>Queue Health shows <span class="font-mono">redis</span> + Horizon Running (v{{ $queueStatus['horizon_version'] ?? '—' }}).</li>
                            <li>Recent Sync Logs display <code>acuity:sync-clients</code> and <code>acuity:sync-appointments</code> with status <span class="text-emerald-600">success</span>.</li>
                            <li>Students count increases; dashboard data populates for UK/ES/FR panels.</li>
                        </ul>
                    </div>

                    <div class="space-y-2" id="staging-guide-screenshots">
                        <div class="font-semibold text-sm">Screenshot placeholders</div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div class="cp-placeholder">
                                <div class="flex items-center justify-between">
                                    <h4>[SS-1] Sync Tools panel</h4>
                                    <a href="#ss-sync-tools" class="text-xs text-indigo-600 underline">Upload/Replace</a>
                                </div>
                                <p>Before run — set Slice days = 7, Page size = 100, Limit = 0.</p>
                            </div>
                            <div class="cp-placeholder">
                                <div class="flex items-center justify-between">
                                    <h4>[SS-2] Queue Health</h4>
                                    <a href="#ss-queue" class="text-xs text-indigo-600 underline">Upload/Replace</a>
                                </div>
                                <p>Horizon Running, Effective driver = redis.</p>
                            </div>
                            <div class="cp-placeholder">
                                <div class="flex items-center justify-between">
                                    <h4>[SS-3] Recent Sync Logs</h4>
                                    <a href="#ss-logs" class="text-xs text-indigo-600 underline">Upload/Replace</a>
                                </div>
                                <p>Example success entry with created / updated counts.</p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2 text-sm" id="staging-guide-loom">
                        <div class="font-semibold">Loom-style run script</div>
                        <div x-data="{ text: @js($this->loomScriptText()), copied: false }" class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <span class="text-xs text-slate-600">Read these lines while recording, or copy the full script.</span>
                                <button type="button" class="text-xs text-indigo-600 underline" x-on:click="navigator.clipboard.writeText(text).then(() => { copied = true; setTimeout(() => copied = false, 1500); })">
                                    <span x-show="!copied">Copy script</span>
                                    <span x-show="copied">Copied!</span>
                                </button>
                            </div>
                            <ol class="list-decimal ml-5 space-y-2 text-xs text-slate-700 break-words">
                                @foreach($this->loomScriptLines() as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ol>
                        </div>
                    </div>

                    <div class="space-y-2 text-sm" id="horizon-fix">
                        <div class="font-semibold">Horizon quick fix</div>
                        <ol class="list-decimal ml-5 space-y-2 text-gray-600 text-xs">
                            <li>Set <code>minProcesses</code>/<code>maxProcesses</code> for each environment in <code>config/horizon.php</code> (prod 2→16, staging 1→8, local 1→2) and deploy.</li>
                            <li>Reload config caches: <code>php artisan config:clear</code>, <code>php artisan cache:clear</code>, <code>php artisan config:cache</code>, then <code>php artisan horizon:terminate</code>.</li>
                            <li>Ensure a daemon (systemd/Supervisor/Forge) is running <code>php artisan horizon</code> so workers start automatically.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </x-filament::card>

        <section id="sync-tools" class="space-y-4">
        <x-filament::card class="cp-card">
            <div class="text-lg cp-section-title mb-4">Sync Tools</div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div>
                    <div class="font-semibold mb-2">Delta Sync</div>
                    <div class="space-y-3">
                        <x-filament::input.wrapper>
                            <label class="fi-input-label">Since (e.g. -7 days)</label>
                            <x-filament::input type="text" wire:model.defer="deltaSince" />
                        </x-filament::input.wrapper>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                            <x-filament::input.wrapper>
                                <label class="fi-input-label">From</label>
                                <x-filament::input type="text" wire:model.defer="deltaFrom" placeholder="YYYY-MM-DD" />
                            </x-filament::input.wrapper>
                            <x-filament::input.wrapper>
                                <label class="fi-input-label">To</label>
                                <x-filament::input type="text" wire:model.defer="deltaTo" placeholder="YYYY-MM-DD" />
                            </x-filament::input.wrapper>
                        </div>
                        <label class="flex items-center gap-2 text-xs text-gray-600">
                            <input type="checkbox" wire:model="deltaDry" class="rounded border-gray-300" />
                            <span>Dry run (no writes)</span>
                        </label>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <x-filament::button size="xs" color="gray" wire:click="quickFillDelta('last-7')">Last 7 days</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="quickFillDelta('last-30')">Last 30 days</x-filament::button>
                        </div>
                        <div>
                            <x-filament::button color="primary" wire:click="queueDeltaSync" wire:loading.attr="disabled" icon="heroicon-m-play" wire:target="queueDeltaSync">
                                Run Delta Sync
                                <x-filament::loading-indicator class="ml-2 h-4 w-4" wire:loading wire:target="queueDeltaSync" />
                            </x-filament::button>
                            <div class="text-xs text-gray-500 mt-1">Runs <code>acuity:delta-sync</code>.</div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="font-semibold mb-2">Appointments Window Sync</div>
                    <div class="space-y-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                            <x-filament::input.wrapper>
                                <label class="fi-input-label">From</label>
                                <x-filament::input type="text" wire:model.defer="apptFrom" placeholder="YYYY-MM-DD" />
                            </x-filament::input.wrapper>
                            <x-filament::input.wrapper>
                                <label class="fi-input-label">To</label>
                                <x-filament::input type="text" wire:model.defer="apptTo" placeholder="YYYY-MM-DD" />
                            </x-filament::input.wrapper>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <x-filament::input.wrapper>
                                <label class="fi-input-label">Slice days</label>
                                <x-filament::input type="number" min="1" max="30" wire:model.defer="apptSliceDays" />
                            </x-filament::input.wrapper>
                            <x-filament::input.wrapper>
                                <label class="fi-input-label">Page size</label>
                                <x-filament::input type="number" min="25" max="1000" step="25" wire:model.defer="apptPageSize" />
                            </x-filament::input.wrapper>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <x-filament::input.wrapper>
                                <label class="fi-input-label">Limit (0 = all)</label>
                                <x-filament::input type="number" min="0" step="50" wire:model.defer="apptLimit" />
                            </x-filament::input.wrapper>
                            <x-filament::input.wrapper>
                                <label class="fi-input-label">Slice index</label>
                                <x-filament::input type="number" min="1" wire:model.defer="apptSliceIndex" placeholder="optional" />
                            </x-filament::input.wrapper>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <x-filament::input.wrapper>
                                <label class="fi-input-label">Max retries</label>
                                <x-filament::input type="number" min="0" max="10" wire:model.defer="apptMaxRetries" />
                            </x-filiment::input.wrapper>
                            <x-filament::input.wrapper>
                                <label class="fi-input-label">Retry base (ms)</label>
                                <x-filament::input type="number" min="0" max="5000" step="100" wire:model.defer="apptRetryBaseMs" />
                            </x-filament::input.wrapper>
                        </div>
                        <div class="flex flex-wrap gap-3 text-xs text-gray-600">
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="apptDryRun" class="rounded border-gray-300" />Dry run</label>
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="apptLinkAfterSlice" class="rounded border-gray-300" />Link after slice</label>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <x-filament::button size="xs" color="gray" wire:click="quickFillAppointments('july-onward')">July onward</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="quickFillAppointments('this-month')">This month</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="quickFillAppointments('last-14')">Last 14 days</x-filament::button>
                        </div>
                        <div>
                            <x-filament::button color="primary" wire:click="queueAppointmentsSync" wire:loading.attr="disabled" icon="heroicon-m-play" wire:target="queueAppointmentsSync">
                                Run Appointments Sync
                                <x-filament::loading-indicator class="ml-2 h-4 w-4" wire:loading wire:target="queueAppointmentsSync" />
                            </x-filament::button>
                            <div class="text-xs text-gray-500 mt-1">Runs <code>acuity:sync-appointments</code>.</div>
                        </div>
                        <div>
                            <x-filament::button color="success" wire:click="queueAppointmentsImportRun" wire:loading.attr="disabled" icon="heroicon-m-queue-list" wire:target="queueAppointmentsImportRun">
                                Queue Bulk Import Run
                                <x-filament::loading-indicator class="ml-2 h-4 w-4" wire:loading wire:target="queueAppointmentsImportRun" />
                            </x-filament::button>
                            <div class="text-xs text-gray-500 mt-1">Creates an import run that slices the window and queues Horizon jobs.</div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="font-semibold mb-2">Clients Sync</div>
                    <div class="space-y-3">
                        <x-filament::input.wrapper>
                            <label class="fi-input-label">Limit (0 = all)</label>
                            <x-filament::input type="number" min="0" step="50" wire:model.defer="clientLimit" />
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper>
                            <label class="fi-input-label">Page size</label>
                            <x-filament::input type="number" min="25" max="1000" step="25" wire:model.defer="clientPageSize" />
                        </x-filament::input.wrapper>
                        <div>
                            <x-filament::button color="primary" wire:click="queueClientsSync" wire:loading.attr="disabled" icon="heroicon-m-play" wire:target="queueClientsSync">
                                Run Clients Sync
                                <x-filament::loading-indicator class="ml-2 h-4 w-4" wire:loading wire:target="queueClientsSync" />
                            </x-filament::button>
                            <div class="text-xs text-gray-500 mt-1">Runs <code>acuity:sync-clients</code>.</div>
                        </div>
                    </div>
                </div>
            </div>
        </x-filament::card>
        </section>

        <section id="backfills" class="space-y-4">
        <x-filament::card class="cp-card">
            <div class="text-lg cp-section-title mb-4">Backfills</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div class="space-y-2 text-gray-700">
                    <x-filament::button color="primary" wire:click="runBackfillMetadata" wire:loading.attr="disabled" icon="heroicon-m-arrow-path">
                        Run Class Session Metadata
                    </x-filament::button>
                    <div class="text-xs text-gray-500">Runs <code>backfill:class-session-metadata --limit=0</code>.</div>
                </div>
                <div class="space-y-2 text-gray-700">
                    <x-filament::button color="primary" wire:click="runTeacherAssignmentSync" wire:loading.attr="disabled" icon="heroicon-m-user-group">
                        Relink Teacher Assignments
                    </x-filament::button>
                    <div class="text-xs text-gray-500">Runs <code>teacher-assignments:sync</code> so portals & reports pick up new mappings.</div>
                </div>
                <div class="space-y-2 text-gray-700">
                    <x-filament::button color="primary" wire:click="runBackfillRegionFlags" wire:loading.attr="disabled" icon="heroicon-m-arrow-path">
                        Run Region Flags
                    </x-filament::button>
                    <div class="text-xs text-gray-500">Runs <code>students:backfill-region-flags --chunk=500</code>.</div>
                </div>
                <div class="space-y-2 text-gray-700">
                    <x-filament::button color="primary" wire:click="runBackfillNextAppointment" wire:loading.attr="disabled" icon="heroicon-m-arrow-path">
                        Run Next Appointment
                    </x-filament::button>
                    <div class="text-xs text-gray-500">Runs <code>students:backfill-next-appointment --chunk=500 --horizon=365</code>.</div>
                </div>
                <div class="space-y-2 text-gray-700">
                    <x-filament::button color="primary" wire:click="runBackfillFirstLast" wire:loading.attr="disabled" icon="heroicon-m-arrow-path">
                        Run First / Last Dates
                    </x-filament::button>
                    <div class="text-xs text-gray-500">Runs <code>students:backfill-first-last --chunk=1000</code>.</div>
                </div>
                <div class="space-y-2 text-gray-700">
                    <x-filament::button color="primary" wire:click="runBackfillActiveFlag" wire:loading.attr="disabled" icon="heroicon-m-arrow-path">
                        Run Active Flag
                    </x-filament::button>
                    <div class="text-xs text-gray-500">Runs <code>students:update-active-flag --days=90</code>.</div>
                </div>
            </div>
        </x-filament::card>
        </section>

        <section id="bulk-imports" class="space-y-4">
        <x-filament::card class="cp-card">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <div class="text-lg cp-section-title">Bulk Appointment Imports</div>
                    <div class="text-xs text-gray-500">Queued slice runs stay on the <span class="font-mono">acuity</span> queue so Horizon can throttle them.</div>
                </div>
            </div>

            @php($statusColors = [
                'pending' => 'bg-sky-100 text-sky-700',
                'running' => 'bg-emerald-100 text-emerald-700',
                'paused' => 'bg-amber-100 text-amber-700',
                'completed' => 'bg-emerald-200 text-emerald-800',
                'failed' => 'bg-rose-100 text-rose-700',
                'cancelled' => 'bg-gray-200 text-gray-700',
            ])
            @forelse($this->importRuns() as $run)
                @php($statusColor = $statusColors[$run->status] ?? 'bg-slate-200 text-slate-700')
                <div class="rounded-lg border border-slate-200 bg-white p-4 space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold">Run #{{ $run->id }}</div>
                            <div class="text-xs text-gray-500">{{ $run->window_start }} → {{ $run->window_end }}</div>
                            <div class="text-xs text-gray-400">Queued {{ optional($run->created_at)->diffForHumans() ?? '—' }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            @if($run->dry_run)
                                <span class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-[11px] font-medium text-purple-700">Dry run</span>
                            @endif
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium {{ $statusColor }}">{{ ucfirst($run->status) }}</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs text-gray-600">
                        <div>
                            <div class="uppercase tracking-wide text-[10px]">Slices</div>
                            <div class="text-sm font-semibold text-gray-900">{{ $run->processed_slices }} / {{ $run->total_slices }}</div>
                        </div>
                        <div>
                            <div class="uppercase tracking-wide text-[10px]">Fetched</div>
                            <div class="text-sm font-semibold text-gray-900">{{ number_format($run->fetched_count) }}</div>
                        </div>
                        <div>
                            <div class="uppercase tracking-wide text-[10px]">Created / Updated</div>
                            <div class="text-sm font-semibold text-gray-900">{{ number_format($run->created_count) }} / {{ number_format($run->updated_count) }}</div>
                        </div>
                        <div>
                            <div class="uppercase tracking-wide text-[10px]">Unlinked</div>
                            <div class="text-sm font-semibold text-gray-900">{{ number_format($run->unlinked_count) }}</div>
                        </div>
                        <div>
                            <div class="uppercase tracking-wide text-[10px]">Retries</div>
                            <div class="text-sm font-semibold text-gray-900">{{ number_format($run->retries) }}</div>
                        </div>
                        <div>
                            <div class="uppercase tracking-wide text-[10px]">Last Activity</div>
                            <div class="text-sm font-semibold text-gray-900">{{ optional($run->last_activity_at)->diffForHumans() ?? '—' }}</div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        @if(in_array($run->status, [\App\Models\AcuityImportRun::STATUS_PENDING, \App\Models\AcuityImportRun::STATUS_RUNNING], true))
                            <x-filament::button size="xs" color="warning" wire:click="pauseImportRun({{ $run->id }})" wire:loading.attr="disabled">
                                Pause
                            </x-filament::button>
                        @endif
                        @if($run->status === \App\Models\AcuityImportRun::STATUS_PAUSED)
                            <x-filament::button size="xs" color="success" wire:click="resumeImportRun({{ $run->id }})" wire:loading.attr="disabled">
                                Resume
                            </x-filament::button>
                        @endif
                        @if(!in_array($run->status, [\App\Models\AcuityImportRun::STATUS_COMPLETED, \App\Models\AcuityImportRun::STATUS_CANCELLED, \App\Models\AcuityImportRun::STATUS_FAILED], true))
                            <x-filament::button size="xs" color="danger" wire:click="cancelImportRun({{ $run->id }})" wire:loading.attr="disabled">
                                Cancel
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-sm text-gray-500">No bulk import runs have been queued yet.</div>
            @endforelse
        </x-filament::card>
        </section>

        <section id="audit" class="space-y-4">
        <x-filament::card class="cp-card">
            <div class="text-lg cp-section-title mb-4">Audit &amp; Reconcile</div>
            <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                    <x-filament::input.wrapper>
                        <label class="fi-input-label">From (YYYY-MM-DD)</label>
                        <x-filament::input type="text" wire:model.defer="auditFrom" />
                    </x-filament::input.wrapper>
                    <x-filament::input.wrapper>
                        <label class="fi-input-label">To (YYYY-MM-DD)</label>
                        <x-filament::input type="text" wire:model.defer="auditTo" />
                    </x-filament::input.wrapper>
                    <x-filament::input.wrapper class="md:col-span-2">
                        <label class="fi-input-label">Calendar ID</label>
                        <select wire:model.defer="auditCalendarId" class="fi-input block w-full rounded-lg border-gray-300">
                            <option value="">— select —</option>
                            @foreach($this->availableAuditCalendars() as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-filament::input.wrapper>
                    <x-filament::input.wrapper>
                        <label class="fi-input-label">Calendar Name</label>
                        <x-filament::input type="text" wire:model.defer="auditCalendarName" />
                    </x-filament::input.wrapper>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                    <x-filament::button size="xs" color="gray" wire:click="setAuditPreset('this-month')">This month</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="setAuditPreset('last-30')">Last 30 days</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="setAuditPreset('next-45')">Next 45 days</x-filament::button>
                </div>
                <label class="flex items-center gap-2 text-xs text-gray-600">
                    <input type="checkbox" wire:model="auditFillMissing" class="rounded border-gray-300" />
                    <span>Fill missing (dispatch imports)</span>
                </label>
                <div class="flex flex-wrap gap-3">
                    <div class="space-y-1 text-gray-700">
                        <x-filament::button color="primary" wire:click="auditCalendarWindow" wire:loading.attr="disabled" icon="heroicon-m-magnifying-glass">
                            Run Audit Window
                        </x-filament::button>
                        <div class="text-xs text-gray-500">Runs <code>acuity:audit-window</code>.</div>
                    </div>
                    <div class="space-y-1 text-gray-700">
                        <x-filament::button color="primary" wire:click="showLastAuditOutput" wire:loading.attr="disabled" icon="heroicon-m-eye">
                            View Latest Audit
                        </x-filament::button>
                        <div class="text-xs text-gray-500">Loads last <code>acuity:audit-window</code> output.</div>
                    </div>
                    <div class="space-y-1 text-gray-700">
                        <x-filament::button color="primary" wire:click="rerunLastAudit" wire:loading.attr="disabled" icon="heroicon-m-arrow-path">
                            Re-run Last Audit
                        </x-filament::button>
                        <div class="text-xs text-gray-500">Reuses previous audit parameters.</div>
                    </div>
                </div>
            </div>
        </x-filament::card>
        </section>

        <section id="queue-health" class="space-y-4">
        <x-filament::card class="cp-card">
            <div class="text-lg cp-section-title mb-4">Queue Health &amp; Diagnostics</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="cp-grid-label">Effective queue driver</div>
                    <div class="text-xl font-semibold {{ ($queueStatus['driver'] ?? '') === 'redis' ? 'text-emerald-600' : 'text-amber-600' }}">{{ $queueStatus['driver'] ?? 'Not available' }}</div>
                    @if(($queueStatus['driver'] ?? '') === 'sync')
                        @if(config('app.demo_mode'))
                            <div class="text-xs text-gray-500 mt-1">This demo runs jobs inline (<code>QUEUE_CONNECTION=sync</code>), so imports finish without workers.</div>
                        @else
                            <div class="text-xs text-gray-500 mt-1">Set <code>QUEUE_CONNECTION=redis</code> to enable Horizon workers.</div>
                        @endif
                    @endif
                    @if(($queueStatus['redis_available'] ?? null) === false)
                        <div class="mt-2 inline-flex items-center rounded bg-rose-100 px-2 py-1 text-xs text-rose-700">Redis unreachable</div>
                    @elseif(($queueStatus['redis_available'] ?? null) === true && !empty($queueStatus['redis_version']))
                        <div class="text-xs text-gray-500 mt-1">Redis {{ $queueStatus['redis_version'] }}</div>
                    @endif
                </div>
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="cp-grid-label">Horizon</div>
                    <div class="text-xl font-semibold {{ ($queueStatus['horizon_running'] ?? false) ? 'text-emerald-600' : (config('app.demo_mode') ? 'text-gray-500' : 'text-rose-600') }}">
                        {{ ($queueStatus['horizon_running'] ?? false) ? 'Running' : (config('app.demo_mode') ? 'Off (demo runs sync)' : 'Not running') }}
                        @if(!empty($queueStatus['horizon_version']))
                            <span class="text-sm text-gray-500">(v{{ ltrim($queueStatus['horizon_version'], 'v') }})</span>
                        @endif
                    </div>
                    @if(!empty($queueStatus['horizon_output']) && !(config('app.demo_mode') && !($queueStatus['horizon_running'] ?? false)))
                        <div class="text-xs text-gray-500 mt-1">{{ $queueStatus['horizon_output'] }}</div>
                    @endif
                    <a href="/{{ config('horizon.path', 'horizon') }}" target="_blank" class="text-xs text-indigo-600 underline mt-2 inline-block">Open Horizon</a>
                </div>
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="cp-grid-label">Supervised queues</div>
                    <div class="flex flex-wrap gap-2 mt-2">
                        @forelse(($queueStatus['supervised_queues'] ?? []) as $queue)
                            <span class="inline-flex items-center rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ $queue }}</span>
                        @empty
                            <span class="text-xs text-gray-500">No data</span>
                        @endforelse
                    </div>
                    <div class="mt-3 text-xs text-gray-500">Active processes: <span class="font-semibold {{ ($queueStatus['active_processes'] ?? 0) > 0 ? 'text-emerald-600' : (config('app.demo_mode') ? 'text-gray-500' : 'text-rose-600') }}">{{ $queueStatus['active_processes'] ?? 0 }}</span></div>
                </div>
            </div>

            @php($qd = $this->queueDiagnostics())
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <div class="cp-grid-label mb-1">Queue lengths</div>
                    @if($qd['using_redis'])
                        <div class="flex flex-wrap gap-2">
                            @foreach($qd['lengths'] as $name => $len)
                                <span class="inline-flex items-center rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ $name }}: {{ $len }}</span>
                            @endforeach
                        </div>
                    @else
                        <div class="text-xs text-amber-600">Redis queue lengths not available.</div>
                    @endif
                </div>
                <div>
                    <div class="cp-grid-label mb-1">Failed jobs ({{ number_format($qd['failed_count'] ?? 0) }})</div>
                    @if(($qd['failed'] ?? collect())->isNotEmpty())
                        <ul class="max-h-40 overflow-auto space-y-1 text-xs">
                            @foreach($qd['failed'] as $fj)
                                <li>
                                    <span class="font-mono">#{{ $fj->id }}</span>
                                    · <span class="text-gray-600">{{ $fj->queue }}</span>
                                    · <span class="text-gray-500">{{ \Carbon\Carbon::parse($fj->failed_at)->diffForHumans() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="text-xs text-emerald-600">No data</div>
                    @endif
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-3 text-sm">
                <div class="space-y-1 text-gray-700">
                    <x-filament::button color="primary" wire:click="restartHorizon" wire:loading.attr="disabled" icon="heroicon-m-arrow-path">
                        Restart Horizon
                    </x-filament::button>
                    <div class="text-xs text-gray-500">Runs <code>horizon:terminate</code>.</div>
                </div>
                <div class="space-y-1 text-gray-700">
                    <x-filament::button color="primary" wire:click="retryAllFailedJobs" wire:loading.attr="disabled" icon="heroicon-m-arrow-path">
                        Retry Failed Jobs
                    </x-filiment::button>
                    <div class="text-xs text-gray-500">Runs <code>queue:retry --id=all</code>.</div>
                </div>
                <div class="space-y-1 text-gray-700">
                    <x-filament::button color="danger" wire:click="flushFailedJobs" wire:loading.attr="disabled" icon="heroicon-m-trash" x-on:click.prevent="if(!confirm('Flush all failed job records?')) return false;">
                        Flush Failed Jobs
                    </x-filament::button>
                    <div class="text-xs text-gray-500">Runs <code>queue:flush</code>.</div>
                </div>
                <div class="space-y-1 text-gray-700">
                    <x-filament::button color="primary" wire:click="clearCaches" wire:loading.attr="disabled" icon="heroicon-m-bolt">
                        Clear Caches
                    </x-filament::button>
                    <div class="text-xs text-gray-500">Runs <code>optimize:clear</code>.</div>
                </div>
            </div>
        </x-filament::card>
        </section>

        @php($webhook = $this->webhookStats())
        <section id="webhook-health" class="space-y-4">
        <x-filament::card class="cp-card">
            <div class="text-lg cp-section-title mb-4">Webhook Health (Acuity)</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm mb-4">
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="cp-grid-label">Last received</div>
                    <div class="text-xl font-semibold">{{ optional($webhook['last'])->received_at ? \Carbon\Carbon::parse($webhook['last']->received_at)->diffForHumans() : 'No data' }}</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="cp-grid-label">Events (1 hour)</div>
                    <div class="text-xl font-semibold">{{ number_format($webhook['last_hour'] ?? 0) }}</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3">
                    <div class="cp-grid-label">Events (24 hours)</div>
                    <div class="text-xl font-semibold">{{ number_format($webhook['last_day'] ?? 0) }}</div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600 mb-3">
                <div class="flex items-center gap-2">
                    <label>Show</label>
                    <select wire:model.defer="webhookActionFilter" class="fi-input rounded-md border-gray-300 text-xs">
                        <option value="all">All</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="rescheduled">Rescheduled</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <label>Rows</label>
                    <x-filament::input type="number" min="5" max="200" wire:model.defer="webhookLimit" class="w-20" />
                </div>
                <div class="flex items-center gap-2">
                    <label>Filter appointment</label>
                    <x-filament::input type="text" wire:model.defer="webhookApptFilter" placeholder="Appointment ID" class="w-32" />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-4 text-left">Received</th>
                            <th class="py-2 pr-4 text-left">Action</th>
                            <th class="py-2 pr-4 text-left">Appointment</th>
                            <th class="py-2 pr-4 text-left">Client</th>
                            <th class="py-2 pr-4 text-left">Replay</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($webhook['recent'] as $event)
                            <tr>
                                <td class="py-2 pr-4">{{ \Carbon\Carbon::parse($event->received_at)->diffForHumans() }}</td>
                                <td class="py-2 pr-4">{{ $event->action ?? '—' }}</td>
                                <td class="py-2 pr-4 font-mono">{{ $event->appointment_id ?? '—' }}</td>
                                <td class="py-2 pr-4 font-mono">{{ $event->client_id ?? '—' }}</td>
                                <td class="py-2 pr-4">
                                    <div class="flex flex-wrap gap-2">
                                        <x-filament::button size="xs" color="primary" wire:click="replayWebhookAppointment({{ $event->id }})">Replay to Job</x-filament::button>
                                        <x-filament::button size="xs" color="gray" wire:click="replayWebhookClient({{ $event->id }})">Replay Client</x-filament::button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-3 text-center text-xs text-gray-500">No data</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                <x-filament::button color="danger" wire:click="purgeOldWebhooks(30)" wire:loading.attr="disabled" icon="heroicon-m-trash" x-on:click.prevent="if(!confirm('Purge webhook events older than 30 days?')) return false;">
                    Purge &gt;30d
                </x-filament::button>
            </div>
        </x-filament::card>
        </section>

        <section id="sync-logs" class="space-y-4">
        <x-filament::card class="cp-card" wire:poll.30s>
            <div class="text-lg cp-section-title mb-4">Recent Sync Logs</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-3 text-left">When</th>
                            <th class="py-2 pr-3 text-left">Command</th>
                            <th class="py-2 pr-3 text-left">Params</th>
                            <th class="py-2 pr-3 text-left">Status</th>
                            <th class="py-2 pr-3 text-left">By</th>
                            <th class="py-2 text-left">Output</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($this->recentLogs() as $log)
                            <tr>
                                <td class="py-2 pr-3">{{ $log->created_at?->diffForHumans() ?? '—' }}</td>
                                <td class="py-2 pr-3 font-mono">{{ $log->command }}</td>
                                <td class="py-2 pr-3 text-xs text-gray-600 whitespace-pre">{{ json_encode($log->params, JSON_PRETTY_PRINT) }}</td>
                                <td class="py-2 pr-3">
                                    @php($status = $log->status ?? 'unknown')
                                    @if($status === 'success')
                                        <span class="text-emerald-600">success</span>
                                    @elseif($status === 'error')
                                        <span class="text-rose-600">error</span>
                                    @elseif($status === 'running')
                                        <span class="text-amber-600">running</span>
                                    @elseif($status === 'queued')
                                        <span class="text-gray-500">queued</span>
                                    @else
                                        <span class="text-gray-500">{{ $status }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3">{{ $log->ran_by ?? '—' }}</td>
                                <td class="py-2">
                                    <x-filament::button size="xs" wire:click="openLogOutput({{ $log->id }})">View output</x-filament::button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-3 text-center text-xs text-gray-500">No data</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="text-xs text-gray-500 mt-2">Use the buttons above to queue jobs; each entry stores params and captured output.</div>
        </x-filament::card>
        </section>

        <x-filament::modal id="heavy-appointments-modal" width="md" :visible="false">
            <x-slot name="heading">Confirm heavy appointments sync</x-slot>
            <div class="text-sm text-gray-600 space-y-2">
                <p>This run uses Slice days = {{ $pendingAppointmentOptions['--sliceDays'] ?? '—' }} and Page size = {{ $pendingAppointmentOptions['--pageSize'] ?? '—' }}. Large ranges can take a long time and hammer Acuity.</p>
                <p>Proceed only if you intentionally requested a heavy window.</p>
            </div>
            <x-slot name="footer">
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'heavy-appointments-modal' })">Cancel</x-filament::button>
                <x-filament::button color="danger" wire:click="confirmQueueAppointmentsSync" wire:loading.attr="disabled">Run anyway</x-filament::button>
            </x-slot>
        </x-filament::modal>

        <x-filament::modal id="output-modal" width="4xl">
            @php($log = $outputLogId ? \App\Models\SyncLog::find($outputLogId) : null)
            <x-slot name="heading">Output: {{ $log?->command }} (#{{ $log?->id }})</x-slot>
            <div class="max-h-96 overflow-auto">
                <pre class="text-xs whitespace-pre-wrap">{{ $log?->output }}</pre>
            </div>
            <x-slot name="footer">
                <x-filament::button x-on:click="$dispatch('close-modal', { id: 'output-modal' })">Close</x-filament::button>
            </x-slot>
        </x-filament::modal>

        <x-filament::modal id="audit-output-modal" width="4xl">
            <div wire:poll.2s="checkForLatestAuditResult">
                @php($auditLog = $this->auditOutputLogId ? \App\Models\SyncLog::find($this->auditOutputLogId) : null)
                <x-slot name="heading">Audit Result: acuity:audit-window @if($auditLog)(#{{ $auditLog->id }})@endif</x-slot>
                @php($summary = $this->auditResultSummary())
                @if(!$auditLog)
                    <div class="p-3 text-sm text-gray-600">Waiting for audit job to start…</div>
                @else
                    <div class="space-y-3">
                        <div class="flex flex-wrap gap-2 text-xs">
                            @if(!is_null($summary['acuity']))<span class="inline-flex items-center rounded bg-slate-100 px-2 py-0.5">Acuity: <strong class="ml-1">{{ number_format($summary['acuity']) }}</strong></span>@endif
                            @if(!is_null($summary['db']))<span class="inline-flex items-center rounded bg-slate-100 px-2 py-0.5">DB: <strong class="ml-1">{{ number_format($summary['db']) }}</strong></span>@endif
                            @if(!is_null($summary['missing']))<span class="inline-flex items-center rounded bg-slate-100 px-2 py-0.5">Missing: <strong class="ml-1">{{ number_format($summary['missing']) }}</strong></span>@endif
                        </div>
                        <div class="text-xs text-gray-500">Params: {{ json_encode($auditLog->params) }}</div>
                        <div class="max-h-96 overflow-auto border rounded">
                            <pre class="text-xs p-3 whitespace-pre-wrap">{{ $auditLog->output }}</pre>
                        </div>
                    </div>
                @endif
            </div>
            <x-slot name="footer">
                <x-filament::button x-on:click="$dispatch('close-modal', { id: 'audit-output-modal' })">Close</x-filament::button>
            </x-slot>
        </x-filament::modal>

        <x-filament::modal id="why-stuck-modal" width="4xl">
            @php($ctx = $this->whyStuckContext())
            <x-slot name="heading">Why are jobs stuck?</x-slot>
            <div class="space-y-4 text-sm text-gray-600">
                <div>
                    <div class="font-semibold">Current state</div>
                    <ul class="list-disc ml-5 text-xs space-y-1">
                        <li>ENV: {{ strtoupper($ctx['env'] ?? '') }}</li>
                        <li>QUEUE_CONNECTION: {{ config('queue.default') }}</li>
                        <li>Effective driver: {{ $ctx['driver'] ?? 'unknown' }}</li>
                        <li>Redis reachable: {{ ($ctx['redis_available'] ?? null) === false ? 'no' : 'yes' }}</li>
                        @if(($ctx['redis_error'] ?? null))
                            <li class="text-rose-600">Redis error: {{ $ctx['redis_error'] }}</li>
                        @endif
                    </ul>
                </div>
                <div>
                    <div class="font-semibold">Configured supervisors</div>
                    <table class="min-w-full text-xs">
                        <thead class="text-gray-500 uppercase">
                            <tr>
                                <th class="py-1 pr-3 text-left">Name</th>
                                <th class="py-1 pr-3 text-left">Queues</th>
                                <th class="py-1 pr-3 text-left">Balance</th>
                                <th class="py-1 pr-3 text-left">Min</th>
                                <th class="py-1 pr-3 text-left">Max</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($ctx['configured'] as $sup)
                                <tr>
                                    <td class="py-1 pr-3">{{ $sup['name'] }}</td>
                                    <td class="py-1 pr-3">{{ implode(', ', $sup['queues']) }}</td>
                                    <td class="py-1 pr-3">{{ $sup['balance'] ?? '—' }}</td>
                                    <td class="py-1 pr-3">{{ $sup['minProcesses'] ?? '—' }}</td>
                                    <td class="py-1 pr-3">{{ $sup['maxProcesses'] ?? ($sup['processes'] ?? '—') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-2 text-center text-gray-500">No configuration found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div>
                    <div class="font-semibold">Running supervisors</div>
                    <table class="min-w-full text-xs">
                        <thead class="text-gray-500 uppercase">
                            <tr>
                                <th class="py-1 pr-3 text-left">Name</th>
                                <th class="py-1 pr-3 text-left">Queues</th>
                                <th class="py-1 pr-3 text-left">Processes</th>
                                <th class="py-1 pr-3 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($queueStatus['supervisors'] ?? [] as $sup)
                                <tr>
                                    <td class="py-1 pr-3">{{ $sup['name'] ?? '—' }}</td>
                                    <td class="py-1 pr-3">{{ $sup['queue'] ?? '—' }}</td>
                                    <td class="py-1 pr-3">{{ $sup['processes'] ?? 0 }}</td>
                                    <td class="py-1 pr-3">{{ $sup['status'] ?? 'unknown' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-2 text-center text-gray-500">No active supervisors.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="text-xs text-gray-500">Tip: if QUEUE_CONNECTION ≠ redis, update <code>.env</code>, refresh config cache, and restart Horizon.</div>
            </div>
            <x-slot name="footer">
                <x-filament::button x-on:click="$dispatch('close-modal', { id: 'why-stuck-modal' })">Close</x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament::page>
