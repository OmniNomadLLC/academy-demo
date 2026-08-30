<?php

namespace App\Filament\Pages;

use App\Filament\Resources\StudentResource;
use App\Models\ClassSession;
use App\Models\Student;
use App\Support\Concerns\FormatsSessionTimes;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ClassSessionRoster extends Page
{
    use FormatsSessionTimes;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationLabel = null;
    protected static ?string $title = 'Class roster';
    protected static string $view = 'filament.pages.class-session-roster';
    protected static string $routePath = 'classes/session-roster';

    /** @var array<int, array{name: string, email: ?string, student_id: ?int, session_id: int, profile_url: ?string}> */
    public array $members = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    public function mount(): void
    {
        $user = Auth::user();

        abort_unless($user, 403);

        $role = Str::lower((string) ($user->role ?? ''));
        abort_unless(in_array($role, ['super_admin', 'admin', 'manager'], true), 403);

        $this->loadRoster();
    }

    private function loadRoster(): void
    {
        $calendarName = request()->query('calendar');
        $eventKey = $this->cleanJsonString(request()->query('event'));
        $eventTimezone = $this->cleanJsonString(request()->query('timezone'));
        $eventDuration = $this->parseDuration(request()->query('duration'));
        $date = request()->query('date');
        $start = request()->query('start');
        $sessionId = request()->query('session');
        $location = request()->query('location');

        $query = ClassSession::query()
            ->where(function ($q) {
                $q->whereNull('canceled')
                    ->orWhere('canceled', false)
                    ->orWhereIn('status', ['scheduled', 'confirmed']);
            })
            ->when($calendarName, fn ($q) => $q->where('calendar_name', $calendarName))
            ->when($location, fn ($q) => $q->where('location', $location));

        if ($eventKey) {
            $query->where(function ($q) use ($eventKey) {
                $q->whereRaw("json_extract(acuity_data, '$.datetime') = ?", [$eventKey]);
            });
        } elseif ($date && $start) {
            $query->whereDate('session_date', $date)
                ->where(function ($q) use ($start) {
                    $q->where('start_time', $start)
                        ->orWhereRaw("json_extract(acuity_data, '$.time') = ?", [$start]);
                });
        } elseif ($sessionId) {
            $query->where('id', (int) $sessionId);
        }

        /** @var Collection<int, ClassSession> $sessions */
        $sessions = $query
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->get();

        if ($sessions->isEmpty()) {
            $this->members = [];
            $this->meta = [
                'hasSessions' => false,
                'calendar' => $calendarName,
                'location' => $location,
            ];

            return;
        }

        $primary = $sessions->sortBy('start_time')->first();
        $record = (object) [
            'event_datetime' => $eventKey,
            'event_duration' => $eventDuration,
            'event_timezone' => $eventTimezone,
            'raw_start_time' => $sessions->min('start_time'),
            'raw_end_time' => $sessions->max('end_time'),
            'session_date' => $primary?->session_date,
            'location' => $location ?? $primary?->location,
        ];

        $startAt = $this->resolveEventDateTime($record, 'raw_start_time');
        $endAt = $this->resolveEventDateTime($record, 'raw_end_time');
        $userTimezone = $this->userTimezone();
        $displayStart = $startAt ? $startAt->copy()->setTimezone($userTimezone) : null;
        $displayEnd = $endAt ? $endAt->copy()->setTimezone($userTimezone) : null;

        $this->meta = [
            'hasSessions' => true,
            'calendar' => $calendarName ?? $primary?->calendar_name,
            'location' => $location ?? $primary?->location,
            'start' => $displayStart ? $displayStart->format('D, d M H:i') : null,
            'end' => $displayEnd ? $displayEnd->format('H:i') : null,
            'teacher' => $primary?->teacher_name ?? null,
        ];

        $this->members = $this->mapMembers($sessions);
    }

    /**
     * @param Collection<int, ClassSession> $sessions
     * @return array<int, array{name: string, email: ?string, student_id: ?int, session_id: int, profile_url: ?string}>
     */
    private function mapMembers(Collection $sessions): array
    {
        $studentIds = [];
        $emails = [];

        foreach ($sessions as $session) {
            if ($session->student_id) {
                $studentIds[] = (int) $session->student_id;
            }

            $email = $this->extractEmail($session);
            if ($email) {
                $emails[] = $email;
            }
        }

        $studentIds = array_values(array_unique($studentIds));
        $emails = array_values(array_unique($emails));

        $students = Student::query()
            ->when(! empty($studentIds), fn ($q) => $q->whereIn('id', $studentIds))
            ->when(! empty($emails), function ($q) use ($emails) {
                $q->orWhereIn('email_norm', $emails);
            })
            ->get();

        $studentsById = $students->keyBy('id');
        $studentsByEmail = $students->keyBy('email_norm');

        $members = [];

        foreach ($sessions as $session) {
            $email = $this->extractEmail($session);
            $key = $email ?? 'session-'.$session->id;

            $student = null;

            if ($session->student_id && $studentsById->has($session->student_id)) {
                $student = $studentsById->get($session->student_id);
            } elseif ($email && $studentsByEmail->has($email)) {
                $student = $studentsByEmail->get($email);
            }

            $name = $student ? trim(($student->first_name ?? '').' '.($student->last_name ?? '')) : $this->extractName($session);
            $name = $name !== '' ? $name : ($email ?: 'Unknown learner');

            $current = $members[$key] ?? [];

            $members[$key] = [
                'name' => $current['name'] ?? $name,
                'email' => $current['email'] ?? $email,
                'student_id' => $current['student_id'] ?? $student?->id,
                'session_id' => $current['session_id'] ?? $session->id,
                'profile_url' => $current['profile_url'] ?? ($student ? StudentResource::getUrl('view', ['record' => $student]) : null),
            ];
        }

        $members = array_values($members);

        usort($members, fn ($a, $b) => strcmp(Str::lower($a['name']), Str::lower($b['name'])));

        return $members;
    }

    private function extractEmail(ClassSession $session): ?string
    {
        $email = data_get($session->acuity_data, 'email')
            ?? data_get($session->acuity_data, 'client.email')
            ?? data_get($session->acuity_data, 'Client.email')
            ?? $session->student_email
            ?? $session->client_email;

        return is_string($email) ? Str::lower(trim($email)) : null;
    }

    private function extractName(ClassSession $session): string
    {
        $first = data_get($session->acuity_data, 'firstName')
            ?? data_get($session->acuity_data, 'client.firstName')
            ?? data_get($session->acuity_data, 'Client.firstName');
        $last = data_get($session->acuity_data, 'lastName')
            ?? data_get($session->acuity_data, 'client.lastName')
            ?? data_get($session->acuity_data, 'Client.lastName');

        $first = is_string($first) ? trim($first) : '';
        $last = is_string($last) ? trim($last) : '';

        return trim($first.' '.$last);
    }

    private function parseDuration($value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}

