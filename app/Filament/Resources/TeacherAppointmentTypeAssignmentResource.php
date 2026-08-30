<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeacherAppointmentTypeAssignmentResource\Pages;
use App\Models\TeacherAppointmentTypeAssignment;
use App\Models\TeacherAppointmentTypeCatalog;
use App\Models\User;
use App\Support\TeacherAppointmentTypeAllocator;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Tables\Grouping\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherAppointmentTypeAssignmentResource extends Resource
{
    protected static ?string $model = TeacherAppointmentTypeCatalog::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Teacher Appointment Types';

    protected static ?string $pluralLabel = 'Teacher Appointment Types';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 62;

    public static function shouldRegisterNavigation(): bool
    {
        return static::visibleToSuperAdmins();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_calendar_label')
                    ->label('Calendar')
                    ->state(function (Model $record) {
                        $displayId = $record->display_calendar_id ?? $record->acuity_calendar_id;
                        $label = $record->display_calendar_label ?? null;

                        if ($label) {
                            return $label;
                        }

                        return static::calendarLabel($displayId);
                    })
                    ->badge()
                    ->color('gray')
                    ->tooltip(function (Model $record) {
                        return $record->display_calendar_id
                            ?? $record->acuity_calendar_id
                            ?? null;
                    })
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('display_appointment_type_name')
                    ->label('Appointment Type')
                    ->state(fn (Model $record) => $record->display_appointment_type_name ?? $record->appointment_type_name)
                    ->wrap()
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('display_appointment_type_id')
                    ->label('Type ID')
                    ->state(fn (Model $record) => $record->display_appointment_type_id ?? $record->acuity_appointment_type_id)
                    ->copyable()
                    ->copyMessage('Type ID copied')
                    ->copyMessageDuration(1500),
                Tables\Columns\TextColumn::make('display_teacher_name')
                    ->label('Teacher')
                    ->state(fn (Model $record) => $record->display_teacher_name ?? '—'),
                Tables\Columns\TextColumn::make('display_teacher_email')
                    ->label('Email')
                    ->state(fn (Model $record) => $record->display_teacher_email ?? '—')
                    ->toggleable()
                    ->toggledHiddenByDefault(),
            ])
            ->filters([
                SelectFilter::make('calendar')
                    ->label('Calendar')
                    ->options(static::relevantCalendars())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! $value) {
                            return $query;
                        }

                        return $query->where('teacher_appointment_type_catalog.acuity_calendar_id', $value);
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('syncAssignments')
                    ->label('Relink teacher assignments')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Run teacher assignment sync')
                    ->modalDescription('Executes the teacher-assignments:sync command so portals and reports pick up the latest calendar/type changes.')
                    ->visible(fn () => static::visibleToSuperAdmins())
                    ->action(function () {
                        Artisan::call('teacher-assignments:sync');

                        Notification::make()
                            ->title('Teacher assignments re-synced')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('clearAssignments')
                    ->label('Clear all assignments')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove all teacher appointment type assignments')
                    ->modalDescription('This will delete every UK presential appointment assignment so all appointment types become available again. Teachers will be detached from sessions tied to those types.')
                    ->visible(fn () => static::visibleToSuperAdmins())
                    ->action(function () {
                        $teacherIds = TeacherAppointmentTypeAssignment::query()
                            ->distinct()
                            ->pluck('user_id')
                            ->filter()
                            ->map(fn ($id) => (int) $id)
                            ->unique()
                            ->values();

                        TeacherAppointmentTypeAssignment::query()->delete();

                        foreach ($teacherIds as $teacherId) {
                            $teacher = \App\Models\User::find($teacherId);

                            if ($teacher) {
                                $teacher->unsetRelation('teacherAppointmentTypeAssignments');
                                $teacher->setRelation('teacherAppointmentTypeAssignments', collect());
                                TeacherAppointmentTypeAllocator::sync($teacher);
                            }
                        }

                        static::flushCalendarCaches();

                        Notification::make()
                            ->title('Teacher appointment types cleared')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('unassign')
                    ->label('Unassign teacher')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Model $record) => filled($record->assignment_id))
                    ->action(function (Model $record) {
                        if (! static::visibleToSuperAdmins()) {
                            return;
                        }

                        $calendarId = (string) ($record->display_calendar_id ?? $record->acuity_calendar_id ?? '');
                        $typeId = (string) ($record->display_appointment_type_id ?? $record->acuity_appointment_type_id ?? '');

                        if ($calendarId === '' || $typeId === '') {
                            return;
                        }

                        $assignment = TeacherAppointmentTypeAssignment::query()
                            ->where('acuity_calendar_id', $calendarId)
                            ->where('acuity_appointment_type_id', $typeId)
                            ->first();

                        if (! $assignment) {
                            return;
                        }

                        $teacher = $assignment->teacher;
                        $assignment->delete();

                        if ($teacher) {
                            $teacher->unsetRelation('teacherAppointmentTypeAssignments');
                            $teacher->setRelation('teacherAppointmentTypeAssignments', $teacher->teacherAppointmentTypeAssignments()->get());
                            TeacherAppointmentTypeAllocator::sync($teacher);
                        }

                        static::flushCalendarCaches($calendarId);
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No appointments mapped yet')
            ->emptyStateDescription('Assignments for Riverside and Northgate calendars will appear after teachers are linked in System → Users.')
            ->paginationPageOptions([10, 20, 50, 'all'])
            ->defaultPaginationPageOption(20)
            ->groups([
                Group::make('display_calendar_label')
                    ->label('Calendar')
                    ->titlePrefixedWithLabel(false)
                    ->orderQueryUsing(function (Builder $query, string $direction): Builder {
                        return $query->orderBy('teacher_appointment_type_catalog.calendar_label', $direction);
                    }),
            ])
            ->defaultGroup('display_calendar_label');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeacherAppointmentTypeAssignments::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::visibleToSuperAdmins();
    }

    public static function canDelete(Model $record): bool
    {
        return static::visibleToSuperAdmins();
    }

    public static function getEloquentQuery(): Builder
    {
        if (! static::visibleToSuperAdmins()) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery();
    }

    protected static function relevantCalendars(): array
    {
        return Cache::remember('teacher_assignment_relevant_calendars', 600, function (): array {
            $priority = ['northgate' => 0, 'riverside' => 1, 'parkside' => 2, 'southbank' => 3];

            return TeacherAppointmentTypeCatalog::query()
                ->select('acuity_calendar_id', 'calendar_label', 'calendar_norm')
                ->get()
                ->unique('acuity_calendar_id')
                ->sortBy(function ($row) use ($priority) {
                    $norm = strtolower(trim((string) ($row->calendar_norm ?? '')));
                    $label = trim((string) ($row->calendar_label ?? ''));
                    $weight = $priority[$norm] ?? $priority[strtolower($label)] ?? 100;

                    return sprintf('%03d_%s', $weight, strtolower($label));
                })
                ->mapWithKeys(function ($row) {
                    $calendarId = trim((string) $row->acuity_calendar_id);
                    $label = trim((string) $row->calendar_label);

                    return $calendarId !== '' && $label !== ''
                        ? [$calendarId => $label]
                        : [];
                })
                ->toArray();
        });
    }

    protected static function calendarLabel(?string $calendarId): string
    {
        $calendarId = $calendarId ? trim($calendarId) : '';

        if ($calendarId === '') {
            return 'Unknown calendar';
        }

        $map = static::relevantCalendars();

        return $map[$calendarId] ?? ('Calendar '.$calendarId);
    }

    public static function flushCalendarCaches(?string $calendarId = null): void
    {
        Cache::forget('teacher_assignment_relevant_calendars');

        if ($calendarId) {
            Cache::forget("user_resource_calendar_appt_types_{$calendarId}");
            Cache::forget("user_resource_calendar_presential_{$calendarId}");
        }

        Cache::forget('user_resource_calendar_options');
    }

    protected static function visibleToSuperAdmins(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->hasRole('super_admin', 'super-admin') ?? false);
    }
    public static function tableQueryWithUniverse(): Builder
    {
        $priority = ['northgate', 'riverside', 'parkside', 'southbank'];
        $caseExpressionParts = [];
        foreach ($priority as $index => $slug) {
            $slug = addslashes(strtolower(trim($slug)));
            $caseExpressionParts[] = "WHEN LOWER(teacher_appointment_type_catalog.calendar_norm) = '{$slug}' THEN {$index}";
        }

        $priorityCase = 'CASE ' . implode(' ', $caseExpressionParts) . ' ELSE 100 END';

        $priority = ['northgate' => 0, 'riverside' => 1, 'parkside' => 2, 'southbank' => 3];
        $caseExpression = 'CASE';
        foreach ($priority as $slug => $weight) {
            $caseExpression .= " WHEN LOWER(teacher_appointment_type_catalog.calendar_norm) = '" . addslashes($slug) . "' THEN {$weight}";
        }
        $caseExpression .= ' ELSE 100 END';

        $calendarTeacherSub = DB::table('users')
            ->select([
                'acuity_calendar_id',
                DB::raw('MAX(name) as fallback_name'),
                DB::raw('MAX(email) as fallback_email'),
            ])
            ->whereIn('role', User::TEACHING_ROLES)
            ->where('is_active', true)
            ->whereNotNull('acuity_calendar_id')
            ->groupBy('acuity_calendar_id');

        return TeacherAppointmentTypeCatalog::query()
            ->select([
                'teacher_appointment_type_catalog.*',
                DB::raw('teacher_appointment_type_assignments.id as assignment_id'),
                DB::raw('teacher_appointment_type_catalog.acuity_calendar_id as display_calendar_id'),
                DB::raw('teacher_appointment_type_catalog.calendar_label as display_calendar_label'),
                DB::raw('teacher_appointment_type_catalog.acuity_appointment_type_id as display_appointment_type_id'),
                DB::raw('teacher_appointment_type_catalog.appointment_type_name as display_appointment_type_name'),
                DB::raw('COALESCE(users.name, calendar_teachers.fallback_name) as display_teacher_name'),
                DB::raw('COALESCE(users.email, calendar_teachers.fallback_email) as display_teacher_email'),
            ])
            ->leftJoin('teacher_appointment_type_assignments', function ($join) {
                $join->on('teacher_appointment_type_assignments.acuity_calendar_id', '=', 'teacher_appointment_type_catalog.acuity_calendar_id')
                    ->on('teacher_appointment_type_assignments.acuity_appointment_type_id', '=', 'teacher_appointment_type_catalog.acuity_appointment_type_id');
            })
            ->leftJoin('users', 'teacher_appointment_type_assignments.user_id', '=', 'users.id')
            ->leftJoinSub($calendarTeacherSub, 'calendar_teachers', function ($join) {
                $join->on('calendar_teachers.acuity_calendar_id', '=', 'teacher_appointment_type_catalog.acuity_calendar_id');
            })
            ->orderByRaw($caseExpression)
            ->orderBy('teacher_appointment_type_catalog.calendar_label')
            ->orderBy('teacher_appointment_type_catalog.appointment_type_name');
    }
}
