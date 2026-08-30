<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Calendar\TeacherCalendarEventFactory;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Support\TeacherRoster;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CalendarEventsController extends Controller
{
    public function __construct(private readonly TeacherCalendarEventFactory $eventFactory)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = Filament::auth()?->user() ?? Auth::user();

        if (! $user || (! $user->isTeachingRole() && ! $user->hasRole('admin', 'super_admin'))) {
            abort(403);
        }

        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);

        $teacherTimezone = $this->resolveTeacherTimezone($user);

        $rangeStart = CarbonImmutable::parse($validated['start'], $teacherTimezone->getName())->setTimezone('UTC');
        $rangeEnd = CarbonImmutable::parse($validated['end'], $teacherTimezone->getName())->setTimezone('UTC');

        $sessions = TeacherRoster::sessions($user)
            ->cloneWithout(['orders', 'limit', 'offset'])
            ->with([
                'schoolClass:id,name',
                'classLocation:id,name',
                'attendanceRecords' => fn ($query) => $query
                    ->select('id', 'class_session_id', 'student_id')
                    ->with(['student:id,first_name,last_name,email']),
            ])
            ->whereBetween('session_date', [
                $rangeStart->copy()->subDays(1)->toDateString(),
                $rangeEnd->copy()->addDays(1)->toDateString(),
            ])
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->get();

        $events = $sessions->map(function (ClassSession $session) use ($teacherTimezone, $rangeStart, $rangeEnd) {
            $payload = $this->buildPayload($session);
            $event = $this->eventFactory->make($payload, $teacherTimezone, $session->location);

            if (! $event) {
                return null;
            }

            if ($event->endUtc->lte($rangeStart) || $event->startUtc->gte($rangeEnd)) {
                return null;
            }

            $studentName = $this->resolveStudentName($session, $payload);
            $title = $studentName ?: ($session->schoolClass?->name ?? 'Lesson');
            $startLabel = $event->startLocal->format('D, d M Y · H:i');
            $endLabel = $event->endLocal->format('H:i');

            return [
                'id' => $session->id,
                'title' => $title,
                'start' => $event->startLocal->toIso8601String(),
                'end' => $event->endLocal->toIso8601String(),
                'extendedProps' => [
                    'student' => $studentName,
                    'location' => $session->classLocation?->name ?? $session->location,
                    'calendar' => $session->calendar_name,
                    'status' => $session->status,
                    'start_label' => $startLabel,
                    'end_label' => $endLabel,
                    'notes' => $session->notes,
                    'raw_tz' => $event->meta['acuity_timezone'] ?? null,
                    'roster_url' => route('filament.portal.pages.class-session-roster', ['session' => $session->id]),
                ],
            ];
        })->filter()->values();

        Log::info('Portal calendar events', [
            'user_id' => $user->id,
            'role' => $user->role,
            'range' => [$rangeStart->toIso8601String(), $rangeEnd->toIso8601String()],
            'count' => $events->count(),
        ]);

        return response()->json($events);
    }

    private function resolveTeacherTimezone($user): DateTimeZone
    {
        $candidates = [
            is_string($user->timezone ?? null) ? trim($user->timezone) : null,
            config('app.timezone'),
            'UTC',
        ];

        foreach ($candidates as $candidate) {
            if (! $candidate) {
                continue;
            }

            try {
                return new DateTimeZone($candidate);
            } catch (\Throwable $e) {
                continue;
            }
        }

        return new DateTimeZone('UTC');
    }

    private function buildPayload(ClassSession $session): array
    {
        $payload = $session->acuity_data;

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($payload)) {
            $payload = [];
        }

        $payload['session_date'] ??= $session->session_date?->toDateString();
        $payload['start_time'] ??= $session->start_time;
        $payload['end_time'] ??= $session->end_time;
        $payload['duration_minutes'] ??= $this->sessionDurationMinutes($session);
        $payload['location'] ??= $session->location;

        return $payload;
    }

    private function sessionDurationMinutes(ClassSession $session): ?int
    {
        if (! $session->session_date || ! $session->start_time || ! $session->end_time) {
            return null;
        }

        try {
            $date = $session->session_date instanceof \DateTimeInterface
                ? CarbonImmutable::instance($session->session_date)->toDateString()
                : (string) $session->session_date;
            $baseTz = config('services.acuity.timezone') ?? config('app.timezone') ?? 'UTC';
            $start = CarbonImmutable::parse("{$date} {$session->start_time}", $baseTz);
            $end = CarbonImmutable::parse("{$date} {$session->end_time}", $baseTz);
            $diff = (int) $start->diffInMinutes($end, false);

            return $diff > 0 ? $diff : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveStudentName(ClassSession $session, array $payload): ?string
    {
        $records = $session->attendanceRecords ?? collect();
        foreach ($records as $record) {
            if (! $record->relationLoaded('student')) {
                $record->loadMissing('student:id,first_name,last_name,email');
            }
            $student = $record->student;
            if ($student) {
                $name = trim(trim((string) ($student->first_name ?? '')).' '.trim((string) ($student->last_name ?? '')));
                if ($name !== '') {
                    return preg_replace('/\s+/', ' ', $name);
                }
            }
        }

        $candidates = [
            trim((string) (data_get($payload, 'client.firstName').' '.data_get($payload, 'client.lastName'))),
            data_get($payload, 'client.name'),
            trim((string) (data_get($payload, 'firstName').' '.data_get($payload, 'lastName'))),
            trim((string) (data_get($payload, 'Client.firstName').' '.data_get($payload, 'Client.lastName'))),
            data_get($payload, 'name'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $clean = trim(preg_replace('/\s+/', ' ', $candidate));
            if ($clean !== '') {
                return $clean;
            }
        }

        return null;
    }
}
