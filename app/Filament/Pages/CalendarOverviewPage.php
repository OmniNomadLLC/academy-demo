<?php

namespace App\Filament\Pages;

use App\Services\Acuity\AppointmentExtractor;
use App\Services\LocationMappingService;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CalendarOverviewPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Calendar';
    protected static ?string $navigationGroup = 'Academic Management';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.calendar-overview-page';

    // Filters state
    public ?string $calendar_norm = null;
    public ?string $category_norm = null;
    public ?string $location = null; // raw location column
    public ?string $region = null;   // logical region: UK/Spain/France
    public ?string $timezone = 'Europe/Madrid';
    public bool $academic_only = false;

    // Visible date range from the UI (YYYY-MM-DD); if null, default ±60 days from today
    public ?string $from = null;
    public ?string $to = null;

    public function mount(): void
    {
        // Defaults: show near-term window and academic scope off
        $user = Auth::user();
        // Default to Academic view so unknown categories are included and calendar is not empty
        $this->academic_only = true;
        // Do not pre-filter by region; let user choose
        $this->region = null;
        // Fixed default calendar timezone
        $this->timezone = 'Europe/Madrid';
    }

    public function form(Form $form): Form
    {
        // Build dynamic options from DB (distinct values)
        $calendarOptions = $this->distinctValues('calendar_norm');
        $categoryOptions = $this->distinctValues('category_norm');
        $locationOptions = array_combine(LocationMappingService::getAllLocations(), LocationMappingService::getAllLocations());
        $regionOptions = $locationOptions; // same list
        $tzOptions = [
            'Europe/Madrid' => 'Europe/Madrid (Spain)',
            'Europe/London' => 'Europe/London (UK)',
            'Europe/Paris'  => 'Europe/Paris (France)',
            'UTC'           => 'UTC',
        ];

        // Build filters grid dynamically so regional pages can hide the region selector
        $grid = [];
        $grid[] = Forms\Components\Select::make('calendar_norm')
            ->label('Calendar')
            ->options($calendarOptions)
            ->searchable()
            ->native(false)
            ->live()
            ->columnSpan(2);
        $grid[] = Forms\Components\Select::make('category_norm')
            ->label('Category')
            ->options($categoryOptions)
            ->searchable()
            ->native(false)
            ->live()
            ->columnSpan(2);
        if ($this->showRegionFilter()) {
            $grid[] = Forms\Components\Select::make('region')
                ->label('Region')
                ->options($regionOptions)
                ->native(false)
                ->live()
                ->columnSpan(2);
        }
        $grid[] = Forms\Components\Select::make('timezone')
            ->label('Timezone')
            ->options($tzOptions)
            ->default($this->timezone ?? 'Europe/Madrid')
            ->native(false)
            ->live()
            ->columnSpan(2);
        $grid[] = Forms\Components\Fieldset::make('Date range')
            ->schema([
                Forms\Components\DatePicker::make('from')->label('From')->live(),
                Forms\Components\DatePicker::make('to')->label('To')->live(),
            ])->columns(2);

        return $form
            ->schema([
                Forms\Components\Grid::make(['md' => 6])
                    ->schema($grid),
            ]);
    }

    // Allow regional pages to hide the region selector
    protected function showRegionFilter(): bool
    {
        return true;
    }

    private function currentFilters(): array
    {
        $state = $this->form->getState() ?? [];
        return [
            'calendar_norm' => $state['calendar_norm'] ?? $this->calendar_norm,
            'category_norm' => $state['category_norm'] ?? $this->category_norm,
            'region' => $state['region'] ?? $this->region,
            'timezone' => $state['timezone'] ?? $this->timezone,
            'from' => $state['from'] ?? $this->from,
            'to' => $state['to'] ?? $this->to,
        ];
    }

    private function distinctValues(string $col): array
    {
        $rows = DB::table('class_sessions')
            ->select(DB::raw("DISTINCT LOWER(TRIM(COALESCE({$col}, ''))) AS v"))
            ->whereRaw("TRIM(COALESCE({$col}, '')) <> ''")
            ->orderBy('v')
            ->pluck('v')
            ->toArray();
        $opts = [];
        foreach ($rows as $v) { $opts[$v] = $v; }
        return $opts;
    }

    private function tzForRegion(?string $region): string
    {
        $r = is_string($region) ? strtolower(trim($region)) : '';
        return match ($r) {
            'spain' => 'Europe/Madrid',
            'france' => 'Europe/Paris',
            'uk' => 'Europe/London',
            default => (Auth::user()?->timezone ?: 'Europe/London'),
        };
    }

    // Normalize various input formats to Y-m-d for date filters.
    private function normDate(?string $s, string $fallback): string
    {
        if (!$s) {
            return $fallback;
        }
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $s)->format('Y-m-d');
            } catch (\Throwable $e) {
                // try next
            }
        }
        try {
            return Carbon::parse($s)->format('Y-m-d');
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    protected function buildBaseQuery(array $filters)
    {
        // Normalize incoming date strings (e.g., 09/08/2025) to Y-m-d for SQL filtering
        $rawFrom = $filters['from'] ?? null;
        $rawTo = $filters['to'] ?? null;
        $from = $this->normDate($rawFrom, now()->subDays(60)->toDateString());
        $to = $this->normDate($rawTo, now()->addDays(365)->toDateString());

        $q = DB::table('class_sessions')
            ->whereBetween('session_date', [$from, $to])
            ->where(function ($w) {
                // Exclude explicitly cancelled; include scheduled/confirmed/completed
                $w->where('canceled', false)
                  ->orWhereNull('canceled')
                  ->orWhere('status', '!=', 'cancelled');
            });

        // Non-academic view skips unknown categories
        if (!$this->academic_only) {
            $q->whereRaw("TRIM(COALESCE(category_norm, '')) <> ''");
        }

        if (!empty($filters['calendar_norm'])) {
            $val = strtolower(trim((string) $filters['calendar_norm']));
            $like = '%'.$val.'%';
            $q->where(function ($w) use ($val, $like) {
                $w->whereRaw("LOWER(TRIM(COALESCE(calendar_norm, ''))) LIKE ?", [$like])
                  ->orWhereRaw("LOWER(TRIM(COALESCE(calendar_name, ''))) LIKE ?", [$like])
                  ->orWhereRaw("LOWER(TRIM(COALESCE(".$this->jsonUnquoteExpr("json_extract(acuity_data, '$.calendar')").", ''))) LIKE ?", [$like])
                  ->orWhereRaw("LOWER(TRIM(COALESCE(".$this->jsonUnquoteExpr("json_extract(acuity_data, '$.calendarName')").", ''))) LIKE ?", [$like])
                  ->orWhereRaw("LOWER(TRIM(COALESCE(".$this->jsonUnquoteExpr("json_extract(acuity_data, '$.calendar.name')").", ''))) LIKE ?", [$like]);
            });
        }
        if (!empty($filters['category_norm'])) {
            $val = strtolower(trim((string) $filters['category_norm']));
            $like = $val.'%';
            $q->where(function ($w) use ($val, $like) {
                $w->whereRaw("LOWER(TRIM(COALESCE(category_norm, ''))) LIKE ?", [$like])
                  ->orWhereRaw("LOWER(TRIM(COALESCE(".$this->jsonUnquoteExpr("json_extract(acuity_data, '$.category')").", ''))) LIKE ?", [$like])
                  ->orWhereRaw("LOWER(TRIM(COALESCE(".$this->jsonUnquoteExpr("json_extract(acuity_data, '$.Category')").", ''))) LIKE ?", [$like]);
            });
        }
        if (!empty($filters['region'])) {
            $q->whereRaw('LOWER(location) = ?', [strtolower((string) $filters['region'])]);
        }

        return $q;
    }

    private function paletteColor(string $key): string
    {
        // Deterministic HSL to hex mapping based on key hash
        $hash = crc32(strtolower(trim($key)));
        $h = $hash % 360; // hue
        $s = 65; $l = 52; // pleasant saturation/lightness
        return $this->hslToHex($h, $s, $l);
    }

    private function hslToHex(int $h, int $s, int $l): string
    {
        $h /= 360; $s /= 100; $l /= 100;
        $a = $s * min($l, 1 - $l);
        $f = function ($n) use ($h, $l, $a) {
            $k = fmod($n + $h * 12, 12);
            $c = $l - $a * max(min(min($k - 3, 9 - $k), 1), -1);
            return (int) round(255 * $c);
        };
        return sprintf('#%02x%02x%02x', $f(0), $f(8), $f(4));
    }

    public function getViewData(): array
    {
        $filters = $this->currentFilters();
        // Use selected timezone (default Europe/Madrid)
        $tz = $filters['timezone'] ?: 'Europe/Madrid';

        $query = $this->buildBaseQuery($filters);

        // Group to avoid duplicates: date, start, end, region(location), calendar, status
        $rows = $query
            ->select([
                'session_date',
                'start_time',
                'end_time',
                'location',
                'calendar_name',
                DB::raw("LOWER(TRIM(COALESCE(calendar_norm, ''))) AS calendar_norm"),
                DB::raw("LOWER(TRIM(COALESCE(category_norm, ''))) AS category_norm"),
                'status',
                DB::raw('MIN(id) as id'),
                DB::raw('COUNT(*) as cnt'),
            ])
            ->groupBy('session_date','start_time','end_time','location','calendar_name','status', DB::raw("LOWER(TRIM(COALESCE(calendar_norm, '')))") , DB::raw("LOWER(TRIM(COALESCE(category_norm, '')))") )
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->get();

        $events = [];
        $colorMap = [];
        foreach ($rows as $r) {
            $cal = (string) ($r->calendar_name ?? 'Unknown');
            $calNorm = (string) ($r->calendar_norm ?? '');
            $status = (string) ($r->status ?? 'scheduled');
            $region = (string) ($r->location ?? '');
            if (!isset($colorMap[$calNorm])) {
                $colorMap[$calNorm] = $this->paletteColor($calNorm !== '' ? $calNorm : $cal);
            }
            $title = trim($cal) !== '' ? $cal : 'Unknown';
            if ((int) $r->cnt > 1) { $title .= ' ('.$r->cnt.')'; }
            // Normalize date and convert from session's region timezone to the selected display timezone
            $dateStr = (string) $r->session_date;
            $dateOnly = substr($dateStr, 0, 10);
            $srcTz = $this->tzForLocation($region) ?: 'Europe/Madrid';
            $hour = (string) $r->start_time;
            $groupCount = (int) $r->cnt;
            // Enrich with student name / appointment type from the representative session
            $studentName = null; $apptType = null;
            try {
                $rep = \App\Models\ClassSession::find($r->id);
                if ($rep) {
                    $data = $rep->acuity_data;
                    if (!is_array($data)) { $decoded = json_decode((string) $data, true); if (is_array($decoded)) { $data = $decoded; } }
                    if (is_array($data)) {
                        $apptType = $data['type'] ?? $data['appointmentType'] ?? $data['appointmentTypeName'] ?? null;
                        $first = $data['firstName'] ?? data_get($data, 'client.firstName') ?? data_get($data, 'client.first_name') ?? data_get($data, 'Client.firstName') ?? data_get($data, 'first_name');
                        $last  = $data['lastName'] ?? data_get($data, 'client.lastName') ?? data_get($data, 'client.last_name') ?? data_get($data, 'Client.lastName') ?? data_get($data, 'last_name');
                        $nm = trim(trim((string) ($first ?? '')) . ' ' . trim((string) ($last ?? '')));
                        $studentName = $nm !== '' ? $nm : null;
                    }
                    if (!$studentName) {
                        $email = (string) ($rep->student_email ?? $rep->client_email ?? '');
                        if ($email !== '' && str_contains($email, '@')) { $studentName = strtok($email, '@'); }
                    }
                }
            } catch (\Throwable $e) {
                // ignore enrichment errors
            }
            $displayStatus = $status; // will adjust based on time window
            try {
                $startDt = Carbon::createFromFormat('Y-m-d H:i:s', $dateOnly.' '.(string) $r->start_time, $srcTz)->setTimezone($tz);
                $endDt   = Carbon::createFromFormat('Y-m-d H:i:s', $dateOnly.' '.(string) $r->end_time, $srcTz)->setTimezone($tz);
                // Output naive local strings in the target timezone so FullCalendar renders all uniformly
                $start = $startDt->format('Y-m-d\TH:i:s');
                $end   = $endDt->format('Y-m-d\TH:i:s');
                // Determine display status: 'In progress' if now between start and end; 'completed' if ended
                $nowTz = Carbon::now($tz);
                if (strtolower($status) !== 'cancelled' && $startDt->lessThanOrEqualTo($nowTz) && $endDt->greaterThan($nowTz)) {
                    $displayStatus = 'In progress';
                } elseif (strtolower($status) !== 'cancelled' && $endDt->lessThan($nowTz)) {
                    $displayStatus = 'completed';
                }
            } catch (\Throwable $e) {
                // Fallback to naive if parsing fails
                $start = $dateOnly.'T'.(string) $r->start_time;
                $end   = $dateOnly.'T'.(string) $r->end_time;
            }

            // Attendance URL placeholder until new attendance UI is ready
            $url = '#';

            $events[] = [
                'id' => (string) $r->id,
                'title' => $title,
                'start' => $start,
                'end' => $end,
                'url' => $url,
                'extendedProps' => [
                    'status' => $displayStatus,
                    'region' => $region,
                    'calendar_norm' => $calNorm,
                    'calendar_name' => $cal,
                    'hour' => $hour,
                    'group_count' => $groupCount,
                    'student_name' => $studentName,
                    'appointment_type' => $apptType,
                ],
                'color' => $colorMap[$calNorm],
            ];
        }

        return [
            'events' => $events,
            'calendarColors' => $colorMap,
            'timezone' => $tz,
            'isAcademic' => $this->academic_only,
        ];
    }

    private function jsonUnquoteExpr(string $expr): string
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'])) {
            return 'JSON_UNQUOTE('.$expr.')';
        }
        return $expr;
    }

    private function tzForLocation(?string $loc): ?string
    {
        if (!is_string($loc)) return null;
        $v = strtolower(trim($loc));
        return match ($v) {
            'uk' => 'Europe/London',
            'spain' => 'Europe/Madrid',
            'france' => 'Europe/Paris',
            default => null,
        };
    }

    public static function canAccess(): bool
    {
        return (Auth::user()?->hasRole('super_admin') ?? false)
            && parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (Auth::user()?->hasRole('super_admin') ?? false)
            && parent::shouldRegisterNavigation();
    }
}
