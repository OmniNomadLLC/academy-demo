<?php

namespace App\Filament\Resources\UKCalendarResource\Pages;

use App\Filament\Resources\UKCalendarResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUKCalendar extends EditRecord
{
    protected static string $resource = UKCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
