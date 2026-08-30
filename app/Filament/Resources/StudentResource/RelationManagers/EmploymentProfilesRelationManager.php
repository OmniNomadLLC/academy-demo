<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class EmploymentProfilesRelationManager extends RelationManager
{
    protected static string $relationship = 'employmentProfiles';

    protected static ?string $title = 'Employment Profiles';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Forms\Components\Toggle::make('has_work_experience')
                    ->label('Has work experience'),
                Forms\Components\Select::make('preferred_hours')
                    ->label('Preferred hours')
                    ->options([
                        'full_time' => 'Full time',
                        'part_time' => 'Part time',
                        'either' => 'Either',
                    ])
                    ->required(),
                Forms\Components\Textarea::make('notes')
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Select::make('employmentInterests')
                    ->label('Interests')
                    ->relationship('employmentInterests', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->columnSpanFull(),
                Forms\Components\Select::make('employmentAvailabilityOptions')
                    ->label('Availability')
                    ->relationship('employmentAvailabilityOptions', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('preferred_hours')
                    ->label('Preferred hours')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'full_time' => 'Full time',
                        'part_time' => 'Part time',
                        'either' => 'Either',
                        default => $state,
                    }),
                Tables\Columns\IconColumn::make('has_work_experience')
                    ->label('Work experience')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->after(fn ($record) => $this->deactivateOtherProfiles($record)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->after(fn ($record) => $this->deactivateOtherProfiles($record)),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected function deactivateOtherProfiles($record): void
    {
        if (! $record || ! $record->is_active) {
            return;
        }

        $this->getOwnerRecord()?->employmentProfiles()
            ->whereKeyNot($record->getKey())
            ->update(['is_active' => false]);
    }
}
