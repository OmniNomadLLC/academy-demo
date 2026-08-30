<?php

namespace App\Filament\Resources\SpainStudentResource\Pages;

use App\Filament\Resources\Concerns\HandlesEnrollmentActions;
use App\Filament\Resources\Concerns\HasStudentFooterWidgets;
use App\Filament\Resources\SpainStudentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSpainStudent extends ViewRecord
{
    use HasStudentFooterWidgets;
    use HandlesEnrollmentActions;

    protected static string $resource = SpainStudentResource::class;

    protected function getHeaderActions(): array
    {
        return array_merge(
            [Actions\EditAction::make()],
            $this->enrollmentActions()
        );
    }
}
