<?php

namespace App\Filament\Resources\UKStudentResource\Pages;

use App\Filament\Resources\Concerns\HandlesEnrollmentActions;
use App\Filament\Resources\Concerns\HasStudentFooterWidgets;
use App\Filament\Resources\UKStudentResource;
use App\Livewire\Concerns\HandlesStudentPhotos;
use App\Livewire\Concerns\HasStudentProfileTabs;
use App\Models\Student;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewUKStudent extends ViewRecord
{
    use HasStudentFooterWidgets;
    use HandlesEnrollmentActions;
    use HasStudentProfileTabs;
    use HandlesStudentPhotos;

    protected static string $resource = UKStudentResource::class;
    protected static string $view = 'filament.resources.uk-student-resource.pages.view-u-k-student';

    protected function getHeaderActions(): array
    {
        return $this->enrollmentActions();
    }

    protected function getViewData(): array
    {
        $canManage = $this->userCanManageStudent();

        return parent::getViewData() + [
            'activeTab' => $this->activeTab,
            'photoActions' => $canManage,
            'allowAdminForms' => $canManage,
        ];
    }

    protected function getStudentModel(): ?Student
    {
        return $this->record;
    }

    protected function userCanManageStudent(): bool
    {
        $user = Auth::user();
        $record = $this->record;

        if (! $user || ! $record) {
            return false;
        }

        return $user->can('update', $record);
    }
}
