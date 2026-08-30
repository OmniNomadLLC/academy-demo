<?php

namespace App\Filament\Resources\UKUpcomingResource\Pages;

use App\Filament\Resources\UKUpcomingResource;
use App\Models\ClassSession;
use App\Models\User;
use App\Support\Concerns\InterpretsAcuityFields;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ManageUpcomingClass extends Page implements HasForms
{
    use InteractsWithForms;
    use InterpretsAcuityFields;

    protected static string $resource = UKUpcomingResource::class;

    protected static string $view = 'filament.resources.uk-upcoming-resource.pages.manage-upcoming-class';

    public ClassSession $record;

    public array $sessionSummary = [];

    public array $students = [];

    public ?int $assignedTeacherId = null;

    public ?int $currentTeacherId = null;

    public array $teacherOptions = [];

    public ?int $cover_teacher_id = null;

    public function mount(ClassSession $record): void
    {
        $this->record = $record;
        $this->authorizeAccess();

        $this->teacherOptions = $this->loadTeacherOptions();

        $this->loadGroup();

        $this->form->fill([
            'cover_teacher_id' => $this->currentTeacherId,
        ]);
    }

    protected function authorizeAccess(): void
    {
        $user = Auth::user();

        if (! $user) {
            abort(403);
        }

        if (! $user->hasRole('admin', 'super_admin', 'manager')) {
            abort(403);
        }
    }

    protected function loadTeacherOptions(): array
    {
        return User::query()
            ->whereIn('role', User::TEACHING_ROLES)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $teacher) => [$teacher->id => trim($teacher->name ?: $teacher->email)])
            ->toArray();
    }

    protected function loadGroup(): void
    {
        $calendarKey = $this->normalizedCalendarKey($this->record);
        $groupQuery = ClassSession::query()
            ->where('session_date', $this->record->session_date)
            ->where('start_time', $this->record->start_time);

        if ($this->record->end_time) {
            $groupQuery->where('end_time', $this->record->end_time);
        }

        $record = $this->record;
        $groupQuery->where(function ($query) use ($calendarKey, $record) {
            $matched = false;

            if ($calendarKey) {
                $query->orWhereRaw($this->calendarExpr().' = ?', [$calendarKey]);
                $matched = true;
            }

            if ($record->calendar_name) {
                $query->orWhere('calendar_name', $record->calendar_name);
                $matched = true;
            }

            if ($record->calendar_norm) {
                $query->orWhere('calendar_norm', strtolower(trim($record->calendar_norm)));
                $matched = true;
            }

            if (! $matched) {
                $query->orWhereNotNull('calendar_name');
            }
        });

        $sessions = $groupQuery
            ->with(['assignedTeacher','teacher'])
            ->get();

        if ($sessions->isEmpty()) {
            $sessions = collect([$this->record->loadMissing(['assignedTeacher','teacher'])]);
        }

        $first = $sessions->first();
        $this->assignedTeacherId = $first?->assigned_teacher_id ?? $first?->teacher_id;
        $this->currentTeacherId = $first?->cover_teacher_id ?? $first?->teacher_id;

        if ($this->assignedTeacherId && ! isset($this->teacherOptions[$this->assignedTeacherId])) {
            $this->teacherOptions[$this->assignedTeacherId] = $sessions->first()?->assignedTeacher?->name
                ?? $sessions->first()?->teacher?->name
                ?? 'Teacher #'.$this->assignedTeacherId;
        }

        if ($this->currentTeacherId && ! isset($this->teacherOptions[$this->currentTeacherId])) {
            $this->teacherOptions[$this->currentTeacherId] = $sessions->first()?->teacher?->name
                ?? 'Teacher #'.$this->currentTeacherId;
        }

        $appointmentLabel = $this->resolveAppointmentLabel($sessions->first());

        $this->sessionSummary = [
            'date' => $this->record->session_date?->format('d-m-Y') ?? $this->record->session_date,
            'start' => $this->record->start_time,
            'end' => $this->record->end_time,
            'calendar' => Str::title($this->normalizedCalendarKey($this->record) ?? ($this->record->calendar_name ?? '')),
            'appointment' => $appointmentLabel,
            'location' => $this->record->location,
            'assigned' => $this->assignedTeacherId ? ($this->teacherOptions[$this->assignedTeacherId] ?? 'Teacher #'.$this->assignedTeacherId) : '—',
            'current' => $this->currentTeacherId ? ($this->teacherOptions[$this->currentTeacherId] ?? 'Teacher #'.$this->currentTeacherId) : '—',
            'count' => $sessions->count(),
        ];

        $this->students = $sessions->map(function (ClassSession $session) {
            $data = $session->acuity_data ?? [];
            $first = trim((string) ($data['firstName'] ?? data_get($data, 'client.firstName') ?? ''));
            $last = trim((string) ($data['lastName'] ?? data_get($data, 'client.lastName') ?? ''));
            $email = trim((string) ($data['email'] ?? data_get($data, 'client.email') ?? ''));

            $name = trim(($first.' '.$last)) ?: $email ?: 'Unknown attendee';

            return [
                'id' => $session->id,
                'name' => $name,
                'status' => $session->status,
            ];
        })->sortBy('name')->values()->toArray();
    }

    protected function normalizedCalendarKey(ClassSession $session): ?string
    {
        if ($session->calendar_norm) {
            return strtolower(trim($session->calendar_norm));
        }

        $data = $session->acuity_data ?? [];
        $candidates = [
            $data['calendar'] ?? null,
            $data['calendarName'] ?? null,
            data_get($data, 'calendar.name'),
            $data['Calendar'] ?? null,
            $data['CalendarName'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return null;
    }

    protected function extractAppointmentTypeId(ClassSession $session): ?string
    {
        $data = $session->acuity_data ?? [];
        $candidates = [
            $data['appointmentTypeID'] ?? null,
            $data['appointmentTypeId'] ?? null,
            data_get($data, 'appointmentType.id'),
            $data['typeID'] ?? null,
            $data['TypeID'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    protected function resolveAppointmentLabel(?ClassSession $session): string
    {
        if (! $session) {
            return 'Class';
        }

        $data = $session->acuity_data ?? [];
        $candidates = [
            $data['appointmentType'] ?? null,
            data_get($data, 'appointmentType.name'),
            $data['type'] ?? null,
            $data['Type'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $typeId = $this->extractAppointmentTypeId($session);

        return $typeId ?: 'Class';
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Select::make('cover_teacher_id')
                ->label('Teacher covering this class')
                ->options($this->teacherOptions)
                ->searchable()
                ->required()
                ->helperText('Selecting a different teacher only affects this class instance.'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $targetTeacherId = (int) ($data['cover_teacher_id'] ?? 0);

        if (! $targetTeacherId) {
            Notification::make()->title('Select a teacher')->danger()->send();
            return;
        }

        $sessions = $this->fetchSessionGroup();

        if ($sessions->isEmpty()) {
            Notification::make()->title('Unable to locate class sessions')->danger()->send();
            return;
        }

        $assigned = $sessions->first()->assigned_teacher_id ?? $sessions->first()->teacher_id;
        $sessionIds = $sessions->pluck('id');

        if ($targetTeacherId === (int) $assigned) {
            ClassSession::query()
                ->whereIn('id', $sessionIds)
                ->update([
                    'teacher_id' => $assigned,
                    'cover_teacher_id' => null,
                ]);
        } else {
            ClassSession::query()
                ->whereIn('id', $sessionIds)
                ->update([
                    'assigned_teacher_id' => $assigned,
                    'cover_teacher_id' => $targetTeacherId,
                    'teacher_id' => $targetTeacherId,
                ]);
        }

        Notification::make()
            ->title('Class assignment updated')
            ->success()
            ->send();

        $this->loadGroup();
    }

    protected function fetchSessionGroup(): Collection
    {
        $calendarKey = $this->normalizedCalendarKey($this->record);
        $typeId = $this->extractAppointmentTypeId($this->record);

        $query = ClassSession::query()
            ->where('session_date', $this->record->session_date)
            ->where('start_time', $this->record->start_time);

        if ($this->record->end_time) {
            $query->where('end_time', $this->record->end_time);
        }

        $query->where(function ($builder) use ($calendarKey) {
            $record = $this->record;

            if ($calendarKey) {
                $builder->orWhereRaw($this->calendarExpr().' = ?', [$calendarKey]);
            }

            if ($record->calendar_name) {
                $builder->orWhere('calendar_name', $record->calendar_name);
            }

            if ($record->calendar_norm) {
                $builder->orWhere('calendar_norm', strtolower(trim($record->calendar_norm)));
            }
        });

        if ($typeId) {
            $query->whereRaw($this->appointmentTypeIdExpr().' = ?', [$typeId]);
        }

        $sessions = $query->get();

        if ($sessions->isEmpty()) {
            $sessions = collect([$this->record]);
        }

        return $sessions;
    }
}
