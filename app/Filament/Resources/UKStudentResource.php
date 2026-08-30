<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Resources\UKStudentResource\Pages;
use App\Models\ClassSession;
use App\Models\Student;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn\TextColumnSize;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UKStudentResource extends Resource
{
    use RequiresRegionAccess;

    protected static string $requiredRegion = 'UK';
    protected static ?string $model = Student::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'UK';
    protected static ?string $navigationLabel = 'Students';
    protected static ?int $navigationSort = 8;
    protected static bool $allowsUkManagerAccess = true;

    public static function getNavigationGroup(): ?string
    {
        return 'UK';
    }

    // Filter to only show UK students (fast path: by student location or their stored acuity_category)
    public static function getEloquentQuery(): Builder
    {
        $region = 'UK';
        return parent::getEloquentQuery()
            ->excludingArchived()
            ->where(function (Builder $q) use ($region) {
                $q->where('in_uk', true)
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
                Forms\Components\Hidden::make('location')->default('UK'),
                Forms\Components\DatePicker::make('registration_date'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Enrolled')
                    ->helperText('Student record is enrolled / not archived. Activity status (has upcoming lessons) is computed automatically from class_sessions.')
                    ->default(true),
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
                        if (isset($record->in_uk) && $record->in_uk) {
                            $tags[] = 'UK';
                        }
                        if (isset($record->in_spain) && $record->in_spain) {
                            $tags[] = 'Spain';
                        }
                        if (isset($record->in_france) && $record->in_france) {
                            $tags[] = 'France';
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
                    ->size(TextColumnSize::ExtraSmall)
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->label('Last name')
                    ->size(TextColumnSize::ExtraSmall)
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->size(TextColumnSize::ExtraSmall)
                    ->wrap()
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('active_status')
                    ->label('Active')
                    ->getStateUsing(fn ($record) => $record->is_active_recent ? 'Active' : 'Non active')
                    ->badge()
                    ->wrap()
                    ->size(TextColumnSize::ExtraSmall)
                    ->color(fn($state) => (is_string($state) && strtolower($state) === 'active') ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('attendance_rate')
                    ->label('Attendance')
                    ->formatStateUsing(fn($record) => number_format((float)($record->attendance_rate ?? 0), 2).'%' )
                    ->badge()
                    ->wrap()
                    ->size(TextColumnSize::ExtraSmall)
                    ->color(fn($record) => ($record->attendance_rate ?? 0) < 75 ? 'danger' : 'success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('low_attendance_badge')
                    ->label('Low')
                    ->getStateUsing(fn($record) => (($record->attendance_rate ?? 100) < 75) ? 'Low' : '')
                    ->badge()
                    ->wrap()
                    ->size(TextColumnSize::ExtraSmall)
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn () => ! static::userIsUkManager()),
                Tables\Actions\Action::make('archive')
                    ->label('')
                    ->tooltip('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Archive this student?')
                    ->modalDescription(fn ($record) => trim(($record->first_name ?? '').' '.($record->last_name ?? ''))
                        .' will be removed from the active students list. Their attendance history is preserved. '
                        .'This action can be reversed only by a developer (no admin UI for unarchive in MVP).')
                    ->action(function ($record) {
                        $record->update([
                            'archived_at' => now(),
                            'archived_reason' => 'manual',
                        ]);

                        Log::warning(sprintf(
                            'Student #%d (%s) archived manually with reason=manual by user #%d (%s)',
                            $record->id,
                            $record->email,
                            auth()->id(),
                            auth()->user()?->email ?? 'unknown'
                        ));

                        Notification::make()
                            ->title('Student archived')
                            ->body(trim(($record->first_name ?? '').' '.($record->last_name ?? ''))
                                .' is now in Archived Students.')
                            ->success()
                            ->send();
                    })
                    ->visible(fn () => auth()->user()?->hasRole('admin', 'super_admin') ?? false),
                Tables\Actions\RestoreAction::make()
                    ->label('')
                    ->tooltip('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn ($record) => ! static::userIsUkManager() && method_exists($record, 'trashed') && $record->trashed()),
                Tables\Actions\DeleteAction::make()
                    ->label('')
                    ->tooltip('Delete')
                    ->icon('heroicon-o-trash')
                    ->visible(fn () => ! static::userIsUkManager()),
            ])
            ->filters([

                Tables\Filters\TernaryFilter::make('low_attendance')
                    ->label('Low Attendance (<75%)')
                    ->placeholder('Any')
                    ->trueLabel('Yes')
                    ->falseLabel('No')
                    ->queries(
                        true: fn(\Illuminate\Database\Eloquent\Builder $q) => $q->whereNotNull('attendance_rate')->where('attendance_rate', '<', 75),
                        false: fn(\Illuminate\Database\Eloquent\Builder $q) => $q->where(function($qq){ $qq->whereNull('attendance_rate')->orWhere('attendance_rate', '>=', 75); }),
                        blank: fn(\Illuminate\Database\Eloquent\Builder $q) => $q
                    ),
                Tables\Filters\TernaryFilter::make('critical_attendance')
                    ->label('Critical Attendance (<60%)')
                    ->placeholder('Any')
                    ->trueLabel('Under 60%')
                    ->falseLabel('60%+')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('attendance_rate')->where('attendance_rate', '<', 60),
                        false: fn (Builder $q) => $q->where(function (Builder $qq) {
                            $qq->whereNull('attendance_rate')->orWhere('attendance_rate', '>=', 60);
                        }),
                        blank: fn (Builder $q) => $q,
                    ),
                Tables\Filters\TernaryFilter::make('no_upcoming')
                    ->label('Upcoming class assignment')
                    ->placeholder('Any')
                    ->trueLabel('Missing')
                    ->falseLabel('Scheduled')
                    ->queries(
                        true: fn (Builder $q) => $q->where(function (Builder $inner) {
                            $inner->whereNull('next_appointment_date')
                                ->orWhereDate('next_appointment_date', '<', now()->toDateString());
                        }),
                        false: fn (Builder $q) => $q->whereNotNull('next_appointment_date')
                            ->whereDate('next_appointment_date', '>=', now()->toDateString()),
                        blank: fn (Builder $q) => $q,
                    ),
                Tables\Filters\TernaryFilter::make('new_last7')
                    ->label('New students (7 days)')
                    ->placeholder('Any')
                    ->trueLabel('Only new')
                    ->falseLabel('Older')
                    ->queries(
                        true: fn (Builder $q) => $q->where('created_at', '>=', now()->copy()->subDays(6)->startOfDay()),
                        false: fn (Builder $q) => $q->where(function (Builder $inner) {
                            $inner->whereNull('created_at')
                                ->orWhere('created_at', '<', now()->copy()->subDays(6)->startOfDay());
                        }),
                        blank: fn (Builder $q) => $q,
                    ),
                Tables\Filters\TernaryFilter::make('inactive_45')
                    ->label('Inactive (45+ days)')
                    ->placeholder('Any')
                    ->trueLabel('Inactive')
                    ->falseLabel('Recent')
                    ->queries(
                        true: fn (Builder $q) => $q->where(function (Builder $inner) {
                            $inner->whereNull('last_appointment_date')
                                ->orWhereDate('last_appointment_date', '<', now()->subDays(45)->toDateString());
                        }),
                        false: fn (Builder $q) => $q->whereNotNull('last_appointment_date')
                            ->whereDate('last_appointment_date', '>=', now()->subDays(45)->toDateString()),
                        blank: fn (Builder $q) => $q,
                    ),
                Tables\Filters\Filter::make('high_risk')
                    ->label('High risk (intelligence)')
                    ->indicator('High risk')
                    ->toggle()
                    ->query(function (Builder $query): Builder {
                        $now = now();
                        $absenceCutoff = now()->copy()->subDays(6)->startOfDay();

                        return $query->where(function (Builder $inner) use ($now, $absenceCutoff) {
                            $inner->where(function (Builder $attendance) {
                                $attendance->whereNotNull('attendance_rate')
                                    ->where('attendance_rate', '<', 60);
                            })
                            ->orWhere(function (Builder $upcoming) use ($now) {
                                $upcoming->whereNull('next_appointment_date')
                                    ->orWhere('next_appointment_date', '<=', $now);
                            })
                            ->orWhereExists(function ($sub) use ($absenceCutoff) {
                                $sub->select(DB::raw('1'))
                                    ->from('attendance_records as ar')
                                    ->whereColumn('ar.student_id', 'students.id')
                                    ->where('ar.status', 'absent')
                                    ->where('ar.marked_at', '>=', $absenceCutoff);
                            });
                        });
                    }),
                Tables\Filters\SelectFilter::make('is_active')
                    ->label('Active')
                    ->options(['yes' => 'Yes', 'no' => 'No'])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        $val = $data['value'] ?? null;
                        if ($val === 'yes') {
                            return $query->where('is_active_recent', true);
                        }
                        if ($val === 'no') {
                            return $query->where(function (Builder $q) {
                                $q->where('is_active_recent', false)->orWhereNull('is_active_recent');
                            });
                        }
                        return $query;
                    }),
               Tables\Filters\SelectFilter::make('acuity_category')
                   ->label('Category')
                   ->placeholder('All categories')
                   ->options(function () {
                       return Student::query()
                           ->whereNotNull('acuity_category')
                           ->where(function (Builder $q) {
                               $q->where('in_uk', true)
                                 ->orWhereRaw('LOWER(location) = ?', ['uk']);
                           })
                           ->distinct()
                           ->orderBy('acuity_category')
                           ->pluck('acuity_category', 'acuity_category')
                           ->toArray();
                   })
                   ->query(function (Builder $query, array $data): Builder {
                       $value = $data['value'] ?? null;
                       return $value ? $query->where('acuity_category', $value) : $query;
                   }),
                Tables\Filters\SelectFilter::make('acuity_calendar')
                    ->label('Acuity calendar')
                    ->placeholder('All calendars')
                    ->options(function () {
                        return ClassSession::query()
                            ->join('students as s', 's.id', '=', 'class_sessions.student_id')
                            ->where(function (Builder $student) {
                                $student->where('s.in_uk', true)
                                    ->orWhereRaw('LOWER(s.location) = ?', ['uk']);
                            })
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

                                    $inner->where(function ($region) {
                                        $region->whereRaw('LOWER(TRIM(class_sessions.location)) = ?', ['uk'])
                                               ->orWhereRaw('LOWER(TRIM(class_sessions.category_norm)) = ?', ['uk']);
                                    });
                                });
                        });
                    }),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->defaultSort('last_appointment_date', 'desc');
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        if (! $user || ! $user->hasDomainAccess('students')) {
            return false;
        }

        return parent::canViewAny();
    }

    public static function canCreate(): bool
    {
        if (static::userIsUkManager()) {
            return false;
        }

        return parent::canCreate();
    }

    public static function canEdit($record): bool
    {
        if (static::userIsUkManager()) {
            return false;
        }

        return parent::canEdit($record);
    }

    public static function canDelete($record): bool
    {
        if (static::userIsUkManager()) {
            return false;
        }

        return parent::canDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        if (static::userIsUkManager()) {
            return false;
        }

        return parent::canDeleteAny();
    }

    public static function canForceDelete($record): bool
    {
        if (static::userIsUkManager()) {
            return false;
        }

        return parent::canForceDelete($record);
    }

    public static function canForceDeleteAny(): bool
    {
        if (static::userIsUkManager()) {
            return false;
        }

        return parent::canForceDeleteAny();
    }

    public static function canRestore($record): bool
    {
        if (static::userIsUkManager()) {
            return false;
        }

        return parent::canRestore($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUKStudents::route('/'),
            'create' => Pages\CreateUKStudent::route('/create'),
            'view' => Pages\ViewUKStudent::route('/{record}'),
            'edit' => Pages\EditUKStudent::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        // Attendance management is UK-only
        return [
            \App\Filament\Resources\StudentResource\RelationManagers\AttendanceRecordsRelationManager::class,
        ];
    }

    // Reuse the unified Student view infolist
    public static function infolist(Infolist $infolist): Infolist
    {
        return \App\Filament\Resources\StudentResource::infolist($infolist);
    }

    protected static function userIsUkManager(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isUkManager() ?? false);
    }
}
