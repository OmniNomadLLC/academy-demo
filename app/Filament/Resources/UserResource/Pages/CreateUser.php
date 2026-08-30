<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        $this->syncAppointmentTypes();
    }

    private function syncAppointmentTypes(): void
    {
        $record = $this->record;
        if (! $record instanceof User) {
            return;
        }

        $state = $this->form->getRawState();

        \Log::debug('CreateUser form state', [
            'user_id' => $record->id,
            'calendar_id' => $state['acuity_calendar_id'] ?? null,
            'calendar_assignments' => $state['teacher_calendar_assignments'] ?? null,
        ]);

        UserResource::syncTeacherCalendars(
            $record,
            $state['teacher_calendar_assignments'] ?? [],
            $state['acuity_calendar_id'] ?? null
        );
    }
}
