<?php

namespace App\Filament\Resources\UKCalendarResource\Pages;

use App\Filament\Resources\UKCalendarResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUKCalendars extends ListRecords
{
    protected static string $resource = UKCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
