<?php

namespace App\Filament\Resources\SpainCalendarResource\Pages;

use App\Filament\Resources\SpainCalendarResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSpainCalendars extends ListRecords
{
    protected static string $resource = SpainCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
