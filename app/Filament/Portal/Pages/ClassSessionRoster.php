<?php

namespace App\Filament\Portal\Pages;

use App\Models\ClassSession;
use App\Models\Student;
use App\Support\Concerns\FormatsSessionTimes;
use App\Support\TeacherRoster;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ClassSessionRoster extends Page
{
    use FormatsSessionTimes;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationLabel = null;
    protected static ?string $title = 'Class roster';
    protected static string $view = 'filament.portal.pages.class-session-roster';
    protected static string $routePath = 'classes/session-roster';

    public array $members = [];
    public array $meta = [];

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $role = strtolower((string) ($user->role ?? ''));
        abort_unless($user->isTeachingRole() || in_array($role, ['admin', 'super_admin'], true), 403);

        $this->loadRoster();
    }

    private function loadRoster(): void
    {
        $user = Auth::user();
        $calendarName = request()->query('calendar');
        $eventKey = $this->cleanJsonString(request()->query('event'));
        $eventTimezone = $this->cleanJsonString(request()->query('timezone'));
        $eventDuration = $this->parseDuration(request()->query('duration'));
        $date = request()->query('date');
        $start = request()->query('start');
        $sessionId = request()->query('session');
        $location = request()->query('location');

        $builder = TeacherRoster::sessions($user)->cloneWithout(['orders', 'limit', 'offset']);

        if ($calendarName) {
            $builder->where('calendar_name', $calendarName);
        }

        if ($eventKey) {
            $builder->where(function ($q) use ($eventKey) {
                $q->whereRaw("json_extract(acuity_data, '$.datetime') = ?", [$eventKey]);
            });
        } elseif ($date && $start) {
            $builder->whereDate('session_date', $date)
                ->where(function ($q) use ($start) {
                    $q->where('start_time', $start)
                        ->orWhereRaw("json_extract(acuity_data, '$.time') = ?", [$start]);
                });
        } elseif ($sessionId) {
            $builder->where('id', (int) $sessionId);
        }

        $sessions = $builder->get();

        if ($sessions->isEmpty()) {
            $this->members = [];
            $this->meta = [
                'hasSessions' => false,
                'calendar' => $calendarName,
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
            'session_date' => $primary->session_date,
            'location' => $location ?? $primary->location,
        ];

        $startAt = $this->resolveEventDateTime($record, 'raw_start_time');
        $endAt = $this->resolveEventDateTime($record, 'raw_end_time');

        $userTimezone = $this->userTimezone();
        $displayStart = $startAt ? $startAt->copy()->setTimezone($userTimezone) : null;
        $displayEnd = $endAt ? $endAt->copy()->setTimezone($userTimezone) : null;

        $this->meta = [
            'hasSessions' => true,
            'calendar' => $calendarName ?? $primary->calendar_name,
            'location' => $location ?? $primary->location,
            'start' => $displayStart ? $displayStart->format('D, d M H:i') : null,
            'end' => $displayEnd ? $displayEnd->format('H:i') : null,
            'teacher' => Auth::user()?->name,
        ];

        $this->members = $this->mapMembers($sessions);
    }

    private function mapMembers($sessions): array
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
            ];

            if (! isset($members[$key]['profile_url']) && $student) {
                $members[$key]['profile_url'] = route('filament.portal.pages.student-profile', [
                    'record' => $student,
                    'session' => $session->id,
                ]);
            }

            if (! array_key_exists('profile_url', $members[$key])) {
                $members[$key]['profile_url'] = $student ? route('filament.portal.pages.student-profile', [
                    'record' => $student,
                    'session' => $session->id,
                ]) : null;
            }
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
