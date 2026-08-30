<?php

namespace App\Filament\Resources\AcademicUpcomingResource\Pages;

use App\Filament\Resources\AcademicUpcomingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ListAcademicUpcoming extends ListRecords
{
    protected static string $resource = AcademicUpcomingResource::class;

    public ?string $selectedCalendar = null;

    protected $queryString = [
        'selectedCalendar' => ['except' => null],
    ];

    protected function getDistinctCalendars(): array
    {
        $today = now()->toDateString();
        $to = now()->addDays(60)->toDateString();
        $calendarExpr = $this->calendarExpr();

        $q = DB::table('class_sessions')
            ->whereBetween('session_date', [$today, $to])
            ->where(function ($w) { $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']); })
            ->select(DB::raw('DISTINCT '.$calendarExpr.' as cal'))
            ->orderBy('cal');

        Log::info('[AcademicUpcoming] Tabs SQL', ['sql' => $q->toSql(), 'bindings' => $q->getBindings()]);

        $rows = $q->pluck('cal')->toArray();
        $vals = array_values(array_filter(array_map(function ($v) {
            if ($v === null) return null;
            $s = trim(str_replace('"', '', (string) $v));
            return $s !== '' ? $s : null;
        }, $rows)));
        sort($vals);
        return $vals;
    }

    protected function getHeaderActions(): array
    {
        $buttons = [];
        $buttons[] = Action::make('cal_all')
            ->label('All')
            ->color($this->selectedCalendar ? 'gray' : 'primary')
            ->outlined((bool) $this->selectedCalendar)
            ->tooltip('All calendars')
            ->action(fn () => $this->selectedCalendar = null);

        $labelMap = $this->buildCalendarLabels();
        foreach ($this->getDistinctCalendars() as $c) {
            $active = $this->selectedCalendar === $c;
            $buttons[] = Action::make('cal_'.md5($c))
                ->label($labelMap[$c] ?? $this->firstWord($c))
                ->color($active ? 'primary' : 'gray')
                ->outlined(! $active)
                ->tooltip($c)
                ->action(fn () => $this->selectedCalendar = $c);
        }

        // Export CSV (Academic view covers all regions)
        $buttons[] = Action::make('exportCsv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->tooltip('Download upcoming classes as CSV')
            ->url(function () {
                return route('exports.upcoming', [
                    // no region filter for academic; respects date window defaults
                    'calendar' => $this->selectedCalendar,
                ]);
            })
            ->openUrlInNewTab();

        return $buttons;
    }

    private function firstWord(?string $s): string
    {
        if (!is_string($s)) return '—';
        $trim = trim($s);
        if ($trim === '') return '—';
        $parts = preg_split('/\s+/', $trim);
        return $parts[0] ?? $trim;
    }

    private function secondWord(?string $s): ?string
    {
        if (!is_string($s)) return null;
        $trim = trim($s);
        if ($trim === '') return null;
        $parts = preg_split('/\s+/', $trim);
        return $parts[1] ?? null;
    }

    private function lastWord(?string $s): ?string
    {
        if (!is_string($s)) return null;
        $trim = trim($s);
        if ($trim === '') return null;
        $parts = preg_split('/\s+/', $trim);
        return $parts[count($parts)-1] ?? null;
    }

    private function buildCalendarLabels(): array
    {
        $cals = $this->getDistinctCalendars();
        $firstCounts = [];
        foreach ($cals as $c) {
            $fw = strtolower($this->firstWord($c));
            $firstCounts[$fw] = ($firstCounts[$fw] ?? 0) + 1;
        }
        $labels = [];
        $used = [];
        foreach ($cals as $c) {
            $fw = $this->firstWord($c);
            $label = $fw;
            if (($firstCounts[strtolower($fw)] ?? 0) > 1) {
                $second = $this->secondWord($c);
                if (is_string($second) && $second !== '') {
                    $label = $fw.' '.mb_substr($second, 0, 1).'.';
                } else {
                    $last = $this->lastWord($c);
                    $label = $last && strtolower($last) !== strtolower($fw)
                        ? $fw.' '.mb_substr($last, 0, 1).'.'
                        : $fw;
                }
            }
            $base = $label;
            $thirdTried = false;
            while (in_array($label, $used, true)) {
                if (!$thirdTried) {
                    $parts = preg_split('/\s+/', trim((string) $c));
                    $third = $parts[2] ?? null;
                    if ($third) {
                        $label = $base.' '.mb_substr($third, 0, 1).'.';
                        $thirdTried = true;
                        continue;
                    }
                }
                $suffix = 2;
                while (in_array($base.' '.$suffix, $used, true)) { $suffix++; }
                $label = $base.' '.$suffix;
            }
            $used[] = $label;
            $labels[$c] = $label;
        }
        return $labels;
    }

    protected function getTableQuery(): Builder
    {
        $today = now()->toDateString();
        $to = now()->addDays(60)->toDateString();
        $calendarExpr = $this->calendarExpr();

        $query = static::getResource()::getModel()::query()
            ->whereBetween('session_date', [$today, $to])
            ->where(function ($w) { $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']); })
            ->orderBy('session_date')
            ->orderBy('start_time');

        if ($this->selectedCalendar) {
            $val = strtolower(trim($this->selectedCalendar));
            $query->whereRaw("{$calendarExpr} = ?", [$val]);
        }

        Log::info('[AcademicUpcoming] Table SQL', ['sql' => $query->toSql(), 'bindings' => $query->getBindings()]);
        $countsQuery = (clone $query)
            ->cloneWithout(['orders'])
            ->cloneWithoutBindings(['orders']);

        $counts = $countsQuery
            ->selectRaw("{$calendarExpr} as cal, COUNT(*) as cnt")
            ->groupBy('cal')
            ->orderBy('cal')
            ->pluck('cnt', 'cal')
            ->toArray();
        Log::info('[AcademicUpcoming] Calendar counts (next 60d)', $counts);

        return $query;
    }

    private function dbDriver(): string { return DB::getDriverName(); }

    private function calendarExpr(): string
    {
        return "LOWER(TRIM(COALESCE(calendar_norm, '')))";
    }
}
