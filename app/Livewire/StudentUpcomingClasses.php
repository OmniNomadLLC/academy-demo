<?php

namespace App\Livewire;

use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Support\DbExpressions;
use App\Support\TeacherRoster;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

class StudentUpcomingClasses extends Component implements HasTable, HasForms
{
    use InteractsWithTable;
    use InteractsWithForms;
    public int $studentId;
    public bool $isUk = false;
    public bool $isPast = false;

    public function mount(int $studentId, bool $isUk = false, bool $isPast = false): void
    {
        $this->studentId = $studentId;
        $this->isUk = $isUk;
        $this->isPast = $isPast;
    }

    #[On('attendance-updated')]
    public function handleAttendanceUpdated(int $studentId): void
    {
        if ($studentId === $this->studentId) {
            $this->resetTable();
        }
    }

    private function baseQuery(): EloquentBuilder
    {
        $student = Student::find($this->studentId);
        // Defensive guard: empty query if missing
        $q = ClassSession::query()->whereRaw('1=0');
        if (!$student) return $q;

        $today = now()->toDateString();
        $to = $this->isPast ? $today : null; // all future when not past
        $from = $this->isPast ? null : $today;

        $clientIdExpr = "COALESCE(\n            json_extract(class_sessions.acuity_data, '$.clientID'),\n            json_extract(class_sessions.acuity_data, '$.clientId'),\n            json_extract(class_sessions.acuity_data, '$.client.id'),\n            json_extract(class_sessions.acuity_data, '$.client_id'),\n            json_extract(class_sessions.acuity_data, '$.ClientID'),\n            json_extract(class_sessions.acuity_data, '$.ClientId'),\n            json_extract(class_sessions.acuity_data, '$.Client.id'),\n            json_extract(class_sessions.acuity_data, '$.Client_id')\n        )";
        $emailExpr = "LOWER(COALESCE(\n            json_extract(class_sessions.acuity_data, '$.email'),\n            json_extract(class_sessions.acuity_data, '$.client.email'),\n            json_extract(class_sessions.acuity_data, '$.Client.email')\n        ))";
        $castClientIdExpr = DbExpressions::castToString($clientIdExpr);
        $castParamExpr = DbExpressions::castToString('?');

        $hasStudentIdColumn = \Illuminate\Support\Facades\Schema::hasColumn('class_sessions', 'student_id');
        $hasStudentEmailColumn = \Illuminate\Support\Facades\Schema::hasColumn('class_sessions', 'student_email');
        $hasClientEmailColumn = \Illuminate\Support\Facades\Schema::hasColumn('class_sessions', 'client_email');

        $normalizedEmail = $student->email ? strtolower(trim((string) $student->email)) : null;

        $q = ClassSession::query()
            ->leftJoin('users as u', 'u.id', '=', 'class_sessions.teacher_id')
            ->leftJoin('attendance_records as ar', function ($j) use ($student) {
                $j->on('ar.class_session_id', '=', 'class_sessions.id')->where('ar.student_id', '=', $student->id);
            })
            ->select([
                'class_sessions.*',
                DB::raw('u.name as teacher_name'),
                DB::raw("COALESCE(ar.status, '') as mark"),
            ])
            ->when(!$this->isPast, function ($q) use ($from) {
                $q->whereDate('class_sessions.session_date', '>=', $from);
            })
            ->when($this->isPast, function ($q) use ($today) {
                $q->whereDate('class_sessions.session_date', '<', $today);
            })
            ->where(function ($w) {
                $w->where(function ($inner) {
                    $inner->where('class_sessions.canceled', false)
                        ->orWhereNull('class_sessions.canceled');
                });

                $allowedStatuses = $this->isPast
                    ? ['confirmed', 'completed', 'cancelled']
                    : ['scheduled', 'confirmed', 'cancelled'];

                $w->orWhereIn('class_sessions.status', $allowedStatuses);
            })
            ->where(function ($w) use (
                $student,
                $emailExpr,
                $castClientIdExpr,
                $castParamExpr,
                $hasStudentIdColumn,
                $hasStudentEmailColumn,
                $hasClientEmailColumn,
                $normalizedEmail
            ) {
                if ($hasStudentIdColumn) {
                    $w->orWhere('class_sessions.student_id', $student->id);
                }

                if (!empty($student->acuity_client_id)) {
                    $w->orWhere(function ($clientQuery) use ($castClientIdExpr, $castParamExpr, $student, $hasStudentIdColumn) {
                        if ($hasStudentIdColumn) {
                            $clientQuery->whereNull('class_sessions.student_id');
                        }

                        $clientQuery->whereRaw("$castClientIdExpr = $castParamExpr", [(string) $student->acuity_client_id]);
                        $clientQuery->whereNotExists($this->linkedSessionSubquery($student));
                    });
                }

                if ($normalizedEmail) {
                    $w->orWhere(function ($emailQuery) use (
                        $normalizedEmail,
                        $emailExpr,
                        $hasStudentIdColumn,
                        $hasStudentEmailColumn,
                        $hasClientEmailColumn,
                        $student
                    ) {
                        if ($hasStudentIdColumn) {
                            $emailQuery->whereNull('class_sessions.student_id');
                        }

                        $emailQuery->where(function ($emailMatches) use (
                            $normalizedEmail,
                            $emailExpr,
                            $hasStudentEmailColumn,
                            $hasClientEmailColumn
                        ) {
                            $emailMatches->whereRaw("$emailExpr = LOWER(?)", [$normalizedEmail]);

                            if ($hasStudentEmailColumn) {
                                $emailMatches->orWhereRaw('LOWER(class_sessions.student_email) = LOWER(?)', [$normalizedEmail]);
                            }

                            if ($hasClientEmailColumn) {
                                $emailMatches->orWhereRaw('LOWER(class_sessions.client_email) = LOWER(?)', [$normalizedEmail]);
                            }
                        });

                        $emailQuery->whereNotExists($this->linkedSessionSubquery($student));
                    });
                }
            })
            ->whereIn('class_sessions.id', function ($sub) use ($student) {
                $sub->selectRaw('MAX(cs_dup.id)')
                    ->from('class_sessions as cs_dup')
                    ->where(function ($inner) use ($student) {
                        $inner->where('cs_dup.student_id', $student->id)
                            ->orWhereNull('cs_dup.student_id');
                    })
                    ->groupBy(
                        DB::raw('COALESCE(cs_dup.student_id, 0)'),
                        'cs_dup.session_date',
                        'cs_dup.start_time',
                        'cs_dup.end_time',
                        DB::raw('COALESCE(cs_dup.calendar_name, \'\')')
                    );
            })
            ->when($this->isPast, function ($q) {
                $q->orderByDesc('class_sessions.session_date')->orderByDesc('class_sessions.start_time');
            }, function ($q) {
                $q->orderBy('class_sessions.session_date')->orderBy('class_sessions.start_time');
            });

        $user = Auth::user();
        if ($user && $user->isTeachingRole()) {
            $teacherSessions = TeacherRoster::sessions($user);
            $q->whereIn('class_sessions.id', $teacherSessions->select('id'));
            if ($user->restrictsByRegion()) {
                $q->whereIn('class_sessions.location', $user->allowedRegions());
            }
        }

        return $q;
    }

