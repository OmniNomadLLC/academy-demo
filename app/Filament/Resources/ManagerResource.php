<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Resources\ManagerResource\Pages;
use App\Models\Manager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManagerResource extends Resource
{
    use RequiresRegionAccess;

    protected static string $requiredRegion = 'UK';
    protected static ?string $model = Manager::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';
    protected static ?string $navigationLabel = 'Managers';
    protected static ?string $navigationGroup = 'UK';
    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return 'UK';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\TextInput::make('email')->required()->email()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(255)->nullable(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('email')->searchable(),
            Tables\Columns\TextColumn::make('phone'),
            Tables\Columns\TextColumn::make('students_count')->counts('students')->label('# Students')->badge()->sortable(),
        ])->filters([
            \Filament\Tables\Filters\TernaryFilter::make('has_students')
                ->label('Has Students')
                ->placeholder('Any')
                ->trueLabel('Yes')
                ->falseLabel('No')
                ->queries(
                    true: fn(Builder $query) => $query->has('students'),
                    false: fn(Builder $query) => $query->doesntHave('students'),
                    blank: fn(Builder $query) => $query
                ),
            \Filament\Tables\Filters\SelectFilter::make('student_range')
                ->label('# Students')
                ->options([
                    '0-5' => '0–5',
                    '6-10' => '6–10',
                    '11-20' => '11–20',
                    '21+' => '21+',
                ])
                ->query(function (Builder $query, array $data): Builder {
                    $val = $data['value'] ?? null;
                    if (!$val) return $query;
                    $query->withCount('students');
                    return match ($val) {
                        '0-5' => $query->having('students_count', '>=', 0)->having('students_count', '<=', 5),
                        '6-10' => $query->havingBetween('students_count', [6,10]),
                        '11-20' => $query->havingBetween('students_count', [11,20]),
                        '21+' => $query->having('students_count', '>=', 21),
                        default => $query,
                    };
                }),
        ])
          ->actions([
              Tables\Actions\EditAction::make(),
          ])
          ->bulkActions([
              Tables\Actions\DeleteBulkAction::make(),
          ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListManagers::route('/'),
            'create' => Pages\CreateManager::route('/create'),
            'edit' => Pages\EditManager::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\ManagerResource\RelationManagers\StudentsRelationManager::class,
        ];
    }
}
