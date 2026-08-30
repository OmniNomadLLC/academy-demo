<?php

namespace App\Filament\Pages\Calendar;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Pages\CalendarOverviewPage;
use Illuminate\Support\Facades\DB;
use App\Services\LocationMappingService;

class UKCalendar extends CalendarOverviewPage
{
    use RequiresRegionAccess;

    protected static ?string $navigationGroup = 'UK';
    protected static ?string $navigationLabel = 'Calendar';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'u-k/calendar';
    public static string $requiredRegion = 'UK';

    public static function getNavigationGroup(): ?string
    {
        return 'UK';
    }

    public function mount(): void
    {
        parent::mount();
        $this->region = 'UK';
        $this->timezone = 'Europe/London';
    }

    protected function buildBaseQuery(array $filters)
    {
        // Force region to UK and include category keyword matching for UK
        $filters['region'] = 'UK';
        $q = parent::buildBaseQuery($filters);
        $keywords = LocationMappingService::keywordsForRegion('UK');
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
