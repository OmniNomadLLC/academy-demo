<?php

namespace App\Filament\Portal\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PortalDashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static ?string $navigationGroup = null;
    protected static ?int $navigationSort = 1;
    protected static string $routePath = '/';
    protected static ?string $navigationLabel = 'Dashboard';
    protected static ?string $title = null;

    public function getSubheading(): string
    {
        return '';
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\TodaysUpcomingClasses::class,
            \App\Filament\Portal\Widgets\PortalRosterStats::class,
        ];
    }

    public function getColumns(): int | string | array
    {
        return [
            'default' => 1,
            'md' => 1,
            'lg' => 2,
        ];
    }

    public function getTitle(): string
    {
        $user = Auth::user();
        $name = is_string($user?->name) && trim($user->name) !== ''
            ? trim($user->name)
            : 'My';

        $suffix = Str::endsWith(Str::lower($name), 's') ? "'" : "'s";

        return $name.$suffix.' Teaching Hub';
    }
}
