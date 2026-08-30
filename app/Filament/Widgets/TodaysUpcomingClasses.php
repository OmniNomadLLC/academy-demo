<?php

namespace App\Filament\Widgets;

use App\Models\ClassSession;
use App\Models\User;
use App\Support\Concerns\FormatsSessionTimes;
use App\Support\TeacherRoster;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TodaysUpcomingClasses extends BaseWidget
{
    use FormatsSessionTimes;
    // Place under "Starting soon" and before "Today’s Classes"
    protected static ?int $sort = -49;
    protected int | string | array $columnSpan = 'full';
    public ?string $selectedDate = null;
    public ?string $selectedWeekStart = null;

    public static function canView(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        $role = strtolower((string) $user->role);

        return in_array($role, array_merge(['super_admin', 'admin', 'manager'], User::TEACHING_ROLES), true);
    }

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $role = strtolower((string) ($user->role ?? ''));
        $region = $this->resolveRegion();
        $date = Carbon::parse($this->resolveDate())->toDateString();

        $query = ClassSession::query()
            ->select([
                'class_sessions.session_date',
                'class_sessions.location',
                'class_sessions.calendar_name',
                DB::raw("COALESCE(json_extract(class_sessions.acuity_data, '$.datetime'), class_sessions.session_date || ' ' || class_sessions.start_time) as event_start_key"),
                DB::raw('MIN(class_sessions.id) as id'),
                DB::raw('MIN(class_sessions.start_time) as raw_start_time'),
                DB::raw('MAX(class_sessions.end_time) as raw_end_time'),
                DB::raw('COUNT(DISTINCT class_sessions.id) as session_count'),
                DB::raw("ROUND(100.0 * COUNT(DISTINCT CASE WHEN ar.status IN ('present','late') THEN ar.student_id END) / NULLIF(COUNT(DISTINCT ar.student_id), 0), 1) as attendance_rate"),
                DB::raw('MAX(CASE WHEN ar.sent_at IS NOT NULL THEN 1 ELSE 0 END) as email_sent'),
                DB::raw("MAX(json_extract(class_sessions.acuity_data, '$.datetime')) as event_datetime"),
                DB::raw("MAX(json_extract(class_sessions.acuity_data, '$.duration')) as event_duration"),
                DB::raw("MAX(COALESCE(json_extract(class_sessions.acuity_data, '$.calendarTimezone'), json_extract(class_sessions.acuity_data, '$.timezone'), json_extract(class_sessions.acuity_data, '$.timeZone'))) as event_timezone"),
                DB::raw($this->studentLabelExpression().' as student_label'),
                DB::raw("MAX(CASE WHEN "
                    ."LOWER(COALESCE(class_sessions.calendar_name, '')) LIKE '%assessment%' "
                    ."OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(class_sessions.acuity_data, '$.type')), '')) LIKE '%assessment%' "
                    ."OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(class_sessions.acuity_data, '$.appointmentType.name')), '')) LIKE '%assessment%' "
                    ."THEN 1 ELSE 0 END) as is_assessment"),
            ])
            ->leftJoin('attendance_records as ar', function ($join) {
                $join->on('class_sessions.id', '=', 'ar.class_session_id')
                    ->whereIn('ar.status', ['present','late','absent']);
            })
            ->leftJoin('students', 'students.id', '=', 'class_sessions.student_id')
            ->whereDate('class_sessions.session_date', $date)
            ->where(function ($w) {
                $w->where('class_sessions.canceled', false)
                  ->orWhereNull('class_sessions.canceled')
                  ->orWhereIn('class_sessions.status', ['scheduled','confirmed']);
            })
            ->when($region, fn($q) => $q->where('class_sessions.location', $region))
            ->groupBy('class_sessions.session_date', 'class_sessions.location', 'class_sessions.calendar_name', 'event_start_key')
            ->orderBy('event_start_key');

        if (in_array($role, User::TEACHING_ROLES, true)) {
            $query->whereIn('class_sessions.id', TeacherRoster::sessions($user)->select('id'));
        }

        return $table
            ->query($query)
            ->headerActions($this->getDayHeaderActions())
            ->deferLoading()
            ->columns([
                Tables\Columns\TextColumn::make('raw_start_time')
                    ->label('Start')
                    ->sortable()
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->formatStateUsing(fn ($state, $record) => $this->formatTimeForUser($record, 'raw_start_time')),
                Tables\Columns\TextColumn::make('calendar_name')
                    ->label('Calendar')
                    ->limit(30)
                    ->wrap()
                    ->lineClamp(2)
                    ->visibleFrom('md'),
               Tables\Columns\TextColumn::make('student_label')
                    ->label('Student / Group')
                    ->limit(32)
                    ->wrap()
                    ->lineClamp(2)
                    ->formatStateUsing(function ($state) {
                        $value = is_string($state) ? trim($state) : '';

                        if ($value !== '') {
                            $value = trim($value, "\"' ");
                        }

                        return $value === '' ? '—' : $value;
                    }),
                Tables\Columns\TextColumn::make('location')
                    ->label('Region')
                    ->sortable()
                    ->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('session_count')
                    ->label('Sessions')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('attendance_rate')
                    ->label('Attendance')
                    ->sortable()
                    ->badge()
                    ->visibleFrom('lg')
                    ->formatStateUsing(function ($state, $record) {
                        if (Str::lower((string) ($record->location ?? '')) !== 'uk') {
                            return '—';
                        }

                        if (is_null($state)) {
                            return '—';
                        }

                        return number_format((float) $state, 1).'%';
                    })
                    ->color(function ($state, $record) {
                        if (Str::lower((string) ($record->location ?? '')) !== 'uk' || is_null($state)) {
                            return 'gray';
                        }

                        $value = (float) $state;
                        return $value >= 90 ? 'success' : ($value >= 75 ? 'warning' : 'danger');
                    }),
                Tables\Columns\IconColumn::make('email_sent')
                    ->label('Emails')
                    ->boolean()
                    ->state(function ($record) {
                        if (Str::lower((string) ($record->location ?? '')) !== 'uk') {
                            return null;
                        }

                        return (int) ($record->email_sent ?? 0) === 1;
                    })
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('viewStudents')
                    ->label('View students')
                    ->icon('heroicon-o-user-group')
                    ->visible(fn ($record) => Str::lower((string) ($record->location ?? '')) !== 'uk')
                    ->url(function ($record) {
                        $params = array_filter([
                            'calendar' => $record->calendar_name,
                            'event' => $this->cleanJsonString($record->event_datetime ?? null),
                            'timezone' => $this->cleanJsonString($record->event_timezone ?? null),
                            'duration' => $this->cleanJsonString($record->event_duration ?? null),
                            'date' => $this->normalizeDateString($record->session_date ?? null),
                            'start' => $record->raw_start_time,
                            'session' => $record->id,
                            'location' => $record->location ?? null,
                        ], fn ($value) => !is_null($value) && $value !== '');

                        $user = Auth::user();
                        $routeName = ($user && in_array(Str::lower((string) ($user->role ?? '')), User::TEACHING_ROLES, true))
                            ? 'filament.portal.pages.class-session-roster'
                            : 'filament.admin.pages.class-session-roster';

                        return route($routeName, $params);
                    })
                    ->openUrlInNewTab(false),
                Tables\Actions\Action::make('takeAttendanceUk')
                    ->label('')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->tooltip('Take attendance')
                    ->color('primary')
                    ->visible(fn ($record) => strtolower((string) ($record->location ?? '')) === 'uk')
                    ->url(function ($record) {
                        $user = Auth::user();
                        $routeName = ($user && in_array(Str::lower((string) ($user->role ?? '')), User::TEACHING_ROLES, true))
                            ? 'filament.portal.pages.take-attendance'
                            : 'filament.admin.pages.take-attendance';

                        return route($routeName, ['sessionId' => $record->id]);
                    })
                    ->button(),
            ])
            ->recordUrl(function ($record) {
                if (Str::lower((string) ($record->location ?? '')) !== 'uk') {
                    return null;
                }

                $user = Auth::user();
                $routeName = ($user && in_array(Str::lower((string) ($user->role ?? '')), User::TEACHING_ROLES, true))
                    ? 'filament.portal.pages.take-attendance'
                    : 'filament.admin.pages.take-attendance';

                return route($routeName, ['sessionId' => $record->id]);
            })
            ->recordClasses(function ($record) {
                $classes = [];
                if (Str::lower((string) ($record->location ?? '')) === 'uk') {
                    $classes[] = 'cursor-pointer';
                }
                if ((int) ($record->is_assessment ?? 0) === 1) {
                    // `!` modifier beats Filament's ->striped() `even:bg-gray-50`
                    // (which has (0,2,0) specificity via :nth-child(even)).
                    $classes[] = '!bg-purple-100 hover:!bg-purple-200 dark:!bg-purple-900/30 dark:hover:!bg-purple-900/50';
                }
                return $classes ? implode(' ', $classes) : null;
            })
            ->paginated(false)
            ->poll('60s')
            ->striped();
    }

    protected function getTableHeading(): string
    {
        $date = Carbon::parse($this->resolveDate())->locale(app()->getLocale());
        $dayLabel = $date->translatedFormat('l, d M');

        return "Today's Classes · {$dayLabel}";
    }

    protected function getDayHeaderActions(): array
    {
        $currentDate = $this->resolveDate();
        $options = collect($this->dateOptions());
        $actions = [];

        $actions[] = Action::make('previousWeek')
            ->icon('heroicon-o-chevron-left')
            ->label('Prev')
            ->tooltip('Previous week')
            ->color('gray')
            ->outlined()
            ->size('sm')
            ->button()
            ->visible(fn () => ! $this->isMobileView())
            ->action(fn () => $this->shiftWeek(-1));

        if (! $this->isCurrentWeek()) {
            $actions[] = Action::make('thisWeek')
                ->label('This week')
                ->tooltip('Jump to the current week')
                ->color('gray')
                ->outlined()
                ->size('sm')
                ->button()
                ->action(fn () => $this->goToCurrentWeek());
        }

        $dayActions = $options
            ->map(function (array $option) use ($currentDate) {
                $isActive = $option['date'] === $currentDate;

                return Action::make('day_'.$option['date'])
                    ->label($option['label'])
                    ->tooltip($option['tooltip'])
                    ->color($isActive ? 'primary' : 'gray')
                    ->outlined(! $isActive)
                    ->size('sm')
                    ->button()
                    ->action(fn () => $this->setDay($option['date']));
            })
            ->values()
            ->toArray();

        $actions = array_merge($actions, $dayActions);

        $actions[] = Action::make('nextWeek')
            ->icon('heroicon-o-chevron-right')
            ->label('Next')
            ->tooltip('Next week')
            ->color('gray')
            ->outlined()
            ->size('sm')
            ->button()
            ->visible(fn () => ! $this->isMobileView())
            ->action(fn () => $this->shiftWeek(1));

        return $actions;
    }

    private function isMobileView(): bool
    {
        return request()->header('User-Agent') && preg_match('/Mobile|Android|iP(ad|hone)/i', request()->header('User-Agent')) > 0;
    }

    private function studentLabelExpression(): string
    {
        $studentName = $this->studentNameExpression();
        $clientName = $this->clientNameExpression();
        $clientFallback = $this->clientFallbackNameExpression();
        $appointmentType = $this->appointmentTypeExpression();

        return "CASE WHEN COUNT(DISTINCT class_sessions.id) > 1 "
            . "THEN MAX({$appointmentType}) "
            . "ELSE MAX(COALESCE({$studentName}, {$clientName}, {$clientFallback}, class_sessions.calendar_name)) END";
    }

    private function studentNameExpression(): string
    {
        $driver = DB::connection()->getDriverName();

        if (Str::contains($driver, ['mysql', 'mariadb'])) {
            return "NULLIF(TRIM(CONCAT_WS(' ', students.first_name, students.last_name)), '')";
        }

        return "NULLIF(TRIM(COALESCE(students.first_name, '') || CASE WHEN students.last_name IS NOT NULL AND students.last_name <> '' THEN ' ' || students.last_name ELSE '' END), '')";
    }

    private function clientNameExpression(): string
    {
        $driver = DB::connection()->getDriverName();
        $first = "COALESCE(json_extract(class_sessions.acuity_data, '$.client.firstName'), json_extract(class_sessions.acuity_data, '$.Client.firstName'))";
        $last = "COALESCE(json_extract(class_sessions.acuity_data, '$.client.lastName'), json_extract(class_sessions.acuity_data, '$.Client.lastName'))";

        if (Str::contains($driver, ['mysql', 'mariadb'])) {
            return "NULLIF(TRIM(CONCAT_WS(' ', {$first}, {$last})), '')";
        }

        return "NULLIF(TRIM(COALESCE({$first}, '') || CASE WHEN {$last} IS NOT NULL AND {$last} <> '' THEN ' ' || {$last} ELSE '' END), '')";
    }

    private function clientFallbackNameExpression(): string
    {
        $driver = DB::connection()->getDriverName();
        $first = "COALESCE(json_extract(class_sessions.acuity_data, '$.firstName'), json_extract(class_sessions.acuity_data, '$.FirstName'))";
        $last = "COALESCE(json_extract(class_sessions.acuity_data, '$.lastName'), json_extract(class_sessions.acuity_data, '$.LastName'))";

        if (Str::contains($driver, ['mysql', 'mariadb'])) {
            return "NULLIF(TRIM(CONCAT_WS(' ', {$first}, {$last})), '')";
        }

        return "NULLIF(TRIM(COALESCE({$first}, '') || CASE WHEN {$last} IS NOT NULL AND {$last} <> '' THEN ' ' || {$last} ELSE '' END), '')";
    }

    private function appointmentTypeExpression(): string
    {
        return "NULLIF(COALESCE(json_extract(class_sessions.acuity_data, '$.type'), json_extract(class_sessions.acuity_data, '$.appointmentType.name'), json_extract(class_sessions.acuity_data, '$.classType')), '')";
    }

    protected function resolveRegion(): ?string
    {
        $user = Auth::user();
        $preferred = session('preferred_region') ?? ($user?->preferred_region ?: null);

        $normalize = static function (?string $value): ?string {
            if (! is_string($value)) {
                return null;
            }

            return match (strtolower(trim($value))) {
                'uk', 'united kingdom', 'gb', 'great britain' => 'UK',
                'spain', 'es' => 'Spain',
                'france', 'fr' => 'France',
                default => null,
            };
        };

        $normalized = $normalize($preferred);

        if ($user && $user->restrictsByRegion()) {
            $allowed = collect($user->allowedRegions())
                ->map($normalize)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (empty($allowed)) {
                return null;
            }

            if (! $normalized || ! in_array($normalized, $allowed, true)) {
                $normalized = $allowed[0];
                session(['preferred_region' => $normalized]);
            }
        } elseif ($normalized) {
            session(['preferred_region' => $normalized]);
        }

        return $normalized;
    }

    protected function resolveDate(): string
    {
        $weekStart = $this->resolveWeekStart();
        $weekEnd = $weekStart->copy()->addDays(5);
        $candidate = $this->selectedDate ?? session('dashboard_upcoming_selected_date');

        if ($candidate && $this->isSelectableDate($candidate)) {
            $candidateDate = Carbon::parse($candidate)->toDateString();

            if (Carbon::parse($candidateDate)->betweenIncluded($weekStart, $weekEnd)) {
                $this->selectedDate = $candidateDate;
                session(['dashboard_upcoming_selected_date' => $candidateDate]);

                return $candidateDate;
            }
        }

        $today = Carbon::now();
        if ($today->isSunday()) {
            $today = $today->copy()->addDay();
        }

        $fallback = $today->copy();
        if (! $fallback->betweenIncluded($weekStart, $weekEnd)) {
            $fallback = $weekStart->copy();
        }

        $candidateDate = $fallback->toDateString();
        $this->selectedDate = $candidateDate;
        session(['dashboard_upcoming_selected_date' => $candidateDate]);

        return $candidateDate;
    }

    protected function setDay(string $date): void
    {
        if (! $this->isSelectableDate($date)) {
            return;
        }

        $parsed = Carbon::parse($date);
        $this->selectedDate = $parsed->toDateString();
        session(['dashboard_upcoming_selected_date' => $this->selectedDate]);

        $this->selectedWeekStart = $parsed->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        session(['dashboard_upcoming_selected_week_start' => $this->selectedWeekStart]);
        $this->resetTable();
        $this->dispatch('$refresh');
    }

    protected function isSelectableDate(string $date): bool
    {
        try {
            $carbon = Carbon::parse($date);
        } catch (\Throwable $e) {
            return false;
        }

        return ! $carbon->isSunday();
    }

    protected function dateOptions(): array
    {
        $start = $this->resolveWeekStart()->copy();

        return collect(range(0, 5))
            ->map(function (int $offset) use ($start) {
                $date = $start->copy()->addDays($offset);

                return [
                    'date' => $date->toDateString(),
                    'label' => $date->locale(app()->getLocale())->isoFormat('ddd'),
                    'tooltip' => $date->locale(app()->getLocale())->isoFormat('dddd, D MMM'),
                ];
            })
            ->toArray();
    }

    protected function resolveWeekStart(): Carbon
    {
        $storedWeek = $this->selectedWeekStart ?? session('dashboard_upcoming_selected_week_start');

        if ($storedWeek) {
            try {
                $week = Carbon::parse($storedWeek)->startOfWeek(Carbon::MONDAY);
            } catch (\Throwable $e) {
                $week = null;
            }

            if ($week) {
                $this->selectedWeekStart = $week->toDateString();
                session(['dashboard_upcoming_selected_week_start' => $this->selectedWeekStart]);

                return $week->copy();
            }
        }

        $dateCandidate = $this->selectedDate ?? session('dashboard_upcoming_selected_date');

        if ($dateCandidate && $this->isSelectableDate($dateCandidate)) {
            $week = Carbon::parse($dateCandidate)->startOfWeek(Carbon::MONDAY);
            $this->selectedWeekStart = $week->toDateString();
            session(['dashboard_upcoming_selected_week_start' => $this->selectedWeekStart]);

            return $week->copy();
        }

        $today = Carbon::now();
        if ($today->isSunday()) {
            $today = $today->copy()->addDay();
        }

        $week = $today->copy()->startOfWeek(Carbon::MONDAY);
        $this->selectedWeekStart = $week->toDateString();
        session(['dashboard_upcoming_selected_week_start' => $this->selectedWeekStart]);

        return $week->copy();
    }

    protected function shiftWeek(int $weeks): void
    {
        if ($weeks === 0) {
            return;
        }

        $current = Carbon::parse($this->resolveDate());
        $target = $current->copy()->addWeeks($weeks);

        if ($target->isSunday()) {
            $target = $target->subDay();
        }

        $this->selectedWeekStart = $target->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        session(['dashboard_upcoming_selected_week_start' => $this->selectedWeekStart]);

        $this->selectedDate = $target->toDateString();
        session(['dashboard_upcoming_selected_date' => $this->selectedDate]);

        $this->resetTable();
        $this->dispatch('$refresh');
    }

    protected function goToCurrentWeek(): void
    {
        $today = Carbon::now();
        if ($today->isSunday()) {
            $today = $today->copy()->addDay();
        }

        $this->selectedWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        session(['dashboard_upcoming_selected_week_start' => $this->selectedWeekStart]);

        $this->selectedDate = $today->toDateString();
        session(['dashboard_upcoming_selected_date' => $this->selectedDate]);

        $this->resetTable();
        $this->dispatch('$refresh');
    }

    protected function isCurrentWeek(): bool
    {
        $today = Carbon::now();
        if ($today->isSunday()) {
            $today = $today->copy()->addDay();
        }

        $currentWeek = $today->copy()->startOfWeek(Carbon::MONDAY);
        $selectedWeek = $this->resolveWeekStart();

        return $selectedWeek->isSameDay($currentWeek);
    }
}
