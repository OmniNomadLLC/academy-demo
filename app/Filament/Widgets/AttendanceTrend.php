<?php

namespace App\Filament\Widgets;

use App\Models\ClassSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Filament\Widgets\ChartWidget;

class AttendanceTrend extends ChartWidget
{
    protected static ?int $sort = 20;
    protected static ?string $heading = 'Attendance (last 30 days - UK)';
    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $from = now()->subDays(29)->toDateString();
        $to = now()->toDateString();
        $dates = [];
        for ($d = Carbon::parse($from); $d->lte($to); $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        // Join attendance_records with class_sessions by date
        $rowsQuery = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->whereBetween('cs.session_date', [$from, $to])
            ->whereRaw('LOWER(cs.location) = ?', ['uk']);

        ClassSession::applyExcludeAssessmentsToQuery($rowsQuery, 'cs');

        $rows = $rowsQuery
            ->select(
                'cs.session_date as d',
                DB::raw("SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present"),
                DB::raw("SUM(CASE WHEN ar.status IN ('present','absent','late') THEN 1 ELSE 0 END) as total")
            )
            ->groupBy('cs.session_date')
            ->get()
            ->mapWithKeys(function ($row) {
                $key = is_string($row->d)
                    ? $row->d
                    : Carbon::parse($row->d)->toDateString();

                return [$key => [
                    'present' => (int) $row->present,
                    'total' => (int) $row->total,
                ]];
            });

        $series = [];
        foreach ($dates as $d) {
            $present = $rows[$d]['present'] ?? 0;
            $total = $rows[$d]['total'] ?? 0;
            $pct = $total > 0 ? round(($present / max(1, $total)) * 100, 1) : null;
            $series[] = $pct ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Attendance % (UK)',
                    'data' => $series,
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'rgba(14,165,233,0.2)',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $dates,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
