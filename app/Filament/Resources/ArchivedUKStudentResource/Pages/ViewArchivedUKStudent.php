<?php

namespace App\Filament\Resources\ArchivedUKStudentResource\Pages;

use App\Filament\Resources\ArchivedUKStudentResource;
use App\Filament\Resources\Concerns\HasStudentFooterWidgets;
use App\Livewire\Concerns\HasStudentProfileTabs;
use Filament\Resources\Pages\ViewRecord;

class ViewArchivedUKStudent extends ViewRecord
{
    use HasStudentFooterWidgets;
    use HasStudentProfileTabs;

    protected static string $resource = ArchivedUKStudentResource::class;
    protected static string $view = 'filament.resources.archived-u-k-student-resource.pages.view-archived-u-k-student';

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getViewData(): array
    {
        return parent::getViewData() + [
            'activeTab' => $this->activeTab,
        ];
    }
}
