<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TutorialResource\Pages;
use App\Models\Tutorial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class TutorialResource extends Resource
{
    protected static ?string $model = Tutorial::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Manage Tutorials';

    protected static ?string $navigationGroup = 'Help';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    protected static function canManage(): bool
    {
        $user = Auth::user();
        return $user?->hasRole('super_admin', 'admin') ?? false;
    }

    public static function canViewAny(): bool  { return self::canManage(); }
    public static function canCreate(): bool   { return self::canManage(); }
    public static function canEdit($record): bool   { return self::canManage(); }
    public static function canDelete($record): bool { return self::canManage(); }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canManage();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->required()
                ->maxLength(200)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('description')
                ->rows(3)
                ->maxLength(500)
                ->columnSpanFull(),

            Forms\Components\TextInput::make('category')
                ->maxLength(100)
                ->datalist(fn () => Tutorial::query()
                    ->whereNotNull('category')
                    ->where('category', '!=', '')
                    ->distinct()
                    ->orderBy('category')
                    ->pluck('category')
                    ->all())
                ->helperText('Free text; existing values are suggested.'),

            Forms\Components\Select::make('content_type')
                ->options([
                    'pdf'     => 'PDF Document',
                    'article' => 'Article (coming soon)',
                ])
                ->disableOptionWhen(fn (string $value): bool => $value === 'article')
                ->default('pdf')
                ->required()
                ->reactive(),

            Forms\Components\FileUpload::make('file_path')
                ->label('PDF File')
                ->acceptedFileTypes(['application/pdf'])
                ->disk('public')
                ->directory('tutorials')
                ->visibility('public')
                ->maxSize(10 * 1024)
                ->preserveFilenames()
                ->required(fn (Forms\Get $get) => $get('content_type') === 'pdf')
                ->visible(fn (Forms\Get $get) => $get('content_type') === 'pdf')
                ->helperText('Upload a PDF. Max 10MB.')
                ->columnSpanFull(),

            Forms\Components\CheckboxList::make('visible_to_roles')
                ->options([
                    'super_admin'  => 'Super Admin',
                    'admin'        => 'Admin',
                    'uk_manager'   => 'UK Manager',
                    'head_teacher' => 'Head Teacher',
                    'teacher'      => 'Teacher',
                    'student'      => 'Student',
                ])
                ->columns(2)
                ->required()
                ->helperText('Choose which roles can see this tutorial.')
                ->columnSpanFull(),

            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0)
                ->helperText('Lower numbers appear first within their category.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->lineClamp(2),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('content_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'pdf' ? 'purple' : 'gray'),
                Tables\Columns\TextColumn::make('visible_to_roles')
                    ->label('Visible to')
                    ->badge()
                    ->separator(',')
                    ->color('info')
                    ->formatStateUsing(fn ($state) => is_array($state) ? $state : []),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options(fn () => Tutorial::query()
                        ->whereNotNull('category')
                        ->where('category', '!=', '')
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),
                Tables\Filters\SelectFilter::make('content_type')
                    ->options(['pdf' => 'PDF', 'article' => 'Article']),
                Tables\Filters\Filter::make('visible_to_roles')
                    ->form([
                        Forms\Components\CheckboxList::make('roles')
                            ->options([
                                'super_admin'  => 'Super Admin',
                                'admin'        => 'Admin',
                                'uk_manager'   => 'UK Manager',
                                'head_teacher' => 'Head Teacher',
                                'teacher'      => 'Teacher',
                                'student'      => 'Student',
                            ])
                            ->columns(2),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $roles = $data['roles'] ?? [];
                        foreach ($roles as $role) {
                            $query->whereJsonContains('visible_to_roles', $role);
                        }
                        return $query;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open PDF')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Tutorial $record) => $record->file_url)
                    ->openUrlInNewTab()
                    ->visible(fn (Tutorial $record) => $record->content_type === 'pdf' && ! empty($record->file_url)),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTutorials::route('/'),
            'create' => Pages\CreateTutorial::route('/create'),
            'edit'   => Pages\EditTutorial::route('/{record}/edit'),
        ];
    }
}
