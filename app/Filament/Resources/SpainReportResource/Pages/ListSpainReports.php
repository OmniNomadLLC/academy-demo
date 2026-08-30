<?php

namespace App\Filament\Resources\SpainReportResource\Pages;

use App\Filament\Resources\SpainReportResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSpainReports extends ListRecords
{
    protected static string $resource = SpainReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
