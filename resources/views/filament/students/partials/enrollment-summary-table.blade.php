@php
    $summary = $summary ?? null;
    $sender = $sender ?? null;
@endphp

@if($summary)
    <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
        <table class="w-full">
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <tr>
                    <th class="w-32 pr-3 text-left font-semibold text-gray-500 uppercase tracking-wide dark:text-gray-400">Channel</th>
                    <td class="text-gray-900 dark:text-gray-100">{{ $summary['channel'] }}</td>
                </tr>
                @if($sender)
                    <tr>
                        <th class="w-32 pr-3 text-left font-semibold text-gray-500 uppercase tracking-wide dark:text-gray-400">Sent by</th>
                        <td class="text-gray-900 dark:text-gray-100">{{ $sender }}</td>
                    </tr>
                @endif
                @if($summary['sent_at'])
                    <tr>
                        <th class="w-32 pr-3 text-left font-semibold text-gray-500 uppercase tracking-wide dark:text-gray-400">Sent</th>
                        <td class="text-gray-900 dark:text-gray-100">
                            {{ $summary['sent_human'] }}
                            <span class="ml-1 text-[11px] text-gray-500 dark:text-gray-400">({{ $summary['sent_at']->format('d M Y, H:i') }})</span>
                        </td>
                    </tr>
                @endif
                @if($summary['status'])
                    <tr>
                        <th class="w-32 pr-3 text-left font-semibold text-gray-500 uppercase tracking-wide dark:text-gray-400">Status</th>
                        <td class="text-gray-900 dark:text-gray-100">{{ $summary['status'] }}</td>
                    </tr>
                @endif
                @if($summary['delivered_at'])
                    <tr>
                        <th class="w-32 pr-3 text-left font-semibold text-gray-500 uppercase tracking-wide dark:text-gray-400">Delivered</th>
                        <td class="text-gray-900 dark:text-gray-100">
                            {{ $summary['delivered_human'] }}
                            <span class="ml-1 text-[11px] text-gray-500 dark:text-gray-400">({{ $summary['delivered_at']->format('d M Y, H:i') }})</span>
                        </td>
                    </tr>
                @endif
                @if($summary['read_at'])
                    <tr>
                        <th class="w-32 pr-3 text-left font-semibold text-gray-500 uppercase tracking-wide dark:text-gray-400">Read</th>
                        <td class="text-gray-900 dark:text-gray-100">
                            {{ $summary['read_human'] }}
                            <span class="ml-1 text-[11px] text-gray-500 dark:text-gray-400">({{ $summary['read_at']->format('d M Y, H:i') }})</span>
                        </td>
                    </tr>
                @endif
                @if($summary['email_sent'])
                    <tr>
                        <th class="w-32 pr-3 text-left font-semibold text-gray-500 uppercase tracking-wide dark:text-gray-400">Email copy</th>
                        <td class="text-gray-900 dark:text-gray-100">Sent</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
@else
    <span class="text-gray-500">Not sent yet</span>
@endif
