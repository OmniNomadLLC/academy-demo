<?php

namespace App\Filament\Resources\SpainStudentResource\Pages;

use App\Filament\Resources\SpainStudentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\RestoreBulkAction;

class ListSpainStudents extends ListRecords
{
    protected static string $resource = SpainStudentResource::class;

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
