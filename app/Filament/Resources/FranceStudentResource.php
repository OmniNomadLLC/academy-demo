<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Resources\FranceStudentResource\Pages;
use App\Models\ClassSession;
use App\Models\Student;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Infolists\Infolist;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn\TextColumnSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class FranceStudentResource extends Resource
{
    use RequiresRegionAccess;

    protected static string $requiredRegion = 'France';
    protected static ?string $model = Student::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'France';
    protected static ?string $navigationLabel = 'Students';
    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'France';
    }

    // Filter to only show France students (fast path: by student location or acuity_category keywords)
    public static function getEloquentQuery(): Builder
    {
        $region = 'France';
        return parent::getEloquentQuery()
            ->where(function (Builder $q) use ($region) {
                $q->where('in_france', true)
                  ->orWhereRaw('LOWER(location) = ?', [strtolower($region)]);
            })
            ->orderBy('last_appointment_date', 'desc')
            ->orderBy('last_name');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('first_name')->required(),
                Forms\Components\TextInput::make('last_name')->required(),
                Forms\Components\TextInput::make('email')->email(),
                Forms\Components\TextInput::make('phone'),
                Forms\Components\Hidden::make('location')->default('France'),
                Forms\Components\DatePicker::make('registration_date'),
                Forms\Components\Toggle::make('is_active')->default(true),
                Forms\Components\Textarea::make('notes'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                return $query
                    ->select('students.*')
                    ->orderByDesc('is_active_recent')
                    ->orderByDesc('last_appointment_date');
            })
            ->columns([
                Tables\Columns\TagsColumn::make('regions')
                    ->label('Regions')
                    ->getStateUsing(function ($record) {
                        $tags = [];
                        if (isset($record->in_france) && $record->in_france) {
                            $tags[] = 'France';
                        }
                        if (isset($record->in_spain) && $record->in_spain) {
                            $tags[] = 'Spain';
                        }
                        if (isset($record->in_uk) && $record->in_uk) {
                            $tags[] = 'UK';
                        }
                        if (empty($tags) && is_string($record->location) && trim($record->location) !== '') {
                            $tags[] = $record->location;
                        }

                        return $tags;
                    })
                    ->separator(', ')
                    ->limit(3),
                Tables\Columns\TextColumn::make('acuity_category')
                    ->label('Category')
                    ->getStateUsing(fn($record) => $record->acuity_category ?? $record->location)
                    ->badge()
                    ->wrap()
                    ->size(TextColumnSize::ExtraSmall)
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_name')
                    ->label('First name')
                    ->wrap()
                    ->size(TextColumnSize::ExtraSmall)
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->label('Last name')
                    ->wrap()
                    ->size(TextColumnSize::ExtraSmall)
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->wrap()
                    ->size(TextColumnSize::ExtraSmall)
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('active_status')
                    ->label('Active')
                    ->getStateUsing(function ($record) {
                        $last = $record->last_appointment_date; $next = $record->next_appointment_date;
                        $isActive = false;
                        if ($last) { $isActive = \Illuminate\Support\Carbon::parse($last)->gte(now()->subDays(14)); }
                        if (!$isActive && $next) { $isActive = \Illuminate\Support\Carbon::parse($next)->lte(now()->addDays(45)); }
                        return $isActive ? 'Active' : 'Non active';
                    })
                    ->badge()
                    ->wrap()
                    ->size(TextColumnSize::ExtraSmall)
                    ->color(function ($state) {
                        $s = is_string($state) ? strtolower($state) : '';
                        return $s === 'active' ? 'success' : 'danger';
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('is_active')
                    ->label('Active')
                    ->options(['yes' => 'Yes', 'no' => 'No'])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        $val = $data['value'] ?? null;
                        if ($val === 'yes') {
                            return $query->where(function (Builder $q) {
                                $q->whereDate('last_appointment_date', '>=', now()->subDays(30))
                                  ->orWhereDate('next_appointment_date', '<=', now()->addDays(30));
                            });
                        }
                        if ($val === 'no') {
                            return $query->where(function (Builder $q) {
                                $q->whereNull('last_appointment_date')
                                  ->orWhereDate('last_appointment_date', '<', now()->subDays(30));
                            })->where(function (Builder $q) {
                                $q->whereNull('next_appointment_date')
                                  ->orWhereDate('next_appointment_date', '>', now()->addDays(30));
                            });
                        }
                        return $query;
                    }),
                Tables\Filters\SelectFilter::make('acuity_category')
                    ->label('Category')
                    ->options(fn() => \App\Models\Student::query()->whereNotNull('acuity_category')->distinct()->orderBy('acuity_category')->pluck('acuity_category','acuity_category')->toArray())
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        $value = $data['value'] ?? null;
                        return $value ? $query->where('acuity_category', $value) : $query;
                    }),
                Tables\Filters\SelectFilter::make('acuity_calendar')
                    ->label('Acuity calendar')
                    ->placeholder('All calendars')
                    ->options(function () {
                        return ClassSession::query()
                            ->whereNotNull('calendar_name')
                            ->select('calendar_name', 'calendar_norm')
                            ->distinct()
                            ->orderBy('calendar_name')
                            ->get()
                            ->mapWithKeys(function ($session) {
                                $name = trim((string) $session->calendar_name);
                                $slug = $session->calendar_norm ?: Str::slug($name);

                                return [json_encode([$name, $slug]) => $name];
                            })
                            ->toArray();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $raw = $data['value'] ?? null;
                        if (! $raw) {
                            return $query;
                        }

                        $decoded = json_decode((string) $raw, true);
                        if (! is_array($decoded) || count($decoded) !== 2) {
                            return $query;
                        }

                        [$name, $slug] = $decoded;
                        $name = trim((string) $name);
                        $slug = trim((string) $slug);
                        $lower = Str::lower($name);

                        return $query->whereExists(function ($sub) use ($lower, $slug) {
                            $sub->selectRaw('1')
                                ->from('class_sessions')
                                ->whereColumn('class_sessions.student_id', 'students.id')
                                ->where(function ($inner) use ($lower, $slug) {
                                    if ($lower !== '') {
                                        $inner->whereRaw('LOWER(TRIM(class_sessions.calendar_name)) = ?', [$lower]);
                                    }

                                    if ($slug !== '') {
                                        $inner->orWhere('class_sessions.calendar_norm', $slug);
                                    }
                                });
                        });
                    }),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->recordClasses('text-xs')
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('')
                    ->tooltip('View')
                    ->icon('heroicon-o-eye'),
                Tables\Actions\EditAction::make()
                    ->label('')
                    ->tooltip('Edit')
                    ->icon('heroicon-o-pencil-square'),
                Tables\Actions\RestoreAction::make()
                    ->label('')
                    ->tooltip('Restore')
                    ->icon('heroicon-o-arrow-uturn-left'),
                Tables\Actions\DeleteAction::make()
                    ->label('')
                    ->tooltip('Delete')
                    ->icon('heroicon-o-trash'),
            ])
            ->defaultSort('last_appointment_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFranceStudents::route('/'),
            'create' => Pages\CreateFranceStudent::route('/create'),
            'view' => Pages\ViewFranceStudent::route('/{record}'),
            'edit' => Pages\EditFranceStudent::route('/{record}/edit'),
        ];
    }

    // Reuse the unified Student view infolist
    public static function infolist(Infolist $infolist): Infolist
    {
        return \App\Filament\Resources\StudentResource::infolist($infolist);
    }
}
