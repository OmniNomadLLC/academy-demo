<?php

namespace App\Filament\Resources\SpainCalendarResource\Pages;

use App\Filament\Resources\SpainCalendarResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSpainCalendar extends EditRecord
{
    protected static string $resource = SpainCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
