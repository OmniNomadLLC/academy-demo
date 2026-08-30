<?php

namespace App\Filament\Resources\FranceReportResource\Pages;

use App\Filament\Resources\FranceReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFranceReports extends ListRecords
{
    protected static string $resource = FranceReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
