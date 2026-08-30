<?php

namespace App\Filament\Resources\UKStudentResource\Pages;

use App\Filament\Resources\UKStudentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\RestoreBulkAction;
use Illuminate\Support\Facades\Auth;

class ListUKStudents extends ListRecords
{
    protected static string $resource = UKStudentResource::class;

    protected function getHeaderActions(): array
    {
        if ($this->userIsUkManager()) {
            return [];
        }

        return [
            Actions\CreateAction::make(),
        ];
    }


    public function getTabs(): array
    {
        return [
            'all' => \Filament\Resources\Components\Tab::make('All'),
            'low' => \Filament\Resources\Components\Tab::make('Low Attendance')
                ->modifyQueryUsing(fn($query) => $query->whereNotNull('attendance_rate')->where('attendance_rate', '<', 75))
                ->badgeColor('danger'),
        ];
    }

    protected function getTableBulkActions(): array
    {
        if ($this->userIsUkManager()) {
            return [];
        }

        return [
            RestoreBulkAction::make(),
            Actions\DeleteBulkAction::make(),
        ];
    }

    protected function userIsUkManager(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isUkManager() ?? false);
    }
}
