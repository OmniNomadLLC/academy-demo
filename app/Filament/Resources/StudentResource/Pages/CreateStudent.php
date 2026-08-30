<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    protected function afterCreate(): void
    {
        try {
            $record = $this->record; // App\Models\Student
            if ($record && filter_var($record->email, FILTER_VALIDATE_EMAIL)) {
                if (filter_var((string) env('ENROLLMENT_EMAILS_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
                    \Mail::to($record->email)->queue(new \App\Mail\WelcomeEnrollment($record));
                }
            }
        } catch (\Throwable $e) {
            // Swallow errors so UI flow is not interrupted
        }
    }
}
