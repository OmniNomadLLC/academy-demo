<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StudentResource\Pages;
use App\Models\AttendanceLog;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentMergeSuggestion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn\TextColumnSize;
use Filament\Infolists as Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use App\Filament\Resources\StudentResource\RelationManagers\AttendanceRecordsRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\EmploymentProfilesRelationManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Academic Management';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'All Students';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Personal Information')
                    ->schema([
                        Forms\Components\TextInput::make('first_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('last_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255)
                            ->rules(['required_without:acuity_client_id'])
                            ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule) {
                                // Case-insensitive uniqueness; relies on unique index when available
                                return $rule;
                            }),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('Emergency Contact')
                    ->schema([
                        Forms\Components\TextInput::make('emergency_contact_name')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('emergency_contact_phone')
                            ->tel()
                            ->maxLength(255),
                    ])->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Academic Information')
                    ->schema([
                        Forms\Components\DatePicker::make('registration_date')
                            ->default(now()),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                        Forms\Components\Select::make('manager_id')
                            ->label('Manager')
                            ->relationship('manager','name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('System Information')
                    ->schema([
                        Forms\Components\TextInput::make('acuity_client_id')
                            ->label('Acuity Client ID')
                            ->rules(['required_without:email'])
                            ->maxLength(255)
                            ->helperText('Required if no email is provided.'),
                    ])->columns(1)->collapsed(),
            ]);
    }

    protected static function isUkStudent(Student $student): bool
    {
        if (property_exists($student, 'in_uk') && $student->in_uk) {
            return true;
        }

        $location = strtolower((string) ($student->location ?? ''));

        return in_array($location, ['uk', 'riverside', 'parkside', 'northgate', 'southbank'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                // Lean on persisted activity flags to keep sorting indexed on MariaDB
                $query->select('students.*')
                    ->orderByDesc('is_active_recent')
                    ->orderByDesc('last_appointment_date');

                if ($user = \Illuminate\Support\Facades\Auth::user()) {
                    if ($user->restrictsByRegion()) {
                        $allowed = $user->allowedRegions();
                        $query->whereIn('students.location', $allowed);
                    }
                }

                return $query;
            })
            ->columns([
                Tables\Columns\TagsColumn::make('regions')
                    ->label('Regions')
                    ->getStateUsing(function ($record) {
                        $tags = [];
                        if (isset($record->in_uk) && $record->in_uk) $tags[] = 'UK';
                        if (isset($record->in_spain) && $record->in_spain) $tags[] = 'Spain';
                        if (isset($record->in_france) && $record->in_france) $tags[] = 'France';
                        if (empty($tags) && is_string($record->location) && trim($record->location) !== '') {
                            $location = trim($record->location);
                            $tags[] = strcasecmp($location, 'Academic') === 0 ? 'Internal' : $location;
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
                        $last = $record->last_appointment_date; // cast to date in model
                        $next = $record->next_appointment_date; // cast to date in model
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
                Tables\Filters\SelectFilter::make('region')
                    ->label('Region')
                    ->options([
                        'UK' => 'UK',
                        'Spain' => 'Spain',
                        'France' => 'France',
                        'Academic' => 'Internal',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $val = $data['value'] ?? null;
                        if (! $val) {
                            return $query;
                        }

                        return $query->where(function (Builder $q) use ($val) {
                            if ($val === 'Academic') {
                                $q->where('location', 'Academic');
                            } else {
                                $q->where(function (Builder $qq) use ($val) {
                                    $qq->where('location', $val)
                                        ->orWhere('location', strtoupper($val));
                                });
                            }
                        });
                    }),

                Tables\Filters\SelectFilter::make('is_active')
                    ->label('Active')
                    ->options(['yes' => 'Yes', 'no' => 'No'])
                    ->query(function (Builder $query, array $data): Builder {
                        $val = $data['value'] ?? null;
                        if ($val == 'yes') {
                            return $query->where(function (Builder $q) {
                                $q->whereDate('last_appointment_date', '>=', now()->subDays(30))
                                  ->orWhereDate('next_appointment_date', '<=', now()->addDays(30));
                            });
                        }
                        if ($val == 'no') {
                            return $query->where(function (Builder $q) {
                                $q->whereNull('last_appointment_date')
                                  ->orWhereDate('last_appointment_date', '<', now()->subDays(30));
                            }).where(function (Builder $q) {
                                $q->whereNull('next_appointment_date')
                                  ->orWhereDate('next_appointment_date', '>', now()->addDays(30));
                            });
                        }
                        return $query;
                    }),
                Tables\Filters\SelectFilter::make('acuity_category')
                    ->label('Category')
                    ->options(fn() => \App\Models\Student::query()
                        ->whereNotNull('acuity_category')
                        ->distinct()
                        ->orderBy('acuity_category')
                        ->pluck('acuity_category', 'acuity_category')
                        ->toArray())
                    ->query(function (Builder $query, array $data): Builder {
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
                Tables\Actions\Action::make('merge_into')
                    ->label('')
                    ->tooltip('Merge into...')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin', 'admin'))
                    ->modalHeading(fn (Student $record) => "Merge {$record->first_name} {$record->last_name} into another student")
                    ->modalDescription(function (Student $record) {
                        // Escape the Acuity-sourced name: it is free-text a client enters
                        // when booking, and it renders unescaped inside this HtmlString in a
                        // super_admin/admin session (stored-XSS sink otherwise).
                        $name = e($record->first_name) . ' ' . e($record->last_name);

                        return new \Illuminate\Support\HtmlString(
                            'The student you select below will be <strong>kept</strong>. ' .
                            "All sessions, attendance, and data from <strong>{$name}</strong> " .
                            "will be moved to the selected student. <strong>{$name}</strong> " .
                            'will then be soft-deleted.'
                        );
                    })
                    ->modalSubmitActionLabel('Yes, merge students')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalIconColor('warning')
                    ->form([
                        Forms\Components\Select::make('keep_student_id')
                            ->label('Select student to keep')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Student::query()
                                ->where(function ($q) use ($search) {
                                    $q->where('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%");
                                })
                                ->whereNull('deleted_at')
                                ->limit(20)
                                ->get()
                                ->mapWithKeys(fn (Student $s) => [
                                    $s->id => "{$s->first_name} {$s->last_name} — " . ($s->email ?? 'no email') . " ({$s->location})",
                                ])
                            )
                            ->required()
                            ->helperText('Search by name or email. This student will be KEPT.'),
                        Forms\Components\Placeholder::make('merge_warning')
                            ->content(new \Illuminate\Support\HtmlString(
                                '<div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300">' .
                                '<strong>Warning:</strong> This action cannot be undone. All records will be transferred and the current student will be deleted.' .
                                '</div>'
                            )),
                    ])
                    ->action(function (Student $record, array $data) {
                        $keepStudent = Student::find($data['keep_student_id']);
                        if (! $keepStudent) {
                            Notification::make()->title('Selected student not found.')->danger()->send();
                            return;
                        }
                        if ($keepStudent->id === $record->id) {
                            Notification::make()->title('Cannot merge a student into themselves.')->danger()->send();
                            return;
                        }

                        $deleteStudent = $record;
                        $fkTables = [
                            'class_sessions', 'attendance_records', 'student_assessments',
                            'student_progress_notes', 'student_enrollment_messages',
                            'employment_outcomes', 'employment_profiles', 'student_skill_progress_logs',
                        ];

                        DB::transaction(function () use ($keepStudent, $deleteStudent, $fkTables) {
                            foreach ($fkTables as $table) {
                                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'student_id')) {
                                    continue;
                                }
                                $moved = DB::table($table)
                                    ->where('student_id', $deleteStudent->id)
                                    ->update(['student_id' => $keepStudent->id]);
                                if ($moved) {
                                    Log::info("StudentResource merge: repointed {$moved} rows in {$table}", [
                                        'kept_id' => $keepStudent->id,
                                        'deleted_id' => $deleteStudent->id,
                                    ]);
                                }
                            }

                            if (empty($keepStudent->acuity_client_id) && ! empty($deleteStudent->acuity_client_id)) {
                                $keepStudent->update(['acuity_client_id' => $deleteStudent->acuity_client_id]);
                            }

                            // Resolve any existing merge suggestion between these two
                            $minId = min($keepStudent->id, $deleteStudent->id);
                            $maxId = max($keepStudent->id, $deleteStudent->id);
                            StudentMergeSuggestion::where('student_a_id', $minId)
                                ->where('student_b_id', $maxId)
                                ->where('status', 'pending')
                                ->update([
                                    'status'      => 'merged',
                                    'resolved_by' => Auth::id(),
                                    'resolved_at' => now(),
                                ]);

                            $deleteStudent->delete();

                            // Log to attendance_logs (nullable class_session_id for manual merges)
                            if (Schema::hasTable('attendance_logs')) {
                                try {
                                    AttendanceLog::create([
                                        'class_session_id' => null,
                                        'student_id'       => $deleteStudent->id,
                                        'user_id'          => Auth::id(),
                                        'user_role'        => strtolower((string) (auth()->user()?->role ?? '')),
                                        'action'           => 'manual_merge',
                                        'old_status'       => null,
                                        'new_status'       => null,
                                        'performed_at'     => now(),
                                    ]);
                                } catch (\Throwable $e) {
                                    report($e);
                                }
                            }

                            Log::info('StudentResource: manual merge completed', [
                                'kept_id'     => $keepStudent->id,
                                'deleted_id'  => $deleteStudent->id,
                                'resolved_by' => Auth::id(),
                            ]);
                        });

                        Notification::make()
                            ->title('Students merged successfully')
                            ->body("{$deleteStudent->first_name} {$deleteStudent->last_name} merged into {$keepStudent->first_name} {$keepStudent->last_name}.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\RestoreAction::make()
                    ->label('')
                    ->tooltip('Restore')
                    ->icon('heroicon-o-arrow-uturn-left'),
                Tables\Actions\DeleteAction::make()
                    ->label('')
                    ->tooltip('Delete')
                    ->icon('heroicon-o-trash'),
            ])
            ->defaultSort('last_appointment_date', 'desc')
            ->paginated([25, 50, 100]);
    }


    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make()
                    ->schema([
                        Infolists\Components\Split::make([
                            // Left column - Student Info
                            Infolists\Components\Grid::make(1)
                                ->schema([
                                    Infolists\Components\Group::make([
                                        Infolists\Components\TextEntry::make('full_name')
                                            ->label('')
                                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                                            ->weight('bold')
                                            ->formatStateUsing(fn($record) => strtoupper($record->full_name)),
                                        Infolists\Components\TextEntry::make('email')
                                            ->label('')
                                            ->color('gray')
                                            ->icon('heroicon-m-envelope'),
                                    ])->columnSpan(1),
                                    
                                    Infolists\Components\Section::make('Contact Information')
                                        ->schema([
                                            Infolists\Components\TextEntry::make('email')
                                                ->label('Email')
                                                ->icon('heroicon-m-envelope')
                                                ->copyable(),
                                            Infolists\Components\TextEntry::make('phone')
                                                ->label('Phone')
                                                ->icon('heroicon-m-phone')
                                                ->copyable(),
                                            Infolists\Components\TextEntry::make('delivery_mode')
                                                ->label('Delivery Mode')
                                                ->state(fn ($record) => $record->is_online ? 'Online' : 'Presential')
                                                ->badge()
                                                ->color(fn ($state) => strtolower((string) $state) === 'online' ? 'primary' : 'gray')
                                                ->visible(fn ($record) => is_string($record->location) && strtolower($record->location) === 'uk'),
                                            Infolists\Components\ViewEntry::make('enrollment_message_summary')
                                                ->label('Enrolment message')
                                                ->view('filament.students.partials.enrollment-summary-table')
                                                ->viewData(fn (Student $record) => self::enrollmentSummaryData($record))
                                                ->visible(fn (Student $record) => self::isUkStudent($record)),
                                        ])->columns(1),
                                        
                                    Infolists\Components\Section::make('Manager')
                                        ->schema([
                                            Infolists\Components\ViewEntry::make('manager_card')
                                                ->label('')
                                                ->view('filament/students/uk-manager-card-host')
                                                ->viewData(fn($record) => ['studentId' => $record->id]),
                                        ])
                                        ->columns(1)
                                        ->extraAttributes([
                                            'class' => 'bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm rounded-xl'
                                        ])
                                        ->visible(function ($record) {
                                            try {
                                                return (isset($record->in_uk) && $record->in_uk) || (is_string($record->location) && strtolower(trim($record->location)) === 'uk');
                                            } catch (\Throwable $e) {
                                                return false;
                                            }
                                        }),

                                    Infolists\Components\Section::make('Notes')
                                        ->schema([
                                            Infolists\Components\TextEntry::make('notes')
                                                ->label('')
                                                ->placeholder('No notes imported from Acuity yet')
                                                ->columnSpanFull()
                                                ->extraAttributes([
                                                    'class' => 'dark:text-gray-200'
                                                ]),
                                        ])
                                        ->columns(1)
                                        ->extraAttributes([
                                            'class' => 'bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm rounded-xl'
                                        ]),
                                ]),
                                
                            // Right column - Timeline & Sync Status
                            Infolists\Components\Grid::make(1)
                                ->schema([
                                    Infolists\Components\Section::make('Attendance')
                                        ->schema([
                                            Infolists\Components\ViewEntry::make('attendance_card')
                                                ->label('')
                                                ->view('filament/students/attendance-card-host')
                                                ->viewData(fn($record) => ['studentId' => $record->id]),
                                        ])->columns(1)
                                        ->visible(function ($record) {
                                            try {
                                                return (isset($record->in_uk) && $record->in_uk) || (is_string($record->location) && strtolower(trim($record->location)) === 'uk');
                                            } catch (\Throwable $e) { return false; }
                                        }),
                                    Infolists\Components\Section::make('Skill development')
                                        ->schema([
                                            Infolists\Components\ViewEntry::make('skill_progress')
                                                ->label('')
                                                ->view('filament/students/skill-progress-host')
                                                ->viewData(fn($record) => ['studentId' => $record->id]),
                                        ])
                                        ->columns(1)
                                        ->extraAttributes([
                                            'class' => 'bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm rounded-xl'
                                        ])
                                        ->visible(fn() => true),
                                    // Sync Status section removed per request
                                ]),
                        ])->from('md'),
                        Infolists\Components\Section::make('Progress Notes')
                            ->schema([
                                Infolists\Components\ViewEntry::make('progress_notes')
                                    ->label('')
                                    ->view('filament/students/progress-notes-host')
                                    ->viewData(fn($record) => ['studentId' => $record->id]),
                            ])
                            ->collapsible()->collapsed(false)
                            ->extraAttributes([
                                'class' => 'bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm rounded-xl'
                            ]),

                        Infolists\Components\Section::make('Upcoming Classes')
                            ->description('Next 90 days — scheduled or confirmed')
                            ->schema([
                                Infolists\Components\ViewEntry::make('upcoming_classes')
                                    ->label('')
                                    ->view('filament/students/upcoming-classes-host')
                                    ->viewData(function ($record) {
                                        $isUk = false;
                                        try {
                                            $isUk = (isset($record->in_uk) && $record->in_uk) || (is_string($record->location) && strtolower(trim($record->location)) === 'uk');
                                        } catch (\Throwable $e) {}
                                        return [
                                            'studentId' => $record->id,
                                            'isUk' => $isUk,
                                        ];
                                    }),
                            ])
                            ->collapsible()->collapsed()
                            ->extraAttributes([
                                'class' => 'bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm rounded-xl'
                            ]),

                        Infolists\Components\Section::make('Past Classes')
                            ->description('All past classes — confirmed or completed')
                            ->schema([
                                Infolists\Components\ViewEntry::make('past_classes')
                                    ->label('')
                                    ->view('filament/students/past-classes-host')
                                    ->viewData(function ($record) {
                                        $isUk = false;
                                        try {
                                            $isUk = (isset($record->in_uk) && $record->in_uk) || (is_string($record->location) && strtolower(trim($record->location)) === 'uk');
                                        } catch (\Throwable $e) {}
                                        return [
                                            'studentId' => $record->id,
                                            'isUk' => $isUk,
                                        ];
                                    }),
                            ])
                            ->collapsible()->collapsed()
                            ->extraAttributes([
                                'class' => 'bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm rounded-xl'
                            ]),
                    ])
                    ->headerActions([
                        Infolists\Components\Actions\Action::make('view_in_acuity')
                            ->label('View in Acuity')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->color('warning')
                            ->url(fn($record) => $record->acuity_client_id 
                                ? "https://acuityscheduling.com/clients/{$record->acuity_client_id}" 
                                : null)
                            ->openUrlInNewTab()
                            ->visible(fn($record) => $record->acuity_client_id),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            AttendanceRecordsRelationManager::class,
            EmploymentProfilesRelationManager::class,
        ];
    }

    protected static function enrollmentSummaryData(Student $record): array
    {
        $record->loadMissing(['latestEnrollmentMessage.initiatedBy', 'enrollmentLastSender']);

        $appTz = config('app.timezone');
        $latest = $record->latestEnrollmentMessage;

        if ($latest) {
            $channelLabel = match ($latest->channel) {
                \App\Models\StudentEnrollmentMessage::CHANNEL_WHATSAPP => 'WhatsApp',
                \App\Models\StudentEnrollmentMessage::CHANNEL_SMS => 'SMS',
                default => ucfirst((string) $latest->channel),
            };

            $sentAt = ($latest->created_at ?? $latest->status_updated_at)?->copy()->timezone($appTz);
            $deliveredAt = $latest->delivered_at?->timezone($appTz);
            $readAt = $latest->read_at?->timezone($appTz);

            return [
                'summary' => [
                    'channel' => $channelLabel,
                    'sent_at' => $sentAt,
                    'sent_human' => $sentAt?->diffForHumans(),
                    'status' => $latest->status ? ucfirst($latest->status) : null,
                    'delivered_at' => $deliveredAt,
                    'delivered_human' => $deliveredAt?->diffForHumans(),
                    'read_at' => $readAt,
                    'read_human' => $readAt?->diffForHumans(),
                    'email_sent' => (bool) ($latest->meta['email_sent'] ?? false),
                ],
                'sender' => optional($latest->initiatedBy)->name
                    ?? optional($record->enrollmentLastSender)->name,
            ];
        }

        if (! $record->enrollment_last_sent_at) {
            return ['summary' => null, 'sender' => null];
        }

        $fallbackSent = $record->enrollment_last_sent_at->timezone($appTz);

        return [
            'summary' => [
                'channel' => $record->enrollment_last_channel === 'whatsapp' ? 'WhatsApp' : 'SMS / Email',
                'sent_at' => $fallbackSent,
                'sent_human' => $fallbackSent->diffForHumans(),
                'status' => 'Sent',
                'delivered_at' => null,
                'delivered_human' => null,
                'read_at' => null,
                'read_human' => null,
                'email_sent' => $record->enrollment_last_channel === 'sms_email',
            ],
            'sender' => optional($record->enrollmentLastSender)->name,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'create' => Pages\CreateStudent::route('/create'),
            'view' => Pages\ViewStudent::route('/{record}'),
            'edit' => Pages\EditStudent::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = DB::table('students')
            ->whereNull('deleted_at')
            ->count();

        return (string) $count;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'success';
    }

    protected static function isSuperAdmin(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::isSuperAdmin() && parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        return static::isSuperAdmin() && parent::canAccess();
    }

    public static function canViewAny(): bool
    {
        return static::isSuperAdmin() && parent::canViewAny();
    }

    protected static function userIsUkManager(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isUkManager() ?? false);
    }
}
