<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AssessmentTemplateResource\Pages;
use App\Filament\Resources\AssessmentTemplateResource\RelationManagers\QuestionsRelationManager;
use App\Models\AssessmentTemplate;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

class AssessmentTemplateResource extends Resource
{
    protected static ?string $model = AssessmentTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Assessment Templates';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = null;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    protected static function canManage(): bool
    {
        $user = auth()->user();

        return $user?->canManageAssessmentTemplates() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::canManage();
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit(EloquentModel $record): bool
    {
        return static::canManage();
    }

    public static function canDelete(EloquentModel $record): bool
    {
        return static::canManage();
    }

    public static function canDeleteAny(): bool
    {
        return static::canManage();
    }

    public static function getNavigationItems(): array
    {
        $base = static::getRouteBaseName();

        if (! Route::has($base.'.index') || ! static::canManage()) {
            return [];
        }

        return parent::getNavigationItems();
    }

    public static function getNavigationLabel(): string
    {
        if (Filament::getCurrentPanel()?->getId() === 'portal') {
            return 'Assessment';
        }

        return parent::getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            return 'UK';
        }

        return null;
    }

    public static function getNavigationSort(): ?int
    {
        return Filament::getCurrentPanel()?->getId() === 'portal'
            ? 2
            : 0;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->rows(3),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Forms\Components\Hidden::make('region')
                    ->default('uk'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Updated'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->icon('heroicon-m-trash')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin', 'admin', 'head_teacher') ?? false)
                    ->action(function (AssessmentTemplate $record) {
                        if ($record->assessments()->exists()) {
                            Notification::make()
                                ->title('Template in use')
                                ->body('Existing assessments are linked to this template. Remove those assessments before deleting it.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->questions()->delete();
                        $record->delete();

                        Notification::make()
                            ->title('Template deleted')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            QuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssessmentTemplates::route('/'),
            'create' => Pages\CreateAssessmentTemplate::route('/create'),
            'edit' => Pages\EditAssessmentTemplate::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('region', 'uk');
    }
}
