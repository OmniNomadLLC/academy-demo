<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Resources\ArchivedUKStudentResource\Pages;
use App\Models\Student;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn\TextColumnSize;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ArchivedUKStudentResource extends Resource
{
    use RequiresRegionAccess;

    protected static string $requiredRegion = 'UK';
    protected static ?string $model = Student::class;
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';
    protected static ?string $navigationGroup = 'UK';
    protected static ?string $navigationLabel = 'Archived Students';
    protected static ?int $navigationSort = 9;
    // allowsUkManagerAccess intentionally omitted — RequiresRegionAccess
    // defaults to denying UK managers when the property is absent.

    public static function getNavigationGroup(): ?string
    {
        return 'UK';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->archivedOnly()
            ->where(function (Builder $q) {
                $q->where('in_uk', true)
                  ->orWhereRaw('LOWER(location) = ?', ['uk']);
            })
            ->orderByDesc('archived_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->getStateUsing(fn ($record) => trim(($record->first_name ?? '').' '.($record->last_name ?? '')))
                    ->size(TextColumnSize::ExtraSmall)
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.$search.'%';
                        return $query->where(function (Builder $q) use ($like) {
                            $q->where('first_name', 'like', $like)
                              ->orWhere('last_name', 'like', $like);
                        });
                    }),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->size(TextColumnSize::ExtraSmall)
                    ->wrap()
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('acuity_category')
                    ->label('Category')
                    ->getStateUsing(fn ($record) => $record->acuity_category ?? $record->location)
                    ->badge()
                    ->wrap()
                    ->size(TextColumnSize::ExtraSmall)
                    ->sortable(),
                Tables\Columns\TextColumn::make('archived_at')
                    ->label('Archived')
                    ->date('d M Y')
                    ->size(TextColumnSize::ExtraSmall)
                    ->sortable(),
                Tables\Columns\TextColumn::make('archived_reason')
                    ->label('Reason')
                    ->badge()
                    ->size(TextColumnSize::ExtraSmall)
                    ->color(fn (?string $state) => match ($state) {
                        'manual' => 'warning',
                        'inactivity' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('nextProfile.id')
                    ->label('Respawned')
                    ->formatStateUsing(fn ($state) => $state ? '→ #'.$state : '')
                    ->badge()
                    ->color('info')
                    ->size(TextColumnSize::ExtraSmall),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('archived_reason')
                    ->label('Reason')
                    ->options([
                        'inactivity' => 'Inactivity',
                        'manual' => 'Manual',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('')
                    ->tooltip('View')
                    ->icon('heroicon-o-eye'),
            ])
            ->recordClasses('text-xs')
            ->defaultSort('archived_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return \App\Filament\Resources\StudentResource::infolist($infolist);
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        if (! $user || ! $user->hasRole('admin', 'super_admin')) {
            return false;
        }

        return parent::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete($record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArchivedUKStudents::route('/'),
            'view'  => Pages\ViewArchivedUKStudent::route('/{record}'),
        ];
    }
}
