<?php

namespace App\Filament\Resources\ClassLocationResource\Pages;

use App\Filament\Resources\ClassLocationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateClassLocation extends CreateRecord
{
    protected static string $resource = ClassLocationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $calendarName = $data['primary_calendar'] ?? null;

        $record = parent::handleRecordCreation($data);

        ClassLocationResource::syncCalendarsForRecord($record, $calendarName);

        return $record;
    }
}
