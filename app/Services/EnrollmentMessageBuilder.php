<?php

namespace App\Services;

use App\Data\EnrollmentMessageData;
use App\Exceptions\EnrollmentMessageException;
use App\Models\ClassLocation;
use App\Models\ClassSession;
use App\Models\Student;
use App\Support\DbExpressions;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EnrollmentMessageBuilder
{
    /**
     * @throws EnrollmentMessageException
     */
    public function build(Student $student): EnrollmentMessageData
    {
        $sessions = $this->upcomingSessions($student);

        if ($sessions->isEmpty()) {
            throw new EnrollmentMessageException('No upcoming sessions found for this student in the next six weeks.');
        }

        /** @var ClassSession $firstSession */
        $firstSession = $sessions->first();

        $location = $this->resolveLocation($student, $firstSession, $sessions);

        if (! $location) {
            throw new EnrollmentMessageException('Unable to determine the class location. Please add the venue in Class Locations.');
        }

        $firstDate = CarbonImmutable::parse($firstSession->session_date, $this->appTimezone());
        $startTime = $this->formatTime($firstSession->start_time);
        $endTime = $this->formatTime($firstSession->end_time);

        if (! $startTime || ! $endTime) {
            throw new EnrollmentMessageException('Unable to determine session start and end times.');
        }

        $weekdayList = $this->formatWeekdayList($sessions);

        $firstName = trim($student->first_name ?: $student->full_name ?? 'there');
        if ($firstName === '') {
            $firstName = 'there';
        }

        $firstUpcomingDow = $firstDate->isoFormat('dddd');
        $firstUpcomingDayOrdinal = $this->ordinal($firstDate->day);
        $firstUpcomingMonth = $firstDate->isoFormat('MMMM');
        $isVirtual = $this->isVirtualDelivery($student, $firstSession, $location);

        if (! $isVirtual && (! $location->address_line_1 || ! $location->city || ! $location->postcode)) {
            throw new EnrollmentMessageException('Class location address is incomplete. Please provide address line 1, city, and postcode.');
        }

        $reference = $this->generateReference($student, $firstSession);
        $preheader = sprintf(
            'Starts %s %s at %s',
            $firstDate->isoFormat('ddd'),
            $firstDate->isoFormat('DD MMM'),
            $startTime
        );

        $commonContext = [
            'weekday_list' => $weekdayList,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'first_session_date' => $firstDate->toDateString(),
            'location_slug' => $location->slug,
            'is_virtual' => $isVirtual,
            'reference' => $reference,
            'preheader' => $preheader,
        ];

        if ($isVirtual) {
            $messageSet = $this->buildVirtualMessage(
                $student,
                $firstSession,
                $location,
                $sessions,
                $firstName,
                $firstUpcomingDow,
                $firstUpcomingDayOrdinal,
                $firstUpcomingMonth,
                $startTime,
                $endTime,
                $weekdayList,
                $firstDate
            );
        } else {
            $messageSet = $this->buildPhysicalMessage(
                $student,
                $location,
                $firstName,
                $firstUpcomingDow,
                $firstUpcomingDayOrdinal,
                $firstUpcomingMonth,
                $startTime,
                $endTime,
                $weekdayList
            );
        }

        $context = array_merge($commonContext, $messageSet['context']);

        return new EnrollmentMessageData(
            $student,
            $firstSession,
            $location,
            $messageSet['whatsapp'],
            $messageSet['sms'],
            $messageSet['email_text'],
            $messageSet['email_html'],
            context: $context
        );
    }

    /**
     * Fetch upcoming sessions for a student within the next six weeks.
     *
     * @return Collection<int, ClassSession>
     */
    protected function upcomingSessions(Student $student): Collection
    {
        $today = Carbon::today($this->appTimezone());
        $limitDate = $today->copy()->addWeeks(6);

        $clientIdExpr = "COALESCE(\n            json_extract(class_sessions.acuity_data, '$.clientID'),\n            json_extract(class_sessions.acuity_data, '$.clientId'),\n            json_extract(class_sessions.acuity_data, '$.client.id'),\n            json_extract(class_sessions.acuity_data, '$.client_id'),\n            json_extract(class_sessions.acuity_data, '$.ClientID'),\n            json_extract(class_sessions.acuity_data, '$.ClientId'),\n            json_extract(class_sessions.acuity_data, '$.Client.id'),\n            json_extract(class_sessions.acuity_data, '$.Client_id')\n        )";

        $emailExpr = "LOWER(COALESCE(\n            json_extract(class_sessions.acuity_data, '$.email'),\n            json_extract(class_sessions.acuity_data, '$.client.email'),\n            json_extract(class_sessions.acuity_data, '$.Client.email')\n        ))";
        $castClientIdExpr = DbExpressions::castToString($clientIdExpr);
        $castParamExpr = DbExpressions::castToString('?');

        return ClassSession::query()
            ->select('class_sessions.*')
            ->whereBetween('class_sessions.session_date', [$today->toDateString(), $limitDate->toDateString()])
            ->where(function ($query) use ($student, $emailExpr, $castClientIdExpr, $castParamExpr) {
                $query->where('class_sessions.student_id', $student->id);

                if (! empty($student->acuity_client_id)) {
                    $query->orWhereRaw("{$castClientIdExpr} = {$castParamExpr}", [(string) $student->acuity_client_id]);
                }

                if (! empty($student->email)) {
                    $emailLower = strtolower(trim((string) $student->email));
                    $query->orWhereRaw("{$emailExpr} = LOWER(?)", [$emailLower]);

                    if (Schema::hasColumn('class_sessions', 'student_email')) {
                        $query->orWhereRaw('LOWER(class_sessions.student_email) = LOWER(?)', [$emailLower]);
                    }

                    if (Schema::hasColumn('class_sessions', 'client_email')) {
                        $query->orWhereRaw('LOWER(class_sessions.client_email) = LOWER(?)', [$emailLower]);
                    }
                }
            })
            ->where(function ($query) {
                $query->where('class_sessions.canceled', false)
                    ->orWhereNull('class_sessions.canceled')
                    ->orWhereIn('class_sessions.status', ['scheduled', 'confirmed']);
            })
            ->orderBy('class_sessions.session_date')
            ->orderBy('class_sessions.start_time')
            ->limit(40)
            ->get();
    }

    protected function resolveLocation(Student $student, ClassSession $firstSession, Collection $sessions): ?ClassLocation
    {
        $candidates = array_filter([
            $firstSession->location,
            $firstSession->calendar_name,
            $firstSession->calendar_norm,
            $student->location,
            $student->acuity_category,
        ]);

        $additional = $sessions->map(function (ClassSession $session) {
            return Arr::get($session->acuity_data ?? [], 'location');
        })->filter()->all();

        $candidates = array_merge($candidates, $additional);

        foreach ($candidates as $candidate) {
            $match = ClassLocation::for((string) $candidate);
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    protected function formatWeekdayList(Collection $sessions): string
    {
        $days = $sessions
            ->map(function (ClassSession $session) {
                return Carbon::parse($session->session_date, $this->appTimezone())->isoFormat('dddd');
            })
            ->unique()
            ->values()
            ->all();

        $order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        usort($days, static function ($a, $b) use ($order) {
            return array_search($a, $order, true) <=> array_search($b, $order, true);
        });

        $count = count($days);

        if ($count === 0) {
            return 'week';
        }

        if ($count === 1) {
            return $days[0];
        }

        if ($count === 2) {
            return implode(' and ', $days);
        }

        $last = array_pop($days);
        return implode(', ', $days) . ', and ' . $last;
    }

    protected function formatTime(?string $time): ?string
    {
        if (! $time) {
            return null;
        }

        $formats = ['H:i:s', 'H:i'];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $time, $this->appTimezone());
                return $parsed->format('g:i A');
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    protected function ordinal(int $day): string
    {
        $suffixes = ['th', 'st', 'nd', 'rd'];
        $mod100 = $day % 100;
        if ($mod100 >= 11 && $mod100 <= 13) {
            return $day . 'th';
        }

        $mod10 = $day % 10;
        return $day . ($suffixes[$mod10] ?? 'th');
    }

    protected function addressLines(ClassLocation $location): array
    {
        $lines = [
            $location->name,
            $location->address_line_1 ? $location->address_line_1 . ',' : null,
        ];

        if ($location->address_line_2) {
            $lines[] = $location->address_line_2 . ',';
        }

        $cityPostcode = trim(collect([$location->city, $location->postcode])->filter()->implode(' '));
        if ($cityPostcode !== '') {
            $lines[] = $cityPostcode;
        }

        return array_values(array_filter($lines, fn ($value) => $value !== null));
    }

    protected function appTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }

    protected function isVirtualDelivery(Student $student, ClassSession $session, ClassLocation $location): bool
    {
        $studentOnline = property_exists($student, 'is_online') && (bool) $student->is_online;

        return (bool) ($location->is_virtual ?? false)
            || (bool) ($session->is_virtual ?? false)
            || $studentOnline;
    }

    protected function buildPhysicalMessage(
        Student $student,
        ClassLocation $location,
        string $firstName,
        string $firstUpcomingDow,
        string $firstUpcomingDayOrdinal,
        string $firstUpcomingMonth,
        string $startTime,
        string $endTime,
        string $weekdayList
    ): array {
        $locationLines = $this->addressLines($location);

        $addressBlock = implode("\n", array_filter([
            $location->name,
            $location->address_line_1,
            $location->address_line_2,
            trim(collect([$location->city, $location->postcode])->filter()->implode(' ')),
        ]));

        $paragraphs = [
            "Hello {$firstName},",
            sprintf(
                'You have been enrolled with us to attend your ESOL English classes, starting on %s, %s %s, from %s to %s. An email with all the dates and times has been sent to you.',
                $firstUpcomingDow,
                $firstUpcomingDayOrdinal,
                $firstUpcomingMonth,
                $startTime,
                $endTime
            ),
            sprintf(
                'Your classes will take place every %s from %s to %s at the following location:',
                $weekdayList,
                $startTime,
                $endTime
            ),
            $addressBlock,
            'Please report to reception and ask for the English classes.',
            'Make sure to attend all of your classes, or your advisor will be contacted.',
            'Please confirm that you have received and understood this message and that you will attend.',
            'Looking forward to having you on board!',
            'Many thanks,',
        ];

        $body = implode("\n\n", $paragraphs);

        $sms = sprintf(
            "Lumina: You're enrolled for ESOL. Starts %s %s %s-%s at %s. Full details sent by email. Reply YES to confirm.",
            $firstUpcomingDow,
            $firstUpcomingDayOrdinal . ' ' . $firstUpcomingMonth,
            $startTime,
            $endTime,
            $location->name
        );

        if (strlen($sms) > 160) {
            $sms = sprintf(
                "Lumina: You're enrolled for ESOL. Starts %s %s %s-%s at %s. See email for details.",
                $firstUpcomingDow,
                $firstUpcomingDayOrdinal . ' ' . $firstUpcomingMonth,
                $startTime,
                $endTime,
                Str::limit($location->name, 24, '')
            );
        }

        return [
            'whatsapp' => $body,
            'sms' => $sms,
            'email_text' => $body,
            'email_html' => $this->paragraphHtml($paragraphs),
            'context' => [],
        ];
    }

    protected function buildVirtualMessage(
        Student $student,
        ClassSession $firstSession,
        ClassLocation $location,
        Collection $sessions,
        string $firstName,
        string $firstUpcomingDow,
        string $firstUpcomingDayOrdinal,
        string $firstUpcomingMonth,
        string $startTime,
        string $endTime,
        string $weekdayList,
        CarbonImmutable $firstDate
    ): array {
        $teacherName = optional($firstSession->teacher)->name;
        $calendarName = trim((string) ($firstSession->calendar_name ?? $location->name ?? 'your class'));
        $teacherSnippet = $teacherName ? " with {$teacherName}" : '';

        $meetingUrl = $location->virtual_meeting_url ?: $firstSession->virtual_meeting_url;
        $meetingRoom = $location->virtual_meeting_room ?: $firstSession->virtual_meeting_room;

        $weeks = $this->calculateCourseWeeks($firstDate, $sessions);
        $weekLabel = $weeks === 1 ? 'week' : 'weeks';

        $accessSentences = [];
        if ($meetingUrl) {
            $accessSentences[] = "You can join using this Zoom link for every class with the teacher {$calendarName}: {$meetingUrl}.";
        } else {
            $accessSentences[] = 'You will receive a Zoom link by email ahead of your first class.';
        }

        if ($meetingRoom) {
            $accessSentences[] = "Alternatively, select \"Join a meeting\" and enter the meeting ID: {$meetingRoom}.";
        }

        $downloadSentence = 'Please use Zoom on your tablet. Download it here: https://zoom.us/download.';

        $paragraphs = array_filter([
            "Hello {$firstName},",
            sprintf(
                'You have now been enrolled to attend our online English classes on Zoom, starting %s, %s %s, from %s to %s.',
                $firstUpcomingDow,
                $firstUpcomingDayOrdinal,
                $firstUpcomingMonth,
                $startTime,
                $endTime
            ),
            sprintf(
                'From then on you will join every %s from %s to %s for the next %d %s.',
                $weekdayList,
                $startTime,
                $endTime,
                $weeks,
                $weekLabel
            ),
            implode(' ', $accessSentences),
            $downloadSentence,
            'Make sure to attend all of your classes, or your advisor will be contacted.',
            'Please confirm that you have received and understood this message and that you will attend.',
            'Looking forward to having you on board!',
            'Many thanks,',
        ]);

        $body = implode("\n\n", $paragraphs);

        $smsParts = [
            "Lumina: Online ESOL via Zoom.",
            sprintf('Starts %s %s %s-%s.', $firstUpcomingDow, $firstUpcomingDayOrdinal . ' ' . $firstUpcomingMonth, $startTime, $endTime),
            $meetingRoom ? "ID {$meetingRoom}." : null,
            $meetingUrl ? 'Link in email.' : 'Link emailed soon.',
            'Reply YES to confirm.',
        ];

        $sms = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($smsParts))));

        if (strlen($sms) > 160) {
            $sms = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
                "Lumina: Online ESOL via Zoom.",
                sprintf('Starts %s %s.', $firstUpcomingDow, $firstUpcomingDayOrdinal . ' ' . $firstUpcomingMonth),
                $meetingRoom ? "ID {$meetingRoom}." : null,
                'Reply YES to confirm.',
            ]))));
        }

        return [
            'whatsapp' => $body,
            'sms' => $sms,
            'email_text' => $body,
            'email_html' => $this->paragraphHtml($paragraphs),
            'context' => array_filter([
                'meeting_url' => $meetingUrl,
                'meeting_room' => $meetingRoom,
                'teacher_name' => $teacherName,
                'calendar_name' => $calendarName,
            ]),
        ];
    }

    protected function calculateCourseWeeks(CarbonImmutable $firstDate, Collection $sessions): int
    {
        $last = $sessions->last();
        if (! $last) {
            return 1;
        }

        $lastDate = CarbonImmutable::parse($last->session_date, $this->appTimezone());
        $diffWeeks = $firstDate->diffInWeeks($lastDate);

        return max(1, $diffWeeks + 1);
    }

    protected function paragraphHtml(array $paragraphs): string
    {
        return collect($paragraphs)
            ->map(fn ($paragraph) => trim((string) $paragraph))
            ->filter(fn ($paragraph) => $paragraph !== '')
            ->map(function ($paragraph) {
                $escaped = e($paragraph);
                $escaped = str_replace(["\r\n", "\n", "\r"], '<br>', $escaped);
                return '<p>' . $escaped . '</p>';
            })
            ->implode("\n");
    }

    protected function generateReference(Student $student, ClassSession $session): string
    {
        return sprintf(
            'ENR-%d-%s',
            $student->id,
            Str::upper(Str::random(5))
        );
    }
}
