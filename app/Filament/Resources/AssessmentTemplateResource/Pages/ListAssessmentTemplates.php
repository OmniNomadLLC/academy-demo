<?php

namespace App\Filament\Resources\AssessmentTemplateResource\Pages;

use App\Filament\Resources\AssessmentTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAssessmentTemplates extends ListRecords
{
    protected static string $resource = AssessmentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
