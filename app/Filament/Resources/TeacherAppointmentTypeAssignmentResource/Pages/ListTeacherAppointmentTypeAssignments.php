<?php

namespace App\Filament\Resources\TeacherAppointmentTypeAssignmentResource\Pages;

use App\Filament\Resources\TeacherAppointmentTypeAssignmentResource;
use App\Models\TeacherAppointmentTypeCatalog;
use App\Support\TeacherAppointmentTypeCatalogBuilder;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTeacherAppointmentTypeAssignments extends ListRecords
{
    protected static string $resource = TeacherAppointmentTypeAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getTableQuery(): Builder
    {
        if (! TeacherAppointmentTypeCatalog::query()->exists()) {
            TeacherAppointmentTypeCatalogBuilder::rebuild();
        }

        return TeacherAppointmentTypeAssignmentResource::tableQueryWithUniverse();
    }
}
