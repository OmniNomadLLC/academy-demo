<?php

namespace App\Filament\Resources\FranceStudentResource\Pages;

use App\Filament\Resources\FranceStudentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\RestoreBulkAction;

class ListFranceStudents extends ListRecords
{
    protected static string $resource = FranceStudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableBulkActions(): array
    {
        return [
            RestoreBulkAction::make(),
            Actions\DeleteBulkAction::make(),
        ];
    }
}
