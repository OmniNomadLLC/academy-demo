<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Resources\FranceCalendarResource\Pages;
use App\Filament\Resources\FranceCalendarResource\RelationManagers;
use App\Models\ClassSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FranceCalendarResource extends Resource
{
    use RequiresRegionAccess;

    protected static string $requiredRegion = 'France';
    protected static ?string $model = ClassSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'France';
    protected static ?string $navigationLabel = 'Calendar';
    protected static ?int $navigationSort = 2;
    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return 'France';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFranceCalendars::route('/'),
            'create' => Pages\CreateFranceCalendar::route('/create'),
            'edit' => Pages\EditFranceCalendar::route('/{record}/edit'),
        ];
    }
}