    public function mark(int $sessionId, string $status): void
    {
        if (!$this->isUk) return; // controls hidden in UI, but keep server guard
        if (!in_array($status, ['present','late','absent'], true)) return;
        $uid = Auth::id();
        $now = now();

        $extra = [
            'marked_by' => $uid,
            'marked_at' => $now,
        ];

        if ($status !== 'absent') {
            $extra['sent_at'] = null;
        }

        AttendanceRecord::recordStatus($sessionId, $this->studentId, $status, $extra);
        // Recompute student's attendance rate
        try {
            $student = Student::find($this->studentId);
            if ($student) { $student->recomputeAttendanceRate(); }
        } catch (\Throwable $e) {}

        // Notify attendance card to refresh
        $this->dispatch('attendance-updated', studentId: $this->studentId);
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $isUk = $this->isUk;
        $isPast = $this->isPast;
        $columns = [
            Tables\Columns\TextColumn::make('session_date')->label('Date')->date('D, d M Y')->sortable(),
            Tables\Columns\TextColumn::make('start_time')->label('Start')->time('H:i')->sortable(),
            Tables\Columns\TextColumn::make('end_time')->label('End')->time('H:i')->sortable(),
            Tables\Columns\TextColumn::make('calendar_name')->label('Calendar')->badge(),
            Tables\Columns\TextColumn::make('category_norm')->label('Category'),
        ];
        $columns[] = Tables\Columns\TextColumn::make('status')
            ->label('Status')
            ->badge()
            ->color(function ($record) {
                $status = strtolower((string)($record->status ?? ''));
                $cancel = (bool) ($record->canceled ?? false) || $status === 'cancelled';
                if ($cancel) return 'danger';
                return in_array($status, ['scheduled','confirmed']) ? 'success' : 'gray';
            })
            ->formatStateUsing(function ($record) {
                $status = strtolower((string)($record->status ?? ''));
                return $status === '' ? 'Scheduled' : ucfirst($status);
            });

        $columns[] = Tables\Columns\TextColumn::make('mark')
            ->label('Attendance')
            ->badge()
            ->color(function ($state) {
                $state = strtolower((string) $state);
                return match ($state) {
                    'present' => 'success',
                    'late' => 'warning',
                    'absent' => 'danger',
                    default => 'gray',
                };
            })
            ->formatStateUsing(function ($state) {
                $state = strtolower((string) $state);
                if ($state === '') {
                    return 'Not marked';
                }

                return ucfirst($state);
            });

        $actions = [];

        if ($this->canMarkAttendance() && ! $isPast) {
            $actions[] = ActionGroup::make([
                Action::make('mark_present')
                    ->label('Mark present')
                    ->color('success')
                    ->icon('heroicon-o-check')
                    ->action(fn ($record) => $this->mark((int) $record->id, 'present')),
                Action::make('mark_late')
                    ->label('Mark late')
                    ->color('warning')
                    ->icon('heroicon-o-clock')
                    ->action(fn ($record) => $this->mark((int) $record->id, 'late')),
                Action::make('mark_absent')
                    ->label('Mark absent')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->action(fn ($record) => $this->mark((int) $record->id, 'absent')),
            ])
                ->label('Mark attendance')
                ->icon('heroicon-o-clipboard-document-check')
                ->visible(fn ($record) => $this->sessionSupportsAttendance($record));
        }

        if ($this->canManageSessions() && ! $isPast) {
            $actions[] = Action::make('change_status')
                ->label('')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('primary')
                ->tooltip('Change status')
                ->form([
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options($this->sessionStatusOptions())
                        ->required()
                        ->default(fn ($record) => $this->normalizeStatus($record->status ?? '')),
                ])
                ->visible(fn () => $this->canManageSessions())
                ->action(function ($record, array $data) {
                    $this->updateSessionStatus((int) $record->id, (string) ($data['status'] ?? ''));
                });

            $actions[] = Action::make('delete_session')
                ->label('')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->tooltip('Delete class session')
                ->requiresConfirmation()
                ->modalHeading('Delete this class session?')
                ->modalDescription('This permanently removes the session for all students. Future Acuity syncs will recreate it if the booking still exists.')
                ->visible(fn () => $this->canManageSessions())
                ->action(fn ($record) => $this->deleteSession((int) $record->id));
        }

        return $table
            ->query($this->baseQuery())
            ->columns($columns)
            ->actions($actions)
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_change_status')
                        ->label('Change status')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->color('primary')
                        ->visible(fn () => !$this->isPast && $this->canManageSessions())
                        ->form([
                            Forms\Components\Select::make('status')
                                ->label('Status')
                                ->options($this->sessionStatusOptions())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $this->bulkUpdateSessionStatus($records, (string) ($data['status'] ?? ''));
                        }),
                    BulkAction::make('bulk_delete_sessions')
                        ->label('Delete sessions')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn () => !$this->isPast && $this->canManageSessions())
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected sessions?')
                        ->modalDescription('This removes the sessions for all students. Acuity syncs can recreate them if the booking still exists.')
                        ->action(function (Collection $records) {
                            $this->bulkDeleteSessions($records);
                        }),
                ]),
            ])
            ->emptyStateHeading($isPast ? 'No past classes found.' : 'No upcoming classes found.')
            ->paginated(true)
            ->paginationPageOptions([10, 25, 50, 100]);
    }

    private function canMarkAttendance(): bool
    {
        if (! $this->isUk) {
            return false;
        }

        $user = Auth::user();
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasDomainAccess') && ! $user->hasDomainAccess('attendance')) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));

        return in_array($role, array_merge(User::TEACHING_ROLES, ['admin', 'super_admin']), true);
    }

    private function canManageSessions(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));

        return in_array($role, ['admin', 'super_admin'], true);
    }

    private function sessionSupportsAttendance($record): bool
    {
        if (! $this->isUk) {
            return false;
        }

        $location = strtolower((string) ($record->location ?? ''));
        if ($location !== 'uk') {
            return false;
        }

        $status = strtolower((string) ($record->status ?? ''));
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return false;
        }

        return true;
    }

    public function render()
    {
        return view('livewire.student-upcoming-classes');
    }

    public function deleteSession(int $sessionId): void
    {
        if (! $this->canManageSessions()) {
            abort(403);
        }

        $session = ClassSession::find($sessionId);

        if (! $session) {
            Notification::make()
                ->title('Class session not found')
                ->danger()
                ->send();

            return;
        }

        $session->delete();

        Notification::make()
            ->title('Class session deleted')
            ->success()
            ->body('If this appointment still exists in Acuity it will return on the next sync.')
            ->send();

        $this->resetTable();
    }

    public function updateSessionStatus(int $sessionId, string $status): void
    {
        if (! $this->canManageSessions()) {
            abort(403);
        }

        $normalized = $this->normalizeStatus($status);

        if (! $normalized || ! array_key_exists($normalized, $this->sessionStatusOptions())) {
            Notification::make()
                ->title('Invalid status')
                ->danger()
                ->body('Choose one of the supported statuses.')
                ->send();

            return;
        }

        $session = ClassSession::find($sessionId);

        if (! $session) {
            Notification::make()
                ->title('Class session not found')
                ->danger()
                ->send();

            return;
        }

        $this->applyStatusToSession($session, $normalized);

        Notification::make()
            ->title('Status updated')
            ->success()
            ->body(sprintf('Session is now %s.', ucfirst($normalized)))
            ->send();

        $this->resetTable();
    }

    private function bulkUpdateSessionStatus(Collection $sessions, string $status): void
    {
        if (! $this->canManageSessions()) {
            abort(403);
        }

        $normalized = $this->normalizeStatus($status);

        if (! $normalized || ! array_key_exists($normalized, $this->sessionStatusOptions())) {
            Notification::make()
                ->title('Invalid status')
                ->danger()
                ->body('Choose one of the supported statuses before applying changes.')
                ->send();

            return;
        }

        $updated = 0;

        foreach ($sessions as $session) {
            if ($session instanceof ClassSession) {
                $this->applyStatusToSession($session, $normalized);
                $updated++;
            }
        }

        if ($updated > 0) {
            Notification::make()
                ->title('Statuses updated')
                ->success()
                ->body(sprintf('%d session%s updated to %s.', $updated, $updated === 1 ? '' : 's', ucfirst($normalized)))
                ->send();
        } else {
            Notification::make()
                ->title('No sessions updated')
                ->warning()
                ->body('Could not find any class sessions to update.')
                ->send();
        }

        $this->resetTable();
    }

    private function bulkDeleteSessions(Collection $sessions): void
    {
        if (! $this->canManageSessions()) {
            abort(403);
        }

        $deleted = 0;

        foreach ($sessions as $session) {
            if ($session instanceof ClassSession) {
                $session->delete();
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Notification::make()
                ->title('Class sessions deleted')
                ->success()
                ->body(sprintf('%d session%s deleted.', $deleted, $deleted === 1 ? '' : 's'))
                ->send();
        } else {
            Notification::make()
                ->title('No sessions deleted')
                ->warning()
                ->body('Select at least one session before deleting.')
                ->send();
        }

        $this->resetTable();
    }

    private function sessionStatusOptions(): array
    {
        return [
            'scheduled' => 'Scheduled',
            'confirmed' => 'Confirmed',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];
    }

    private function normalizeStatus(?string $status): ?string
    {
        if (! is_string($status)) {
            return null;
        }

        $value = strtolower(trim($status));

        if ($value === 'canceled') {
            $value = 'cancelled';
        }

        return $value !== '' ? $value : null;
    }

    private function applyStatusToSession(ClassSession $session, string $status): void
    {
        $session->forceFill([
            'status' => $status,
            'canceled' => in_array($status, ['cancelled', 'canceled'], true),
        ])->save();
    }

    private function linkedSessionSubquery(Student $student): \Closure
    {
        return function ($sub) use ($student) {
            $sub->select(DB::raw('1'))
                ->from('class_sessions as linked_sessions')
                ->where('linked_sessions.student_id', $student->id)
                ->whereColumn('linked_sessions.session_date', 'class_sessions.session_date')
                ->whereColumn('linked_sessions.start_time', 'class_sessions.start_time')
                ->whereColumn('linked_sessions.end_time', 'class_sessions.end_time')
                ->whereColumn('linked_sessions.calendar_name', 'class_sessions.calendar_name');
        };
    }

    // Tables requires a translatable content driver hook; return null to disable.
    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }
}
