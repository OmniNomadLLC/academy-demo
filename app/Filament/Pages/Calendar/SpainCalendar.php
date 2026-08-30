<?php

namespace App\Filament\Pages\Calendar;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Pages\CalendarOverviewPage;
use App\Services\LocationMappingService;

class SpainCalendar extends CalendarOverviewPage
{
    use RequiresRegionAccess;

    protected static ?string $navigationGroup = 'Spain';
    protected static ?string $navigationLabel = 'Calendar';
    protected static ?string $slug = 'spain/calendar';
    public static string $requiredRegion = 'Spain';

    public static function getNavigationGroup(): ?string
    {
        return 'Spain';
    }

    public function mount(): void
    {
        parent::mount();
        $this->region = 'Spain';
    }

    protected function buildBaseQuery(array $filters)
    {
        $filters['region'] = 'Spain';
        $q = parent::buildBaseQuery($filters);
        $keywords = LocationMappingService::keywordsForRegion('Spain');
        if (!empty($keywords)) {
            $q->where(function ($w) use ($keywords) {
                foreach ($keywords as $kw) {
                    $w->orWhere('class_sessions.category_norm', 'like', '%'.$kw.'%');
                }
            });
        }
        return $q;
    }

    protected function showRegionFilter(): bool
    {
        return false;
    }
}
