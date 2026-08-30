<?php

namespace App\Filament\Resources\ManagerResource\RelationManagers;

use App\Models\Student;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';
    protected static ?string $recordTitleAttribute = 'full_name';
    protected static ?string $title = 'Linked Students';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('attendance_rate')->label('Attendance')->badge()
                    ->formatStateUsing(fn($record) => number_format((float)($record->attendance_rate ?? 0), 2).'%')
                    ->color(fn($record) => ($record->attendance_rate ?? 0) < 75 ? 'danger' : 'success'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add & create Student')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['manager_id'] = $this->getOwnerRecord()->getKey();
                        return $data;
                    })
                    ->form([
                        Forms\Components\TextInput::make('first_name')->required(),
                        Forms\Components\TextInput::make('last_name')->required(),
                        Forms\Components\TextInput::make('email')->email()->required(),
                        Forms\Components\TextInput::make('phone')->tel()->nullable(),
                    ]),
                Action::make('linkExisting')
                    ->label('Link existing Student')
                    ->icon('heroicon-o-link')
                    ->form([
                        Forms\Components\Select::make('student_id')
                            ->label('Student')
                            ->options(fn() => Student::query()
                                ->whereNull('manager_id')
                                ->orderBy('last_name')
                                ->limit(500)
                                ->get()
                                ->mapWithKeys(fn($s) => [$s->id => ($s->first_name.' '.$s->last_name.' <'.$s->email.'>')])
                                ->toArray())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $sid = (int)($data['student_id'] ?? 0);
                        if ($sid > 0) {
                            Student::where('id', $sid)->update(['manager_id' => $this->getOwnerRecord()->getKey()]);
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        // Ensure manager stays linked
                        $data['manager_id'] = $this->getOwnerRecord()->getKey();
                        return $data;
                    })
                    ->form([
                        Forms\Components\TextInput::make('first_name')->required(),
                        Forms\Components\TextInput::make('last_name')->required(),
                        Forms\Components\TextInput::make('email')->email()->required(),
                        Forms\Components\TextInput::make('phone')->tel()->nullable(),
                    ]),
                Action::make('unlink')
                    ->label('Unlink')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn($record) => $record->update(['manager_id' => null])),
            ])
            ->defaultSort('last_name');
    }
}

