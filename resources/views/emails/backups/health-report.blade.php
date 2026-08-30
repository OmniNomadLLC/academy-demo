@php($generatedAt = now()->toDayDateTimeString())
<p>Backup health report generated {{ $generatedAt }}.</p>

<table style="width:100%; border-collapse: collapse;">
    <thead>
        <tr>
            <th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">Backup</th>
            <th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">Disk</th>
            <th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">Latest Snapshot</th>
            <th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">Age (days)</th>
            <th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">Size (MB)</th>
            <th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">Status</th>
            <th style="text-align:left; border-bottom:1px solid #ccc; padding:4px;">Notes</th>
        </tr>
    </thead>
    <tbody>
    @foreach($report as $row)
        <tr>
            <td style="border-bottom:1px solid #eee; padding:4px;">{{ $row['name'] }}</td>
            <td style="border-bottom:1px solid #eee; padding:4px;">{{ $row['disk'] }}</td>
            <td style="border-bottom:1px solid #eee; padding:4px;">{{ $row['latest'] }}</td>
            <td style="border-bottom:1px solid #eee; padding:4px;">{{ $row['age_days'] ?? 'n/a' }}</td>
            <td style="border-bottom:1px solid #eee; padding:4px;">{{ $row['size_mb'] }}</td>
            <td style="border-bottom:1px solid #eee; padding:4px; font-weight:bold; color: {{ ($row['status'] ?? 'healthy') === 'healthy' ? '#166534' : '#b91c1c' }};">
                {{ ucfirst($row['status'] ?? 'healthy') }}
            </td>
            <td style="border-bottom:1px solid #eee; padding:4px;">{{ $row['failure'] ?? '' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

@if($hasFailures)
<p style="color:#b91c1c;"><strong>Action recommended:</strong> At least one destination reported an unhealthy status. Investigate before the next nightly run.</p>
@else
<p>All monitored backups look healthy.</p>
@endif

<p>— Lumina Ops Bot</p>
