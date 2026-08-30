<?php

namespace App\Filament\Resources\SpainStudentResource\Pages;

use App\Filament\Resources\SpainStudentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSpainStudent extends EditRecord
{
    protected static string $resource = SpainStudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('view')
                ->label('View profile')
                ->icon('heroicon-o-user')
                ->url(fn () => static::getResource()::getUrl('view', ['record' => $this->getRecord()]))
                ->color('gray'),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCancelRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    public function getBreadcrumbs(): array
    {
        $record = $this->getRecord();

        $studentName = trim((string) ($record->full_name ?? $record->name ?? 'Student'));

        return [
            static::getResource()::getUrl() => static::getResource()::getBreadcrumb(),
            static::getResource()::getUrl('view', ['record' => $record]) => $studentName,
            null => 'Edit',
        ];
    }
}
