<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Resources\ClassLocationResource\Pages;
use App\Models\ClassLocation;
use App\Models\ClassSession;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ClassLocationResource extends Resource
{
    use RequiresRegionAccess;

    protected static string $requiredRegion = 'UK';
    protected static ?string $model = ClassLocation::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationLabel = 'Class Locations';
    protected static ?string $navigationGroup = 'UK';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Location details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Display name shown on rosters and reports.'),
                        Forms\Components\Select::make('region')
                            ->options([
                                'UK' => 'United Kingdom',
                                'Spain' => 'Spain',
                                'France' => 'France',
                                'Academic' => 'Internal',
                            ])
                            ->default('UK')
                            ->required()
                            ->helperText('Region filter used for dashboards.')
                            ->columnSpan(1),
                        Forms\Components\Toggle::make('is_virtual')
                            ->label('Virtual delivery')
                            ->helperText('Enable for online calendars (Zoom / Teams).')
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('virtual_meeting_url')
                            ->label('Virtual meeting URL')
                            ->url()
                            ->maxLength(1024),
                        Forms\Components\TextInput::make('virtual_meeting_room')
                            ->label('Meeting room / code')
                            ->maxLength(255),
                        Forms\Components\Select::make('primary_calendar')
                            ->label('Acuity calendar')
                            ->required()
                            ->helperText('Select the Acuity calendar that should map to this location.')
                            ->searchable()
                            ->reactive()
                            ->options(static::ukCalendarOptions())
                            ->columnSpanFull()
                            ->afterStateHydrated(function (callable $set, ?ClassLocation $record, $state) {
                                if ($state) {
                                    return;
                                }
                                if (! $record) {
                                    return;
                                }
                                $calendar = $record->calendars()->orderBy('id')->first();
                                if ($calendar) {
                                    $set('primary_calendar', $calendar->calendar_name);
                                }
                            }),
                    ]),

                Forms\Components\Section::make('Physical address')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('address_line_1')
                            ->label('Address line 1')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('address_line_2')
                            ->label('Address line 2')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('city')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('postcode')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('country')
                            ->maxLength(255)
                            ->default('UK'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('calendars'))
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('slug')->label('Key')->copyable(),
                TextColumn::make('region')->badge()->label('Region'),
                Tables\Columns\IconColumn::make('is_virtual')->label('Virtual')->boolean(),
                TextColumn::make('calendars_count')->label('Calendars')->badge()->color('gray'),
                TextColumn::make('updated_at')->since()->label('Updated'),
            ])
            ->actions([
                Tables\Actions\Action::make('sync_sessions')
                    ->label('Apply to sessions')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (ClassLocation $record) {
                        $updated = $record->syncFutureSessions();
                        Notification::make()
                            ->title('Location applied')
                            ->body($updated > 0
                                ? sprintf('%d upcoming session%s updated.', $updated, $updated === 1 ? '' : 's')
                                : 'No upcoming sessions matched the assigned calendars.')
                            ->success()
                            ->send();
                    }),
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClassLocations::route('/'),
            'create' => Pages\CreateClassLocation::route('/create'),
            'edit' => Pages\EditClassLocation::route('/{record}/edit'),
        ];
    }

    protected static function ensureSlug(array $data): array
    {
        $calendarName = $data['primary_calendar'] ?? null;

        $slug = $calendarName ? Str::slug($calendarName) : Str::slug($data['name'] ?? Str::random(8));

        $data['slug'] = $slug;

        return $data;
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        return static::ensureSlug($data);
    }

    public static function mutateFormDataBeforeSave(array $data): array
    {
        return static::ensureSlug($data);
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        if (! $user || ! $user->hasRole('admin', 'super_admin')) {
            return false;
        }

        return method_exists($user, 'hasRegionAccess') ? $user->hasRegionAccess('UK') : true;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function syncCalendarsForRecord(ClassLocation $record, ?string $calendarName): void
    {
        $record->calendars()->delete();

        if (! $calendarName || trim($calendarName) === '') {
            return;
        }

        $slug = Str::slug($calendarName);
        $norm = Str::slug($calendarName);

        $record->calendars()->create([
            'calendar_name' => $calendarName,
            'calendar_slug' => $slug,
            'calendar_norm' => $norm,
            'region' => $record->region ?? 'UK',
        ]);
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    protected static function ukCalendarOptions(bool $slugged = false): array
    {
        $names = ClassSession::query()
            ->select('calendar_name')
            ->whereNotNull('calendar_name')
            ->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(location, "")) = ?', ['uk'])
                  ->orWhere('category_norm', 'like', '%uk%');
            })
            ->distinct()
            ->orderBy('calendar_name')
            ->pluck('calendar_name')
            ->filter()
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($slugged) {
            return $names
                ->mapWithKeys(fn ($label) => [Str::slug($label) => Str::slug($label)])
                ->filter(fn ($value, $key) => $key !== '')
                ->all();
        }

        return $names
            ->mapWithKeys(fn ($label) => [$label => $label])
            ->all();
    }
}
