<?php

namespace App\Filament\Resources\ClassLocationResource\Pages;

use App\Filament\Resources\ClassLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListClassLocations extends ListRecords
{
    protected static string $resource = ClassLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
