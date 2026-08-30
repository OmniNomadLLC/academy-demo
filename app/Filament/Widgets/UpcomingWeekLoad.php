<?php

namespace App\Filament\Widgets;

use Illuminate\Support\Facades\DB;
use Filament\Widgets\ChartWidget;

class UpcomingWeekLoad extends ChartWidget
{
    protected static ?int $sort = 30;
    protected static ?string $heading = 'Upcoming Week Load';

    protected function getData(): array
    {
        $start = now()->startOfDay();
        $labels = [];
        $counts = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            $labels[] = $d;
            $counts[$d] = 0;
        }

        $rows = DB::table('class_sessions')
            ->whereBetween('session_date', [now()->toDateString(), now()->addDays(6)->toDateString()])
            ->where(function ($w) { $w->where('canceled', false)->orWhereNull('canceled'); })
            ->select('session_date', DB::raw('COUNT(*) as c'))
            ->groupBy('session_date')
            ->pluck('c', 'session_date');

        foreach ($rows as $d => $c) {
            if (isset($counts[$d])) { $counts[$d] = (int) $c; }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Sessions',
                    'data' => array_values($counts),
                    'backgroundColor' => '#22c55e',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
