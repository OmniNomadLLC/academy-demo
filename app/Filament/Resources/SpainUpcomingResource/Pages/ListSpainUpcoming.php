<?php

namespace App\Filament\Resources\SpainUpcomingResource\Pages;

use App\Filament\Resources\SpainUpcomingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListSpainUpcoming extends ListRecords
{
    protected static string $resource = SpainUpcomingResource::class;

    public ?string $selectedCalendar = null;

    protected $queryString = [
        'selectedCalendar' => ['except' => null],
    ];

    protected function getDistinctCalendars(): array
    {
        $today = now()->toDateString();
        $to = now()->addDays(60)->toDateString();
        $calendarExpr = $this->calendarExpr();
        $categoryExpr = $this->categoryExpr();

        $rows = DB::table('class_sessions')
            ->whereBetween('session_date', [$today, $to])
            ->where(function ($w) {
                $w->where('canceled', false)
                  ->orWhereNull('canceled')
                  ->orWhereIn('status', ['scheduled','confirmed']);
            })
            ->where(function ($w) use ($categoryExpr) {
                foreach (['english','spanish','french','german','italian','lebanese','bni','marina'] as $cat) {
                    $w->orWhereRaw("{$categoryExpr} LIKE ?", [$cat.'%']);
                }
            })
            ->select(DB::raw('DISTINCT '.$calendarExpr.' as cal'))
            ->orderBy('cal')
            ->pluck('cal')
            ->toArray();

        $vals = array_values(array_filter(array_map(function ($v) {
            $s = trim((string) $v);
            return $s !== '' ? $s : null;
        }, $rows)));
        sort($vals);
        return $vals;
    }

    public function mount(): void
    {
        parent::mount();
        // Clear any persisted table filters so the default view has no active filters.
        session()->forget($this->getTableFiltersSessionKey());
        $this->tableFilters = [];
    }

    protected function getHeaderActions(): array
    {
        $buttons = [];
        $buttons[] = Action::make('cal_all')
            ->label('All')
            ->color($this->selectedCalendar ? 'gray' : 'primary')
            ->outlined((bool) $this->selectedCalendar)
            ->tooltip('All calendars')
            ->action(function () {
                $this->selectedCalendar = null;
                // Clear date filters so "All" truly shows everything and prevents defaulting
                $this->tableFilters = [];
                session()->forget($this->getTableFiltersSessionKey());
                $this->resetTable();
            });

        // Removed quick Today button per request

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

        $buttons[] = Action::make('exportCsv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->tooltip('Download upcoming classes as CSV')
            ->url(fn () => route('exports.upcoming', ['region' => 'Spain', 'calendar' => $this->selectedCalendar]))
            ->openUrlInNewTab();

        return $buttons;
    }

    private function firstWord(?string $s): string
    {
        if (!is_string($s)) return '—';
        $parts = preg_split('/\s+/', trim($s));
        return $parts[0] ?? '—';
    }
    private function secondWord(?string $s): ?string
    {
        if (!is_string($s)) return null;
        $parts = preg_split('/\s+/', trim($s));
        return $parts[1] ?? null;
    }
    private function lastWord(?string $s): ?string
    {
        if (!is_string($s)) return null;
        $parts = preg_split('/\s+/', trim($s));
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
                if ($second) {
                    $label = $fw.' '.mb_substr($second, 0, 1).'.';
                } else {
                    $last = $this->lastWord($c);
                    $label = $last && strtolower($last) !== strtolower($fw) ? $fw.' '.mb_substr($last, 0, 1).'.' : $fw;
                }
            }
            $base = $label;
            $thirdTried = false;
            while (in_array($label, $used, true)) {
                if (!$thirdTried) {
                    $parts = preg_split('/\s+/', trim((string) $c));
                    $third = $parts[2] ?? null;
                    if ($third) { $label = $base.' '.mb_substr($third, 0, 1).'.'; $thirdTried = true; continue; }
                }
                $suffix = 2; while (in_array($base.' '.$suffix, $used, true)) { $suffix++; }
                $label = $base.' '.$suffix;
            }
            $used[] = $label; $labels[$c] = $label;
        }
        return $labels;
    }

    protected function getTableQuery(): Builder
    {
        $today = now()->toDateString();
        $to = now()->addDays(60)->toDateString();
        $calendarExpr = $this->calendarExpr();
        $categoryExpr = $this->categoryExpr();

        $query = static::getResource()::getModel()::query()
            ->whereBetween('session_date', [$today, $to])
            ->where(function ($w) {
                $w->where('canceled', false)
                  ->orWhereNull('canceled')
                  ->orWhereIn('status', ['scheduled','confirmed']);
            })
            ->where(function ($w) use ($categoryExpr) {
                foreach (['english','spanish','french','german','italian','lebanese','bni','marina'] as $cat) {
                    $w->orWhereRaw("{$categoryExpr} LIKE ?", [$cat.'%']);
                }
            })
            ->orderBy('session_date')
            ->orderBy('start_time');

        if ($this->selectedCalendar) {
            $val = strtolower(trim($this->selectedCalendar));
            $query->whereRaw("{$calendarExpr} = ?", [$val]);
        }

        return $query;
    }

    private function calendarExpr(): string
    {
        // Robust calendar name extraction: norm -> column -> JSON fallbacks
        return "LOWER(TRIM(COALESCE(
            calendar_norm,
            calendar_name,
            json_extract(acuity_data, '$.calendar'),
            json_extract(acuity_data, '$.calendarName'),
            json_extract(acuity_data, '$.calendar.name'),
            json_extract(acuity_data, '$.Calendar'),
            json_extract(acuity_data, '$.CalendarName')
        )))";
    }
    private function categoryExpr(): string
    {
        return "LOWER(TRIM(COALESCE(category_norm, '')))";
    }
}
