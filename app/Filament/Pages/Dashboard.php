<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user || ! $user->hasDomainAccess('dashboard')) {
            return false;
        }

        return parent::canAccess();
    }

    public function getTitle(): string
    {
        return 'Language Academy Dashboard';
    }

    public function getSubheading(): string
    {
        return 'Real-time management system with automated Acuity synchronization';
    }

    protected function getHeaderActions(): array
    {
        // Region filter moved to the top header next to the Dashboard title
        $makeBtn = function (string $label, ?string $value) {
            $key = $value ? strtolower($value) : 'all';
            return Action::make('region_'.$key)
                ->label($label)
                ->color(fn() => (optional(Auth::user())->preferred_region === $value) ? 'primary' : 'gray')
                ->button()
                ->outlined(fn() => (optional(Auth::user())->preferred_region !== $value))
                ->size('sm')
                ->action(function () use ($value) {
                    session(['preferred_region' => $value]);

                    if ($u = Auth::user()) {
                        $u->preferred_region = $value ?: null;
                        $u->save();
                    }

                    $this->redirectRoute('filament.admin.pages.dashboard');
                });
        };

        $user = Auth::user();
        $buttons = [];

        if (! $user || ! $user->restrictsByRegion()) {
            $buttons[] = $makeBtn('All', null);
        }

        $regions = $user ? $user->allowedRegions() : \App\Models\User::REGIONS;
        foreach ($regions as $region) {
            $buttons[] = $makeBtn($region, $region);
        }

        return $buttons;
    }

    // Remove widgets from the default Dashboard; Control Panel hosts them instead.
    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\DuplicateStudentSuggestionsWidget::class, // pinned top via sort=-1000
            \App\Filament\Widgets\KpiOverview::class,
            \App\Filament\Widgets\TodaysOperations::class,
            \App\Filament\Widgets\TodaysUpcomingClasses::class,
        ];
    }
}
