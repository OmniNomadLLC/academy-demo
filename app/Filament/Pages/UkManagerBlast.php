<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Services\LocationMappingService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

class UkManagerBlast extends Page
{
    use RequiresRegionAccess;

    protected static ?string $navigationIcon = 'heroicon-o-envelope-open';
    protected static ?string $navigationLabel = 'Manager Mail Blast';
    protected static ?string $title = 'Manager Mail Blast (UK)';
    protected static ?string $navigationGroup = 'UK';
    protected static ?int $navigationSort = 4;
    protected static string $view = 'filament.pages.uk-manager-blast';
    public static string $requiredRegion = 'UK';

    public static function getNavigationGroup(): ?string
    {
        return 'UK';
    }

    public ?string $fromDate = null;
    public ?string $toDate = null;
    public ?string $calendar = null;
    public string $subject = '';
    public string $message = '';
    public string $timeframe = 'today';
    public ?int $managerId = null;
    /** @var array<int, string> */
    public array $statuses = ['absent'];
    /** @var array<int, string> */
    public array $previewEmails = [];
    public string $recipientList = '';
    public int $matchingManagerCount = 0;
    public int $matchingAttendanceCount = 0;
    public bool $showFilters = false;

    public function mount(): void
    {
        $this->refreshPreview();
    }

    public function availableUkCalendars(): array
    {
        return DB::table('class_sessions')
            ->select('calendar_name')
            ->whereRaw('LOWER(location) = ?', ['uk'])
            ->whereNotNull('calendar_name')
            ->distinct()->orderBy('calendar_name')
            ->pluck('calendar_name', 'calendar_name')->toArray();
    }

    public static function canAccess(): bool
    {
        if (! static::userHasRequiredRegion()) {
            return false;
        }

        $user = Auth::user();
        if (! $user) {
            return false;
        }

        if (! $user->hasDomainAccess('automation') && ! $user->hasDomainAccess('reporting')) {
            return false;
        }

        return parent::canAccess();
    }

    public function previewList(): array
    {
        return $this->previewEmails;
    }

    private function collectManagerEmails(): array
    {
        return $this->resolveBlastContext()['emails'];
    }

    public function send(): void
    {
        $emails = $this->previewEmails;
        if (empty($emails)) {
            $emails = $this->resolveBlastContext()['emails'];
        }
        if (empty($emails)) {
            Notification::make()->title('No recipients found')->warning()->send();
            return;
        }

        $sanitizedBody = trim(strip_tags((string) $this->message));
        if (trim($this->subject) === '' || $sanitizedBody === '') {
            Notification::make()->title('Subject and message are required')->danger()->send();
            return;
        }
        try {
            dispatch(new \App\Jobs\SendManagerBlast($emails, $this->subject, $this->message));
            Notification::make()->title('Queued blast to '.count($emails).' managers')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Failed to queue blast')->danger()->send();
        }
    }

    public function managerOptions(): array
    {
        $rows = DB::table('managers')
            ->join('students', 'students.manager_id', '=', 'managers.id')
            ->select('managers.id', 'managers.name', 'managers.email')
            ->whereNull('students.deleted_at')
            ->whereNotNull('managers.email')
            ->where(function ($query) {
                $query->whereRaw("LOWER(TRIM(COALESCE(students.location,''))) = 'uk'")
                    ->orWhere('students.in_uk', true);
            })
            ->distinct()
            ->orderBy('managers.name')
            ->get();

        return $rows->mapWithKeys(function ($row) {
            $label = trim((string) ($row->name ?? ''));
            if ($label === '') {
                $label = (string) $row->email;
            }

            return [
                (int) $row->id => sprintf('%s (%s)', $label, $row->email),
            ];
        })->toArray();
    }

    public function statusOptions(): array
    {
        return [
            'present' => 'Present',
            'absent' => 'Absent',
            'late' => 'Late',
            'cancelled' => 'Cancelled',
        ];
    }

