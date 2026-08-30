<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\ClassSession;
use App\Models\TeacherAppointmentTypeAssignment;
use App\Models\TeacherAppointmentTypeCatalog;
use App\Models\User;
use App\Support\DbExpressions;
use App\Support\TeacherAppointmentTypeAllocator;
use App\Services\LocationMappingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserResource extends Resource
{
    protected const TIMEZONE_OPTIONS = [
        'Europe/Madrid' => 'Spain — Europe/Madrid',
        'Europe/Paris' => 'France — Europe/Paris',
        'Europe/London' => 'United Kingdom — Europe/London',
        'Africa/Johannesburg' => 'Africa/Johannesburg',
    ];
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'System';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Users';

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        if (! $user || ! $user->hasRole('admin', 'super_admin', 'super-admin')) {
            return false;
        }

        return parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user || ! $user->hasRole('admin', 'super_admin', 'super-admin')) {
            return false;
        }

        return parent::canAccess();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Account')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->label(fn (string $operation): string => $operation === 'create' ? 'Password' : 'Reset password')
                            ->helperText(fn (string $operation): ?string => $operation === 'create' ? null : 'Leave blank to keep the current password.')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->revealable()
                            ->dehydrateStateUsing(fn (?string $state) => filled($state) ? $state : null)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->columnSpan(1),
                        Forms\Components\Select::make('role')
                            ->options(fn (?User $record) => static::roleSelectOptions($record))
                            ->disableOptionWhen(fn ($value) => in_array($value, ['manager', 'report_recipient'], true)
                                || (in_array($value, ['super_admin', 'super-admin'], true)
                                    && ! (auth()->user()?->hasRole('super_admin', 'super-admin') ?? false)))
                            ->required()
                            ->columnSpan(1),
                        Forms\Components\Select::make('acuity_calendar_id')
                            ->label('Acuity calendar')
                            ->options(fn () => static::calendarOptions())
                            ->searchable()
                            ->placeholder('No calendar restriction')
                            ->helperText('Choose the calendar this user is linked to. Leave empty for unrestricted calendars.')
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                $role = (string) $get('role');
                                $hasCalendar = filled($state);
                                $calendarId = $hasCalendar ? (string) $state : null;
                                $isPresentialUk = $calendarId ? static::isPresentialUkCalendar($calendarId) : false;

                                if (in_array($role, User::TEACHING_ROLES, true)) {
                                    if ($hasCalendar && $isPresentialUk) {
                                        $set('teacher_scope', 'assigned_classes');
                                    } elseif ($hasCalendar) {
                                        $set('teacher_scope', 'calendar_linked');
                                    } elseif ($get('teacher_scope') === 'calendar_linked') {
                                        $set('teacher_scope', 'assigned_classes');
                                    }
                                } elseif ($hasCalendar && in_array($role, ['admin', 'super_admin'], true)) {
                                    $set('teacher_scope', 'calendar_linked');
                                } elseif (! $hasCalendar && $get('teacher_scope') === 'calendar_linked') {
                                    $set('teacher_scope', 'assigned_classes');
                                }
                            })
                            ->columnSpan(1),
                        Forms\Components\Repeater::make('teacher_calendar_assignments')
                            ->label('UK presential calendars and appointment types')
                            ->helperText('Add each Acuity calendar this teacher covers and choose the presential appointment types they should see in the portal.')
                            ->schema([
                                Forms\Components\Select::make('calendar_id')
                                    ->label('Calendar')
                                    ->options(fn () => static::calendarOptions())
                                    ->searchable()
                                    ->required()
                                    ->reactive()
                                    ->afterStateUpdated(fn (callable $set) => $set('appointment_type_ids', [])),
                                Forms\Components\Select::make('appointment_type_ids')
                                    ->label('Appointment types')
                                    ->helperText('Classes outside these types remain hidden for the teacher.')
                                    ->options(fn (callable $get) => static::appointmentTypeOptions($get('calendar_id')))
                                    ->multiple()
                                    ->searchable()
                                    ->preload(),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add calendar')
                            ->dehydrated(false)
                            ->default([])
                            ->visible(fn (callable $get) => static::shouldShowCalendarAssignmentSection($get('role')))
                            ->afterStateHydrated(function (callable $set, ?User $record): void {
                                $set('teacher_calendar_assignments', static::calendarAssignmentFormState($record));
                            }),
                        Forms\Components\Toggle::make('is_active')
                            ->inline(false)
                            ->default(true)
                            ->columnSpan(1),
                        Forms\Components\Select::make('timezone')
                            ->label('Timezone')
                            ->options(self::TIMEZONE_OPTIONS)
                            ->searchable()
                            ->required()
                            ->default('Europe/London')
                            ->columnSpan(1),
                        Forms\Components\Hidden::make('teacher_scope')
                            ->default(fn (?User $record) => $record?->teacherScope() ?? 'assigned_classes'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Region Access')
                    ->description('Control which regional data (UK, Spain, France) the user can view across dashboards and resources.')
                    ->schema([
                        Forms\Components\CheckboxList::make('access_regions')
                            ->options(function (): array {
                                return ['all' => 'All regions (full access)'] + array_combine(User::REGIONS, User::REGIONS);
                            })
                            ->helperText('Leave blank or pick “All regions” for unrestricted access. Selecting specific regions limits data everywhere.')
                            ->columns(2)
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if (is_array($state) && in_array('all', $state, true) && count($state) > 1) {
                                    $set('access_regions', ['all']);
                                }
                            }),
                        Forms\Components\Select::make('preferred_region')
                            ->label('Preferred region default')
                            ->options(array_combine(User::REGIONS, User::REGIONS))
                            ->placeholder('—')
                            ->helperText('Used for default filters. The user can still switch within their allowed regions.'),
                    ])
                    ->columns(2)
                    ->visible(fn (callable $get) => in_array($get('role'), ['admin', 'super_admin'], true)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'admin' => 'success',
                        'head_teacher' => 'info',
                        'teacher' => 'info',
                        'manager' => 'primary',
                        'report_recipient' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TagsColumn::make('resolved_regions')
                    ->label('Regions')
                    ->getStateUsing(function (User $record): array {
                        return $record->restrictsByRegion() ? $record->allowedRegions() : ['All'];
                    })
                    ->separator(', ')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TagsColumn::make('resolved_domains')
                    ->label('Capabilities')
                    ->getStateUsing(function (User $record): array {
                        $domains = $record->allowedDomains();
                        if (in_array('all', $domains, true)) {
                            return ['All areas'];
                        }

                        return collect($domains)
                            ->map(fn (string $key) => User::DOMAIN_ACCESS_OPTIONS[$key] ?? ucfirst($key))
                            ->values()
                            ->all();
                    })
                    ->separator(', ')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('teacher_scope')
                    ->label('Teacher scope')
                    ->formatStateUsing(fn (?string $state): string => User::TEACHER_SCOPE_OPTIONS[$state] ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('manager_scope')
                    ->label('Manager scope')
                    ->formatStateUsing(fn (?string $state): string => User::MANAGER_SCOPE_OPTIONS[$state] ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Last login')
                    ->formatStateUsing(fn (?\Illuminate\Support\Carbon $state): string => $state?->timezone(config('app.timezone'))?->format('d-m-Y H:i') ?? '—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->formatStateUsing(fn (?\Illuminate\Support\Carbon $state): string => $state?->timezone(config('app.timezone'))?->format('d-m-Y H:i') ?? '—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),

        ];
    }
    public static function canViewAny(): bool
    {
        return \Illuminate\Support\Facades\Gate::allows('manageUsers');
    }

    public static function canCreate(): bool
    {
        return \Illuminate\Support\Facades\Gate::allows('manageUsers');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return \Illuminate\Support\Facades\Gate::allows('manageUsers');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return \Illuminate\Support\Facades\Gate::allows('manageUsers');
    }

    public static function syncTeacherCalendars(User $user, array $calendarRows, ?string $primaryCalendarId = null): void
    {
        $normalized = static::normalizeCalendarAssignments($calendarRows);
        $previousCalendars = $user->teacherCalendarIds();

        if (is_string($primaryCalendarId) || is_numeric($primaryCalendarId)) {
            $primaryCalendarId = trim((string) $primaryCalendarId);
        } else {
            $primaryCalendarId = null;
        }

        if (! $primaryCalendarId && ! empty($normalized)) {
            $primaryCalendarId = array_key_first($normalized);
        }

        $calendarIds = array_keys($normalized);

        if (Schema::hasColumn('users', 'teacher_calendar_ids')) {
            $user->teacher_calendar_ids = $calendarIds;
        }
        $user->acuity_calendar_id = $primaryCalendarId ?: null;
        $user->save();

        static::syncTeacherAppointmentTypes($user, $normalized, $previousCalendars);
        static::syncCalendarLinkedSessions($user, $user->acuity_calendar_id);

        Cache::forget('user_resource_calendar_options');
    }

    public static function syncTeacherAppointmentTypes(User $user, array $calendarMap, array $previousCalendars = []): void
    {
        if (! Schema::hasTable('teacher_appointment_type_assignments')) {
            return;
        }

        $role = strtolower((string) ($user->role ?? ''));

        if (! in_array($role, User::TEACHING_ROLES, true)) {
            $user->teacherAppointmentTypeAssignments()->delete();
            $user->unsetRelation('teacherAppointmentTypeAssignments');
            $user->setRelation('teacherAppointmentTypeAssignments', $user->teacherAppointmentTypeAssignments()->get());
            TeacherAppointmentTypeAllocator::sync($user);

            return;
        }

        $calendarIds = array_keys($calendarMap);
        $displacedTeacherIds = [];

        DB::transaction(function () use ($user, $calendarMap, $calendarIds, &$displacedTeacherIds): void {
            if (empty($calendarIds)) {
                $user->teacherAppointmentTypeAssignments()->delete();
                return;
            }

            $user->teacherAppointmentTypeAssignments()
                ->whereNotIn('acuity_calendar_id', $calendarIds)
                ->delete();

            foreach ($calendarMap as $calendarId => $payload) {
                $typeIds = collect($payload['type_ids'] ?? [])
                    ->filter(fn ($value) => is_string($value) || is_numeric($value))
                    ->map(fn ($value) => trim((string) $value))
                    ->filter()
                    ->unique()
                    ->values();

                if ($typeIds->isEmpty()) {
                    $user->teacherAppointmentTypeAssignments()
                        ->where('acuity_calendar_id', $calendarId)
                        ->delete();

                    continue;
                }

                $user->teacherAppointmentTypeAssignments()
                    ->where('acuity_calendar_id', $calendarId)
                    ->whereNotIn('acuity_appointment_type_id', $typeIds->all())
                    ->delete();

                $labels = static::appointmentTypeOptions($calendarId);

                foreach ($typeIds as $typeId) {
                    $conflict = TeacherAppointmentTypeAssignment::query()
                        ->where('acuity_calendar_id', $calendarId)
                        ->where('acuity_appointment_type_id', $typeId)
                        ->where('user_id', '!=', $user->id)
                        ->first();

                    if ($conflict) {
                        $displacedTeacherIds[] = $conflict->user_id;
                        $conflict->delete();
                    }

                    $user->teacherAppointmentTypeAssignments()->updateOrCreate(
                        [
                            'acuity_calendar_id' => $calendarId,
                            'acuity_appointment_type_id' => $typeId,
                        ],
                        [
                            'appointment_type_name' => $labels[$typeId] ?? null,
                        ]
                    );
                }
            }
        });

        $user->unsetRelation('teacherAppointmentTypeAssignments');
        $user->setRelation('teacherAppointmentTypeAssignments', $user->teacherAppointmentTypeAssignments()->get());

        TeacherAppointmentTypeAllocator::sync($user, array_merge($previousCalendars, $calendarIds));

        foreach ($calendarIds as $calendarId) {
            Cache::forget("user_resource_calendar_appt_types_{$calendarId}");
            Cache::forget("user_resource_calendar_presential_{$calendarId}");
        }

        if (! empty($displacedTeacherIds)) {
            foreach (array_unique(array_filter($displacedTeacherIds)) as $displacedId) {
                $displacedTeacher = User::find($displacedId);

                if ($displacedTeacher) {
                    TeacherAppointmentTypeAllocator::sync($displacedTeacher);
                }
            }
        }
    }

    protected static function normalizeCalendarAssignments(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $calendarId = $row['calendar_id'] ?? null;

            if (! is_string($calendarId) && ! is_numeric($calendarId)) {
                continue;
            }

            $calendarId = trim((string) $calendarId);

            if ($calendarId === '' || ! static::isPresentialUkCalendar($calendarId)) {
                continue;
            }

            $typeIds = collect($row['appointment_type_ids'] ?? [])
                ->filter(fn ($value) => is_string($value) || is_numeric($value))
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $normalized[$calendarId] = ['type_ids' => $typeIds];
        }

        return $normalized;
    }

    protected static function shouldShowCalendarAssignmentSection($role): bool
    {
        $role = is_string($role) ? strtolower(trim($role)) : null;

        return $role && in_array($role, User::TEACHING_ROLES, true);
    }

    protected static function calendarAssignmentFormState(?User $record): array
    {
        if (! $record) {
            return [];
        }

        if (! Schema::hasTable('teacher_appointment_type_assignments')) {
            return [];
        }

        $record->loadMissing('teacherAppointmentTypeAssignments');

        $grouped = $record->teacherAppointmentTypeAssignments
            ->groupBy(fn ($assignment) => trim((string) ($assignment->acuity_calendar_id ?? '')));

        $calendarIds = $record->teacherCalendarIds();

        if (empty($calendarIds) && filled($record->acuity_calendar_id)) {
            $calendarIds = [trim((string) $record->acuity_calendar_id)];
        }

        if (empty($calendarIds)) {
            $calendarIds = $grouped->keys()
                ->filter(fn ($value) => is_string($value) && $value !== '')
                ->values()
                ->all();
        }

        $state = [];

        foreach ($calendarIds as $calendarId) {
            if ($calendarId === '') {
                continue;
            }

            $assignments = $grouped[$calendarId] ?? collect();

            $state[] = [
                'calendar_id' => $calendarId,
                'appointment_type_ids' => $assignments->pluck('acuity_appointment_type_id')
                    ->filter(fn ($value) => is_string($value) && $value !== '')
                    ->map(fn ($value) => trim((string) $value))
                    ->unique()
                    ->values()
                    ->all(),
            ];
        }

        return $state;
    }

    protected static function appointmentTypeOptions($calendarId): array
    {
        $calendarId = is_string($calendarId) || is_numeric($calendarId)
            ? trim((string) $calendarId)
            : null;

        if (! $calendarId || ! static::isPresentialUkCalendar($calendarId)) {
            return [];
        }

        return Cache::remember("user_resource_calendar_appt_types_{$calendarId}", 600, function () use ($calendarId) {
            $options = TeacherAppointmentTypeCatalog::query()
                ->where('acuity_calendar_id', $calendarId)
                ->orderBy('appointment_type_name')
                ->pluck('appointment_type_name', 'acuity_appointment_type_id')
                ->mapWithKeys(function ($name, $id) {
                    $label = trim((string) $name);
                    $key = trim((string) $id) ?: $label;

                    return $label !== '' ? [$key => $label] : [];
                })
                ->toArray();

            if (! empty($options)) {
                return $options;
            }

            return static::fallbackAppointmentTypeOptions($calendarId);
        });
    }

    protected static function fallbackAppointmentTypeOptions(string $calendarId): array
    {
        $typeIdExpression = DbExpressions::castToString("COALESCE(
            JSON_EXTRACT(acuity_data, '$.appointmentTypeID'),
            JSON_EXTRACT(acuity_data, '$.appointmentTypeId'),
            JSON_EXTRACT(acuity_data, '$.appointmentType.id'),
            JSON_EXTRACT(acuity_data, '$.typeID'),
            JSON_EXTRACT(acuity_data, '$.TypeID'),
            JSON_EXTRACT(acuity_data, '$.classTypeID'),
            JSON_EXTRACT(acuity_data, '$.ClassTypeID'),
            JSON_EXTRACT(acuity_data, '$.classType.id'),
            JSON_EXTRACT(acuity_data, '$.ClassType.id'),
            JSON_EXTRACT(acuity_data, '$.type.id'),
            JSON_EXTRACT(acuity_data, '$.appointmentType')
        )");

        $typeNameExpression = DbExpressions::castToString("COALESCE(
            JSON_EXTRACT(acuity_data, '$.appointmentType'),
            JSON_EXTRACT(acuity_data, '$.appointmentType.name'),
            JSON_EXTRACT(acuity_data, '$.classType'),
            JSON_EXTRACT(acuity_data, '$.ClassType'),
            JSON_EXTRACT(acuity_data, '$.classType.name'),
            JSON_EXTRACT(acuity_data, '$.ClassType.name'),
            JSON_EXTRACT(acuity_data, '$.classTypeName'),
            JSON_EXTRACT(acuity_data, '$.type'),
            JSON_EXTRACT(acuity_data, '$.Type')
        )");

        $calendarExpressions = [
            "JSON_EXTRACT(acuity_data, '$.calendarID')",
            "JSON_EXTRACT(acuity_data, '$.calendarId')",
            "JSON_EXTRACT(acuity_data, '$.calendar.id')",
            "JSON_EXTRACT(acuity_data, '$.CalendarID')",
            "JSON_EXTRACT(acuity_data, '$.Calendar.id')",
        ];

        $rows = DB::table('class_sessions')
            ->selectRaw("DISTINCT {$typeIdExpression} as type_id, {$typeNameExpression} as type_name")
            ->where(function ($query) use ($calendarId, $calendarExpressions) {
                static::applyCalendarMatchConditions($query, $calendarId, includeCalendarName: true);
            })
            ->whereRaw("{$typeNameExpression} IS NOT NULL AND {$typeNameExpression} <> ''")
            ->orderBy('type_name')
            ->get();

        $options = [];

        foreach ($rows as $row) {
            $typeId = is_string($row->type_id) ? trim($row->type_id) : null;
            $typeName = is_string($row->type_name) ? trim($row->type_name) : null;

            if (! $typeName) {
                continue;
            }

            $typeId = $typeId ?: $typeName;
            $options[$typeId] = $typeName;
        }

        if (! empty($options)) {
            asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $options;
    }

    protected static function applyCalendarMatchConditions($query, string $calendarId, bool $includeCalendarName = false): void
    {
        $calendarId = trim($calendarId);
        $paths = [
            '$.calendarID',
            '$.calendarId',
            '$.calendar.id',
            '$.CalendarID',
            '$.Calendar.id',
        ];

        foreach ($paths as $index => $path) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}(DbExpressions::castToString("JSON_EXTRACT(acuity_data, '{$path}')") . ' = ?', [$calendarId]);
        }

        if (Schema::hasColumn('class_sessions', 'acuity_calendar_id')) {
            $query->orWhere('acuity_calendar_id', $calendarId);
        }

        if ($includeCalendarName) {
            $query->orWhereRaw('LOWER(TRIM(COALESCE(calendar_name, ?))) = ?', ['', strtolower($calendarId)]);
        }
    }

    protected static function matchingCalendarSessionIds(string $calendarId): \Illuminate\Support\Collection
    {
        return ClassSession::query()
            ->where(function ($query) use ($calendarId) {
                static::applyCalendarMatchConditions($query, $calendarId);
            })
            ->pluck('id');
    }

    protected static function syncCalendarLinkedSessions(User $user, ?string $calendarId): void
    {
        $scope = $user->teacherScope();
        $calendarId = is_string($calendarId) || is_numeric($calendarId)
            ? trim((string) $calendarId)
            : '';

        if ($scope !== 'calendar_linked' || $calendarId === '') {
            ClassSession::query()
                ->where('teacher_id', $user->id)
                ->update(['teacher_id' => null]);

            return;
        }

        $matchingIds = static::matchingCalendarSessionIds($calendarId);

        if ($matchingIds->isEmpty()) {
            ClassSession::query()
                ->where('teacher_id', $user->id)
                ->update(['teacher_id' => null]);

            return;
        }

        ClassSession::query()
            ->where('teacher_id', $user->id)
            ->whereNotIn('id', $matchingIds->all())
            ->update(['teacher_id' => null]);

        foreach ($matchingIds->chunk(500) as $chunk) {
            ClassSession::query()
                ->whereIn('id', $chunk->all())
                ->update(['teacher_id' => $user->id]);
        }
    }

    protected static function sessionsForCalendar(string $calendarId): \Illuminate\Support\Collection
    {
        return ClassSession::query()
            ->where(function ($query) use ($calendarId) {
                $query->where('acuity_data->calendarID', $calendarId)
                    ->orWhere('acuity_data->calendarId', $calendarId)
                    ->orWhere('acuity_data->calendar.id', $calendarId)
                    ->orWhere('acuity_data->CalendarID', $calendarId)
                    ->orWhere('acuity_data->Calendar.id', $calendarId);
            })
            ->orderByDesc('session_date')
            ->limit(500)
            ->get(['id', 'location', 'category_norm', 'acuity_data']);
    }

    protected static function isPresentialUkCalendar($calendarId): bool
    {
        $calendarId = is_string($calendarId) || is_numeric($calendarId)
            ? trim((string) $calendarId)
            : null;

        if (! $calendarId) {
            return false;
        }

        return Cache::remember("user_resource_calendar_presential_{$calendarId}", 600, function () use ($calendarId): bool {
            $sessions = static::sessionsForCalendar($calendarId);
            if ($sessions->isEmpty()) {
                return false;
            }

            foreach ($sessions as $session) {
                $location = strtolower(trim((string) ($session->location ?? '')));
                $isUk = $location === 'uk';

                $payload = $session->acuity_data;
                if ($payload !== null && ! is_array($payload)) {
                    $payload = json_decode((string) $payload, true) ?: [];
                }

                $category = null;
                if (is_array($payload)) {
                    $category = data_get($payload, 'category')
                        ?? data_get($payload, 'Category')
                        ?? null;
                }
                $category = $category ?? $session->category_norm;

                if (! $isUk && $category) {
                    $region = LocationMappingService::regionForCategory($category);
                    $isUk = strtolower((string) $region) === 'uk';
                }

                if (! $isUk) {
                    continue;
                }

                if ($category && LocationMappingService::isOnlineCategory($category)) {
                    continue;
                }

                return true;
            }

            return false;
        });
    }

    protected static function calendarOptions(): array
    {
        return Cache::remember('user_resource_calendar_options', 600, function (): array {
            $rows = DB::table('class_sessions')
                ->select('calendar_name', 'acuity_data')
                ->whereNotNull('acuity_data')
                ->orderByDesc('session_date')
                ->limit(5000)
                ->get();

            $options = [];

            foreach ($rows as $row) {
                $payload = $row->acuity_data;
                if (is_string($payload)) {
                    $payload = json_decode($payload, true);
                }

                if (! is_array($payload)) {
                    continue;
                }

                $calendarId = data_get($payload, 'calendarID')
                    ?? data_get($payload, 'calendarId')
                    ?? data_get($payload, 'calendar.id')
                    ?? data_get($payload, 'calendar_id');

                if (! $calendarId) {
                    continue;
                }

                $calendarId = (string) $calendarId;
                if ($calendarId === '') {
                    continue;
                }

                $label = data_get($payload, 'calendarName')
                    ?? data_get($payload, 'calendar.name')
                    ?? data_get($payload, 'calendar')
                    ?? $row->calendar_name
                    ?? ('Calendar '.$calendarId);

                $label = trim((string) $label);
                if ($label === '') {
                    $label = 'Calendar '.$calendarId;
                }

                $options[$calendarId] = $label;
            }

            if (! empty($options)) {
                asort($options, SORT_NATURAL | SORT_FLAG_CASE);
            }

            $existingIds = DB::table('users')
                ->whereNotNull('acuity_calendar_id')
                ->distinct()
                ->pluck('acuity_calendar_id')
                ->map(fn ($value) => (string) $value)
                ->filter()
                ->values();

            $assignmentCalendars = [];
            if (Schema::hasTable('teacher_appointment_type_assignments')) {
                $assignmentCalendars = DB::table('teacher_appointment_type_assignments')
                    ->whereNotNull('acuity_calendar_id')
                    ->distinct()
                    ->pluck('acuity_calendar_id')
                    ->map(fn ($value) => (string) $value)
                    ->filter()
                    ->values()
                    ->all();
            }

            foreach ($assignmentCalendars as $id) {
                $existingIds->push($id);
            }

            if (Schema::hasColumn('users', 'teacher_calendar_ids')) {
                $calendarJsons = DB::table('users')
                    ->whereNotNull('teacher_calendar_ids')
                    ->pluck('teacher_calendar_ids');

                foreach ($calendarJsons as $payload) {
                    $decoded = is_string($payload)
                        ? json_decode($payload, true)
                        : (is_array($payload) ? $payload : []);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    foreach ($decoded as $id) {
                        if (! is_string($id) && ! is_numeric($id)) {
                            continue;
                        }

                        $normalized = trim((string) $id);
                        if ($normalized !== '') {
                            $existingIds->push($normalized);
                        }
                    }
                }
            }

            $existingIds = $existingIds->unique()->values();

            foreach ($existingIds as $id) {
                if (! isset($options[$id])) {
                    $options[$id] = 'Calendar '.$id;
                }
            }

            return $options;
        });
    }

    public static function flushAppointmentTypeCaches(?string $calendarId = null): void
    {
        $forget = function (string $id): void {
            Cache::forget("user_resource_calendar_appt_types_{$id}");
            Cache::forget("user_resource_calendar_presential_{$id}");
        };

        if ($calendarId !== null && $calendarId !== '') {
            $forget(trim((string) $calendarId));
        } else {
            $ids = ClassSession::query()
                ->whereNotNull('acuity_data')
                ->selectRaw("DISTINCT COALESCE(CAST(json_extract(acuity_data, '$.calendarID') AS CHAR), CAST(json_extract(acuity_data, '$.calendarId') AS CHAR), CAST(json_extract(acuity_data, '$.calendar.id') AS CHAR)) as calendar_id")
                ->pluck('calendar_id')
                ->filter()
                ->map(fn ($value) => trim((string) $value, "\" "))
                ->filter()
                ->unique();

            foreach ($ids as $id) {
                $forget($id);
            }
        }

        Cache::forget('user_resource_calendar_options');
    }

    protected static function roleSelectOptions(?User $record): array
    {
        $base = [
            'admin' => 'Admin',
            'head_teacher' => 'Head Teacher',
            'super_admin' => 'Super Admin',
            'teacher' => 'Teacher',
            User::ROLE_UK_MANAGER => 'UK Manager',
        ];

        // Only a super_admin may assign the super_admin role. For any other actor,
        // drop it from the option set entirely (Filament validates the submitted
        // value against these keys, so this is server-side enforcement, not just a
        // hidden dropdown item) — unless the edited record already holds it, in which
        // case it stays for correct display but disableOptionWhen keeps it unselectable.
        $actorIsSuper = auth()->user()?->hasRole('super_admin', 'super-admin') ?? false;
        $recordIsSuper = $record && in_array($record->role, ['super_admin', 'super-admin'], true);
        if (! $actorIsSuper && ! $recordIsSuper) {
            unset($base['super_admin']);
        }

        if ($record && in_array($record->role, ['manager', 'report_recipient'], true)) {
            $legacyLabel = $record->role === 'manager' ? 'Manager (legacy)' : 'Report Recipient (legacy)';
            $base[$record->role] = $legacyLabel;
        }

        ksort($base);

        return $base;
    }
}
