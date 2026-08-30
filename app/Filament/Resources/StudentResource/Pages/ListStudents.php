<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Forms;
use Illuminate\Support\Collection;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\RestoreBulkAction;

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    public function getTitle(): string
    {
        return 'Students Management';
    }

    public function getSubheading(): string
    {
        return 'Manage student information imported from Acuity Scheduling';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add Student'),
        ];
    }

    protected function getTableBulkActions(): array
    {
        return [
            Actions\BulkAction::make('assignManager')
                ->label('Assign Manager (bulk)')
                ->icon('heroicon-o-user-group')
                ->form([
                    Forms\Components\Select::make('manager_id')
                        ->label('Manager')
                        ->relationship('manager','name')
                        ->searchable()
                        ->preload()
                        ->required(),
                ])
                ->action(function (Collection $records, array $data): void {
                    $mid = $data['manager_id'] ?? null;
                    if (!$mid) return;
                    foreach ($records as $record) {
                        $record->update(['manager_id' => $mid]);
                    }
                    Notification::make()->title('Assigned manager to '.count($records).' students')->success()->send();
                }),
            Actions\BulkAction::make('unassignManager')
                ->label('Unassign Manager (bulk)')
                ->icon('heroicon-o-user-minus')
                ->requiresConfirmation()
                ->action(function (\Illuminate\Support\Collection $records): void {
                    foreach ($records as $record) {
                        $record->update(['manager_id' => null]);
                    }
                    \Filament\Notifications\Notification::make()->title('Unassigned manager from '.count($records).' students')->success()->send();
                }),
            RestoreBulkAction::make(),
            Actions\DeleteBulkAction::make(),
        ];
    }

}