    private function resolveDateRange(): array
    {
        $tz = config('app.timezone', 'UTC');
        $now = Carbon::now($tz);

        return match ($this->timeframe) {
            'this_week' => [
                $now->copy()->startOfWeek()->toDateString(),
                $now->copy()->endOfWeek()->toDateString(),
            ],
            'custom' => $this->customDateRange($tz),
            default => [
                $now->copy()->toDateString(),
                $now->copy()->toDateString(),
            ],
        };
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function customDateRange(string $tz): array
    {
        $from = $this->parseDate($this->fromDate, $tz);
        $to = $this->parseDate($this->toDate, $tz);

        if (! $from && ! $to) {
            return [null, null];
        }

        $from ??= $to;
        $to ??= $from;

        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private function parseDate(?string $date, string $tz): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($date), $tz)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolvedStatuses(): array
    {
        $valid = array_keys($this->statusOptions());

        $selected = collect($this->statuses)
            ->filter(fn ($status) => is_string($status) && in_array($status, $valid, true))
            ->map(fn ($status) => (string) $status)
            ->unique()
            ->values()
            ->all();

        return $selected ?: $valid;
    }

    private function resolvedManagerId(): ?int
    {
        return $this->managerId ? (int) $this->managerId : null;
    }

    public function applyFilters(): void
    {
        $this->refreshPreview();
    }

    private function refreshPreview(): void
    {
        $context = $this->resolveBlastContext();
        $this->previewEmails = $context['emails'];
        $this->recipientList = implode("\n", $context['emails']);
        $this->matchingManagerCount = $context['manager_count'];
        $this->matchingAttendanceCount = $context['attendance_count'];
    }

    private function applyUkScope($query, string $studentAlias = 'students'): void
    {
        $keywords = LocationMappingService::keywordsForRegion('UK');

        $query->where(function ($w) use ($studentAlias, $keywords) {
            $studentLocationExpr = "LOWER(TRIM(COALESCE({$studentAlias}.location,'')))";
            $classLocationExpr = "LOWER(TRIM(COALESCE(cs.location,'')))";
            $categoryNormExpr = "LOWER(TRIM(COALESCE(cs.category_norm,'')))";

            $w->whereRaw("{$studentLocationExpr} = 'uk'")
                ->orWhere("{$studentAlias}.in_uk", true)
                ->orWhereRaw("{$classLocationExpr} = 'uk'");

            if (! empty($keywords)) {
                $w->orWhere(function ($qq) use ($keywords, $categoryNormExpr) {
                    foreach ($keywords as $kw) {
                        $qq->orWhereRaw("{$categoryNormExpr} like ?", ['%'.$kw.'%']);
                    }
                });
            }
        });
    }

    /**
     * @return array{emails: array<int,string>, manager_count: int, attendance_count: int}
     */
    private function resolveBlastContext(): array
    {
        $statuses = $this->resolvedStatuses();
        if (empty($statuses)) {
            return [
                'emails' => [],
                'manager_count' => 0,
                'attendance_count' => 0,
            ];
        }

        $query = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'ar.class_session_id', '=', 'cs.id')
            ->join('students', 'ar.student_id', '=', 'students.id')
            ->join('managers', 'students.manager_id', '=', 'managers.id')
            ->whereNull('students.deleted_at')
            ->whereNotNull('managers.email')
            ->whereIn('ar.status', $statuses);

        $this->applyUkScope($query);

        [$start, $end] = $this->resolveDateRange();
        if ($start && $end) {
            $query->whereBetween('cs.session_date', [$start, $end]);
        }

        if (is_string($this->calendar) && trim((string) $this->calendar) !== '') {
            $query->where('cs.calendar_name', 'like', '%' . trim((string) $this->calendar) . '%');
        }

        $managerId = $this->resolvedManagerId();
        if ($managerId) {
            $query->where('managers.id', $managerId);
        }

        $managerCount = (clone $query)->distinct('managers.id')->count('managers.id');
        $attendanceCount = (clone $query)->count();

        $emails = (clone $query)
            ->distinct()
            ->limit(500)
            ->pluck('managers.email')
            ->filter(static fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email) => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();

        return [
            'emails' => $emails,
            'manager_count' => $managerCount,
            'attendance_count' => $attendanceCount,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allManagerEmails(): array
    {
        return DB::table('managers')
            ->whereNotNull('email')
            ->pluck('email')
            ->filter(static fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email) => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();
    }

    public function useAllManagers(): void
    {
        $emails = $this->allManagerEmails();

        $this->previewEmails = $emails;
        $this->recipientList = implode("\n", $emails);
        $this->matchingManagerCount = count($emails);
        $this->matchingAttendanceCount = 0;
    }

    public function toggleFilters(): void
    {
        $this->showFilters = ! $this->showFilters;
    }
}
