<?php

namespace App\Filament\Resources\ClassLocationResource\Pages;

use App\Filament\Resources\ClassLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditClassLocation extends EditRecord
{
    protected static string $resource = ClassLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $calendarName = $data['primary_calendar'] ?? null;

        $record = parent::handleRecordUpdate($record, $data);

        ClassLocationResource::syncCalendarsForRecord($record, $calendarName);

        return $record;
    }
}
