<?php

namespace App\Filament\Pages\Calendar;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Pages\CalendarOverviewPage;
use App\Services\LocationMappingService;

class FranceCalendar extends CalendarOverviewPage
{
    use RequiresRegionAccess;

    protected static ?string $navigationGroup = 'France';
    protected static ?string $navigationLabel = 'Calendar';
    protected static ?string $slug = 'france/calendar';
    public static string $requiredRegion = 'France';

    public static function getNavigationGroup(): ?string
    {
        return 'France';
    }

    public function mount(): void
    {
        parent::mount();
        $this->region = 'France';
    }

    protected function buildBaseQuery(array $filters)
    {
        $filters['region'] = 'France';
        $q = parent::buildBaseQuery($filters);
        $keywords = LocationMappingService::keywordsForRegion('France');
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
