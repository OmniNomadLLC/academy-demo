<?php

namespace App\Filament\Resources\FranceStudentResource\Pages;

use App\Filament\Resources\Concerns\HandlesEnrollmentActions;
use App\Filament\Resources\Concerns\HasStudentFooterWidgets;
use App\Filament\Resources\FranceStudentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFranceStudent extends ViewRecord
{
    use HasStudentFooterWidgets;
    use HandlesEnrollmentActions;

    protected static string $resource = FranceStudentResource::class;

    protected function getHeaderActions(): array
    {
        return array_merge(
            [Actions\EditAction::make()],
            $this->enrollmentActions()
        );
    }
}
