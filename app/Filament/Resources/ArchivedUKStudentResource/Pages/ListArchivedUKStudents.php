<?php

namespace App\Filament\Resources\ArchivedUKStudentResource\Pages;

use App\Filament\Resources\ArchivedUKStudentResource;
use Filament\Resources\Pages\ListRecords;

class ListArchivedUKStudents extends ListRecords
{
    protected static string $resource = ArchivedUKStudentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
