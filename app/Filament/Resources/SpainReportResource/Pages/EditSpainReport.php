<?php

namespace App\Filament\Resources\SpainReportResource\Pages;

use App\Filament\Resources\SpainReportResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSpainReport extends EditRecord
{
    protected static string $resource = SpainReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
