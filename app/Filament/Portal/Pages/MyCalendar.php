<?php

namespace App\Filament\Portal\Pages;

use App\Filament\Pages\Calendar\AbstractCalendarPage;
use App\Support\TeacherRoster;
use App\Support\CalendarQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class MyCalendar extends AbstractCalendarPage
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'My Calendar';
    protected static ?string $title = 'My Teaching Calendar';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationGroup = null;
    protected static string $routePath = 'my-calendar';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->isTeachingRole() || $user->hasRole('admin', 'super_admin');
    }

    public function getCalendarOptions(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $expr = "COALESCE(json_extract(acuity_data, '$.calendar'), json_extract(acuity_data, '$.calendarName'), json_extract(acuity_data, '$.calendar.name'), calendar_name)";

        $rows = TeacherRoster::sessions($user)
            ->selectRaw('DISTINCT '.$expr.' as cal')
            ->orderBy('cal')
            ->pluck('cal')
            ->toArray();

        return collect($rows)
            ->filter()
            ->map(function ($value) {
                $label = is_string($value) ? $value : (string) $value;
                $label = trim(str_replace('"', '', $label));
                return $label === '' ? null : $label;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function getEvents(): array
    {
        return $this->fetchEvents(null, null);
    }

    public function getEventsInRange(string $start, string $end): array
    {
        return $this->fetchEvents(Carbon::parse($start), Carbon::parse($end));
    }

    protected function fetchEvents(?Carbon $from, ?Carbon $to): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $builder = CalendarQuery::sessions(null, $this->selectedCalendars, $this->search, $from, $to)
            ->whereIn('class_sessions.id', TeacherRoster::sessions($user)->select('id'))
            ->limit(1500);

        $rows = $builder->get();

        return CalendarQuery::mapToEvents($rows);
    }

    public function studentProfilePathPrefix(): string
    {
        return 'portal/students';
    }

    protected function getViewData(): array
    {
        $user = Auth::user();

        return [
            'timezone' => $user?->timezone ?: (string) config('app.timezone'),
        ];
    }

    protected function getDefaultViewMode(): string
    {
        return 'day';
    }

    protected function getCalendarPreferenceKey(): string
    {
        return 'portal:my_calendar_view';
    }
}
