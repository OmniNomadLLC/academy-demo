<?php

namespace App\Filament\Resources\FranceUpcomingResource\Pages;

use App\Filament\Resources\FranceUpcomingResource;
use App\Services\LocationMappingService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ListFranceUpcoming extends ListRecords
{
    protected static string $resource = FranceUpcomingResource::class;

    public ?string $selectedCalendar = null;

    protected $queryString = [
        'selectedCalendar' => ['except' => null],
    ];

    protected function getDistinctCalendars(): array
    {
        $today = now()->toDateString();
        $to = now()->addDays(60)->toDateString();
        $keywords = LocationMappingService::keywordsForRegion('France');
        $categoryExpr = $this->categoryExpr();
        $calendarExpr = $this->calendarExpr();

        $q = DB::table('class_sessions')
            ->whereBetween('session_date', [$today, $to])
            ->where(function ($w) { $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']); })
            ->where(function ($w) use ($keywords, $categoryExpr) {
                $w->whereRaw('LOWER(location) = ?', ['france']);
                if (!empty($keywords)) {
                    $w->orWhere(function ($qq) use ($keywords) {
                        foreach ($keywords as $kw) {
                            $qq->orWhere('category_norm', 'like', '%'.$kw.'%');
                        }
                    });
                    $w->orWhere(function ($qq) use ($keywords, $categoryExpr) {
                        foreach ($keywords as $kw) {
                            $qq->orWhereRaw($categoryExpr.' like ?', ['%'.$kw.'%']);
                        }
                    });
                }
            })
            ->select(DB::raw('DISTINCT '.$calendarExpr.' as cal'))
            ->orderBy('cal');

        Log::info('[FranceUpcoming] Tabs SQL', ['sql' => $q->toSql(), 'bindings' => $q->getBindings()]);

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
            ->action(function () {
                $this->selectedCalendar = null;
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

        // Export CSV
        $buttons[] = Action::make('exportCsv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->tooltip('Download upcoming classes as CSV')
            ->url(function () {
                return route('exports.upcoming', [
                    'region' => 'France',
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
        $keywords = LocationMappingService::keywordsForRegion('France');
        $categoryExpr = $this->categoryExpr();
        $calendarExpr = $this->calendarExpr();

        $query = static::getResource()::getModel()::query()
            ->whereBetween('session_date', [$today, $to])
            ->where(function ($w) { $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']); })
            ->where(function ($w) use ($keywords, $categoryExpr) {
                $w->whereRaw('LOWER(location) = ?', ['france']);
                if (!empty($keywords)) {
                    $w->orWhere(function ($qq) use ($keywords) {
                        foreach ($keywords as $kw) {
                            $qq->orWhere('category_norm', 'like', '%'.$kw.'%');
                        }
                    });
                    $w->orWhere(function ($qq) use ($keywords, $categoryExpr) {
                        foreach ($keywords as $kw) {
                            $qq->orWhereRaw($categoryExpr.' like ?', ['%'.$kw.'%']);
                        }
                    });
                }
            })
            ->orderBy('session_date')
            ->orderBy('start_time');

        if ($this->selectedCalendar) {
            $val = strtolower(trim($this->selectedCalendar));
            $query->whereRaw("{$calendarExpr} = ?", [$val]);
        }

        Log::info('[FranceUpcoming] Table SQL', ['sql' => $query->toSql(), 'bindings' => $query->getBindings()]);
        $counts = (clone $query)
            ->selectRaw("{$calendarExpr} as cal, COUNT(*) as cnt")
            ->groupBy('cal')
            ->pluck('cnt', 'cal')
            ->toArray();
        Log::info('[FranceUpcoming] Calendar counts (next 60d)', $counts);

        return $query;
    }

    private function dbDriver(): string { return DB::getDriverName(); }

    private function calendarExpr(): string
    {
        $d = $this->dbDriver();
        if ($d === 'mysql' || $d === 'mariadb') {
            $cal = "JSON_UNQUOTE(COALESCE(\n                JSON_EXTRACT(acuity_data, '$.calendar'),\n                JSON_EXTRACT(acuity_data, '$.calendarName'),\n                JSON_EXTRACT(acuity_data, '$.calendar.name'),\n                JSON_EXTRACT(acuity_data, '$.Calendar'),\n                JSON_EXTRACT(acuity_data, '$.CalendarName')\n            ))";
        } elseif ($d === 'pgsql') {
            $cal = "(COALESCE(\n                (acuity_data->>'calendar'),\n                (acuity_data->>'calendarName'),\n                ((acuity_data->'calendar')->>'name'),\n                (acuity_data->>'Calendar'),\n                (acuity_data->>'CalendarName')\n            ))";
        } else { // sqlite
            $cal = "COALESCE(\n                json_extract(acuity_data, '$.calendar'),\n                json_extract(acuity_data, '$.calendarName'),\n                json_extract(acuity_data, '$.calendar.name'),\n                json_extract(acuity_data, '$.Calendar'),\n                json_extract(acuity_data, '$.CalendarName')\n            )";
        }
        return "LOWER(TRIM(COALESCE(calendar_norm, $cal)))";
    }

    private function categoryExpr(): string
    {
        $d = $this->dbDriver();
        if ($d === 'mysql' || $d === 'mariadb') {
            $cat = "JSON_UNQUOTE(COALESCE(\n                JSON_EXTRACT(acuity_data, '$.category'),\n                JSON_EXTRACT(acuity_data, '$.Category')\n            ))";
        } elseif ($d === 'pgsql') {
            $cat = "(COALESCE(\n                (acuity_data->>'category'),\n                (acuity_data->>'Category')\n            ))";
        } else { // sqlite
            $cat = "COALESCE(\n                json_extract(acuity_data, '$.category'),\n                json_extract(acuity_data, '$.Category')\n            )";
        }
        return "LOWER(TRIM(COALESCE(category_norm, $cat)))";
    }
}
