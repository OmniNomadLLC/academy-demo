<?php

namespace App\Filament\Pages\Calendar;

use App\Support\CalendarQuery;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

abstract class AbstractCalendarPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    public ?string $viewMode = null;
    #[Url]
    public ?string $date = null; // YYYY-MM-DD
    public array $selectedCalendars = [];
    public ?string $search = null;

    protected string $region = '';

    protected static string $view = 'calendar.page';

    public function mount(): void
    {
        $this->date = $this->date ?: now()->toDateString();
        $this->viewMode = $this->resolveInitialViewMode($this->viewMode);
    }

    public function getRegion(): ?string
    {
        return $this->region ?: null;
    }

    public function getCalendarOptions(): array
    {
        return CalendarQuery::distinctCalendars($this->getRegion());
    }

    public function getEvents(): array
    {
        $rows = CalendarQuery::sessions(
            $this->getRegion(),
            $this->selectedCalendars,
            $this->search,
        )->limit(1000)->get();

        return CalendarQuery::mapToEvents($rows);
    }

    public function getEventsInRange(string $start, string $end): array
    {
        $from = \Carbon\Carbon::parse($start);
        $to = \Carbon\Carbon::parse($end);
        $rows = CalendarQuery::sessions(
            $this->getRegion(),
            $this->selectedCalendars,
            $this->search,
            $from,
            $to
        )->limit(1500)->get();
        return CalendarQuery::mapToEvents($rows);
    }

    public function today(): void
    {
        $this->date = now()->toDateString();
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = $this->normalizeViewMode($mode);
        $this->storeViewPreference($this->viewMode);
    }

    public function studentProfilePathPrefix(): string
    {
        return 'admin/students';
    }

    protected function resolveInitialViewMode(?string $candidate): string
    {
        $saved = $this->getSavedViewMode();
        $mode = $candidate ?? $saved ?? $this->getDefaultViewMode();

        return $this->normalizeViewMode($mode);
    }

    protected function normalizeViewMode(?string $mode): string
    {
        $allowed = ['month', 'week', 'day'];
        return in_array($mode, $allowed, true) ? $mode : $this->getDefaultViewMode();
    }

    protected function getDefaultViewMode(): string
    {
        return 'week';
    }

    protected function getCalendarPreferenceKey(): string
    {
        return static::class . ':calendar_view';
    }

    protected function getSavedViewMode(): ?string
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        $prefs = $user->panel_preferences ?? null;
        if (! is_array($prefs)) {
            return null;
        }

        $key = $this->getCalendarPreferenceKey();
        $value = $prefs[$key] ?? null;

        return in_array($value, ['month', 'week', 'day'], true) ? $value : null;
    }

    protected function storeViewPreference(string $mode): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $current = $user->panel_preferences;
        $prefs = is_array($current) ? $current : [];
        $key = $this->getCalendarPreferenceKey();

        if (($prefs[$key] ?? null) === $mode) {
            return;
        }

        $prefs[$key] = $mode;

        $user->forceFill(['panel_preferences' => $prefs])->save();
    }
}
