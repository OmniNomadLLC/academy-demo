<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\Concerns\HandlesEnrollmentActions;
use App\Filament\Resources\Concerns\HasStudentFooterWidgets;
use App\Filament\Resources\StudentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewStudent extends ViewRecord
{
    use HasStudentFooterWidgets;
    use HandlesEnrollmentActions;

    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return array_merge(
            [Actions\EditAction::make()],
            $this->enrollmentActions()
        );
    }
}
