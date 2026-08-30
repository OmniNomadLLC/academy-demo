<div class="grid gap-4 md:grid-cols-{{ $isSuperAdmin ? '2' : '1' }}">
    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm h-full">
        <h3 class="text-sm font-semibold text-gray-800">Timeline</h3>
        <div class="mt-3 grid gap-3 md:grid-cols-2">
            <div class="flex items-center gap-2 text-sm text-gray-600">
                <x-filament::icon icon="heroicon-o-calendar" class="h-5 w-5 text-gray-400" />
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Created</div>
                    <div class="font-medium text-gray-800">{{ optional(optional($record)->created_at)->format('d M Y · H:i') ?: '—' }}</div>
                </div>
            </div>
            <div class="flex items-center gap-2 text-sm text-gray-600">
                <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5 text-gray-400" />
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500">Last updated</div>
                    <div class="font-medium text-gray-800">{{ optional(optional($record)->updated_at)->format('d M Y · H:i') ?: '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    @if($isSuperAdmin)
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm h-full">
            <h3 class="text-sm font-semibold text-gray-800">System Information</h3>
            <div class="mt-3 grid gap-3 md:grid-cols-3">
                <div class="text-sm text-gray-600">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Student ID</div>
                    <div class="font-medium text-gray-800">{{ $record->id ?? '—' }}</div>
                </div>
                <div class="text-sm text-gray-600">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Acuity ID</div>
                    <div class="font-medium text-gray-800">{{ $record->acuity_client_id ?? 'Not synced' }}</div>
                </div>
                <div class="text-sm text-gray-600">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Status</div>
                    @php($isActive = (bool) ($record->is_active ?? false))
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                        {{ $isActive ? 'Active' : 'Inactive' }}
                    </span>
                </div>
            </div>
        </div>
    @endif
</div>
