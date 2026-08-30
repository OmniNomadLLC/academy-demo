<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Resources\SpainReportResource\Pages;
use App\Filament\Resources\SpainReportResource\RelationManagers;
use App\Models\SpainReport;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SpainReportResource extends Resource
{
    use RequiresRegionAccess;

    protected static string $requiredRegion = 'Spain';
    protected static ?string $model = SpainReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Spain';
    protected static ?string $navigationLabel = 'Reports';
    protected static ?int $navigationSort = 3;
    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return 'Spain';
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
            'index' => Pages\ListSpainReports::route('/'),
            'create' => Pages\CreateSpainReport::route('/create'),
            'edit' => Pages\EditSpainReport::route('/{record}/edit'),
        ];
    }
}
