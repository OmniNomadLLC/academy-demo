<?php

namespace App\Filament\Resources\FranceCalendarResource\Pages;

use App\Filament\Resources\FranceCalendarResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFranceCalendar extends EditRecord
{
    protected static string $resource = FranceCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
