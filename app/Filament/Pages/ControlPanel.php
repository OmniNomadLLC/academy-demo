<?php

namespace App\Filament\Pages;

use App\Filament\Resources\TeacherAppointmentTypeAssignmentResource;
use App\Filament\Resources\UserResource;
use App\Jobs\ProcessAcuityImportRun;
use App\Jobs\RunArtisanCommand;
use App\Jobs\SyncAcuityAppointment;
use App\Jobs\SyncAcuityClient;
use App\Models\AcuityImportRun;
use App\Models\SyncLog;
use App\Models\TeacherAppointmentTypeAssignment;
use App\Models\User;
use App\Support\QueueStatusResolver;
use App\Support\TeacherAppointmentTypeAllocator;
use App\Support\TeacherAppointmentTypeCatalogBuilder;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\DB as DBFacade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;

class ControlPanel extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Control Panel';
    protected static ?string $title = 'Control Panel';
    protected static ?string $navigationGroup = 'System';
    protected static string $view = 'filament.pages.control-panel';

    public const GUIDE_LAST_UPDATED = '2025-10-09T09:00:00Z';

    public string $deltaSince = '-7 days';
    public ?string $deltaFrom = null;
    public ?string $deltaTo = null;
    public bool $deltaDry = false;

    public ?string $apptFrom = null;
    public ?string $apptTo = null;
    public int $apptSliceDays = 7;
    public int $apptPageSize = 100;
    public int $apptLimit = 0;
    public int $apptMaxRetries = 5;
    public int $apptRetryBaseMs = 500;
    public ?int $apptSliceIndex = null;
    public bool $apptDryRun = false;
    public bool $apptLinkAfterSlice = false;

    public int $clientLimit = 0;
    public int $clientPageSize = 200;

    public ?string $auditFrom = null;
    public ?string $auditTo = null;
    public ?string $auditCalendarId = null;
    public ?string $auditCalendarName = null;
    public bool $auditFillMissing = false;
    public ?int $auditOutputLogId = null;
    public bool $auditOutputModalOpen = false;
    public bool $auditWaiting = false;
    public ?string $auditQueuedAt = null;

    public ?string $webhookApptFilter = null;
    public int $webhookLimit = 50;
    public ?string $webhookActionFilter = 'all';
    public int $cancelResyncHours = 6;

    public bool $guideOpen = false;
    public bool $heavyAppointmentsConfirmOpen = false;
    public array $pendingAppointmentOptions = [];
    public ?string $pendingAppointmentAction = null;
    public bool $whyStuckOpen = false;

    protected ?array $cachedQueueStatus = null;

    public ?int $outputLogId = null;

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        if (! $user || ! $user->hasRole('super_admin', 'super-admin')) {
            return false;
        }

        return parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        // Gate the whole page (route mount + every Livewire action method) to
        // super_admin — matching shouldRegisterNavigation(). Previously this only
        // checked the 'data_health' domain, which is default-granted to 'manager'
        // and 'admin', so those roles could reach the page directly by URL and
        // invoke privileged wire methods (Horizon restart, job flush, webhook
        // replay, teacher-assignment reset) whose buttons were only ->visible()-hidden.
        $user = auth()->user();

        if (! $user || ! $user->hasRole('super_admin', 'super-admin')) {
            return false;
        }

        return Gate::allows('viewControlPanel');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('flushAppointmentTypeCaches')
                ->label('Refresh UK appointment cache')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Refresh appointment type cache')
                ->modalDescription('Clears cached UK presential appointment types so new classes become selectable when assigning teachers.')
                ->visible(fn () => auth()->user()?->hasRole('super_admin', 'super-admin') ?? false)
                ->action(function (): void {
                    TeacherAppointmentTypeAssignmentResource::flushCalendarCaches();
                    UserResource::flushAppointmentTypeCaches();
                    TeacherAppointmentTypeCatalogBuilder::rebuild();

                    Notification::make()
                        ->title('Appointment caches cleared')
                        ->success()
                        ->send();
                }),
            Action::make('resetTeacherTypeAssignments')
                ->label('Reset teacher appointment types')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Reset all teacher appointment assignments')
                ->modalDescription('Removes every teacher appointment type assignment, clears caches, and re-syncs teachers so all appointment types become available again.')
                ->visible(fn () => auth()->user()?->hasRole('super_admin', 'super-admin') ?? false)
                ->action(function (): void {
                    $teacherIds = TeacherAppointmentTypeAssignment::query()
                        ->distinct()
                        ->pluck('user_id')
                        ->filter()
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values();

                    TeacherAppointmentTypeAssignment::query()->delete();

                    foreach ($teacherIds as $teacherId) {
                        $teacher = User::find($teacherId);

                        if ($teacher) {
                            $teacher->unsetRelation('teacherAppointmentTypeAssignments');
                            $teacher->setRelation('teacherAppointmentTypeAssignments', collect());
                            TeacherAppointmentTypeAllocator::sync($teacher);
                        }
                    }

                    TeacherAppointmentTypeAssignmentResource::flushCalendarCaches();
                    UserResource::flushAppointmentTypeCaches();
                    TeacherAppointmentTypeCatalogBuilder::rebuild();

                    Notification::make()
                        ->title('Teacher appointment assignments reset')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\AdvancedSyncStatus::class,
            \App\Filament\Widgets\DataHealth::class,
        ];
    }

    public function queueStatus(): array
    {
        if ($this->cachedQueueStatus === null) {
            $this->cachedQueueStatus = app(QueueStatusResolver::class)->resolve();
        }

        return $this->cachedQueueStatus;
    }

    public function queueAlert(): ?array
    {
        $status = $this->queueStatus();

        if (($status['driver'] ?? null) !== 'redis') {
            if (config('app.demo_mode')) {
                return [
                    'level' => 'info',
                    'message' => 'This demo intentionally runs its queue synchronously: imports and backfills execute inline, so Horizon and Redis stay offline. In production this page supervises live Redis queues and Horizon workers.',
                ];
            }

            return [
                'level' => 'warning',
                'message' => sprintf(
                    'Queue driver is set to %s. Set QUEUE_CONNECTION=redis so Horizon workers can process jobs.',
                    $status['driver'] ?? 'unknown'
                ),
            ];
        }

        if (($status['redis_available'] ?? null) === false) {
            return [
                'level' => 'danger',
                'message' => 'Redis is unreachable. Restart Redis or update credentials before running staged imports.',
            ];
        }

        if ((int) ($status['active_processes'] ?? 0) === 0) {
            return [
                'level' => 'danger',
                'message' => 'No Horizon workers are running. Jobs will queue but not process.',
            ];
        }

        return null;
    }

    public function whyStuckContext(): array
    {
        $status = $this->queueStatus();

        return [
            'driver' => $status['driver'] ?? null,
            'redis_available' => $status['redis_available'] ?? null,
            'redis_error' => $status['redis_error'] ?? null,
            'configured' => $status['configured_supervisors'] ?? [],
            'running' => $status['supervisors'] ?? [],
            'env' => app()->environment(),
        ];
    }

    public function summaryStats(): array
    {
        $row = DB::table('students')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(DISTINCT acuity_client_id) as distinct_ids, COUNT(DISTINCT email_norm) as distinct_emails, COUNT(*) as total')
            ->first();

        $ids = (int) ($row->distinct_ids ?? 0);
        $emails = (int) ($row->distinct_emails ?? 0);
        $localStudents = $ids > 0 ? $ids : $emails;

        $cachedAcuity = (int) (Cache::get('acuity_client_count') ?? 0);
        $acuityClients = $cachedAcuity > 0 ? $cachedAcuity : $localStudents;
        $acuityAt = Cache::get('acuity_client_count_at');

        return [
            'local_students' => $localStudents,
            'acuity_clients' => $acuityClients,
            'acuity_at' => $acuityAt,
            'acuity_is_estimate' => $cachedAcuity <= 0,
        ];
    }

    public function recentLogs(): Collection
    {
        return SyncLog::query()
            ->latest('id')
            ->limit(15)
            ->get();
    }

    public function importRuns(): Collection
    {
        return AcuityImportRun::query()
            ->latest('id')
            ->limit(5)
            ->get();
    }

    public function queueDiagnostics(): array
    {
        $status = $this->queueStatus();
        $usingRedis = ($status['driver'] ?? null) === 'redis' && ($status['redis_available'] ?? null) !== false;
        $lengths = [];

        if ($usingRedis) {
            try {
                $client = Redis::connection(config('queue.connections.redis.connection', 'default'));
                foreach (['high', 'acuity', 'default'] as $queue) {
                    $lengths[$queue] = (int) $client->llen("queues:$queue");
                }
            } catch (\Throwable $e) {
                $usingRedis = false;
                $lengths = [];
            }
        }

        $failed = collect();
        $failedCount = 0;

        try {
            $failedCount = (int) DBFacade::table('failed_jobs')->count();
            $failed = DBFacade::table('failed_jobs')->latest('id')->limit(5)->get();
        } catch (\Throwable $e) {
            $failed = collect();
        }

        return [
            'using_redis' => $usingRedis,
            'lengths' => $lengths,
            'failed_count' => $failedCount,
            'failed' => $failed,
        ];
    }

    public function webhookStats(): array
    {
        $base = DB::table('acuity_webhook_events');
        $last = (clone $base)->latest('received_at')->first();
        $lastHour = (clone $base)->where('received_at', '>=', Carbon::now()->subHour())->count();
        $lastDay = (clone $base)->where('received_at', '>=', Carbon::now()->subDay())->count();

        $recentQ = (clone $base)->orderByDesc('received_at');
        $limit = max(5, min(200, (int) $this->webhookLimit));

        $actionFilter = $this->webhookActionFilter;
        if ($actionFilter && $actionFilter !== 'all') {
            if ($actionFilter === 'cancelled') {
                $recentQ->whereRaw('LOWER(COALESCE(action, "")) LIKE ?', ['%cancel%']);
            } elseif ($actionFilter === 'rescheduled') {
                $recentQ->whereRaw('LOWER(COALESCE(action, "")) LIKE ?', ['%resched%']);
            } elseif ($actionFilter === 'other') {
                $recentQ->where(function ($qry) {
                    $qry->whereNull('action')
                        ->orWhereRaw('LOWER(action) NOT LIKE ?', ['%cancel%'])
                        ->orWhereRaw('LOWER(action) NOT LIKE ?', ['%resched%']);
                });
            }
        }

        if ($this->webhookApptFilter) {
            $recentQ->where('appointment_id', $this->webhookApptFilter);
        }

        $recent = $recentQ->limit($limit)->get();

        return [
            'last' => $last,
            'last_hour' => $lastHour,
            'last_day' => $lastDay,
            'recent' => $recent,
        ];
    }

    public function availableAuditCalendars(): array
    {
        $rows = DB::table('class_sessions')
            ->select(
                DB::raw("DISTINCT COALESCE(json_extract(acuity_data, '$.calendarID'), json_extract(acuity_data, '$.calendarId'), json_extract(acuity_data, '$.calendar.id')) as cid"),
                DB::raw("COALESCE(json_extract(acuity_data, '$.calendar'), json_extract(acuity_data, '$.calendarName'), json_extract(acuity_data, '$.calendar.name')) as cname")
            )
            ->whereNotNull('acuity_data')
            ->limit(200)
            ->get();

        $options = [];
        foreach ($rows as $row) {
            $id = is_string($row->cid) ? trim($row->cid, "\" ") : (string) ($row->cid ?? '');
            $name = is_string($row->cname) ? trim($row->cname, "\" ") : (string) ($row->cname ?? '');
            if ($id === '') {
                continue;
            }
            $label = $name !== '' ? $name.' ('.$id.')' : $id;
            $options[$id] = $label;
        }

        ksort($options);

        return $options;
    }

    public function setAuditPreset(string $preset): void
    {
        $today = Carbon::now();

        if ($preset === 'this-month') {
            $this->auditFrom = $today->copy()->startOfMonth()->toDateString();
            $this->auditTo = $today->copy()->endOfMonth()->toDateString();
        } elseif ($preset === 'next-month') {
            $next = $today->copy()->addMonth();
            $this->auditFrom = $next->copy()->startOfMonth()->toDateString();
            $this->auditTo = $next->copy()->endOfMonth()->toDateString();
        } elseif ($preset === 'last-30') {
            $this->auditFrom = $today->copy()->subDays(30)->toDateString();
            $this->auditTo = $today->toDateString();
        } elseif ($preset === 'next-45') {
            $this->auditFrom = $today->toDateString();
            $this->auditTo = $today->copy()->addDays(45)->toDateString();
        }
    }

    public function quickFillAppointments(string $preset): void
    {
        $now = Carbon::now();

        if ($preset === 'july-onward') {
            $start = (clone $now);
            if ($start->month >= 7) {
                $start->month(7)->day(1);
            } else {
                $start->subYear()->month(7)->day(1);
            }
            $this->apptFrom = $start->startOfDay()->toDateString();
            $this->apptTo = $now->copy()->endOfDay()->toDateString();
        } elseif ($preset === 'this-month') {
            $this->apptFrom = $now->copy()->startOfMonth()->toDateString();
            $this->apptTo = $now->copy()->endOfMonth()->toDateString();
        } elseif ($preset === 'last-14') {
            $this->apptFrom = $now->copy()->subDays(14)->startOfDay()->toDateString();
            $this->apptTo = $now->copy()->endOfDay()->toDateString();
        }

        $this->apptSliceDays = 7;
        $this->apptPageSize = 100;
        $this->apptLimit = 0;
        $this->apptMaxRetries = 5;
        $this->apptRetryBaseMs = 500;
        $this->apptSliceIndex = null;
        $this->apptDryRun = false;
        $this->apptLinkAfterSlice = false;
    }

    public function quickFillDelta(string $preset): void
    {
        if ($preset === 'last-7') {
            $this->deltaSince = '-7 days';
            $this->deltaFrom = null;
            $this->deltaTo = null;
        } elseif ($preset === 'last-30') {
            $this->deltaSince = '-30 days';
            $this->deltaFrom = null;
            $this->deltaTo = null;
        }
    }

    public function quickFillAuditFromGuide(): void
    {
        $this->setAuditPreset('this-month');
    }

    public function queueDeltaSync(): void
    {
        $options = [];

        if ($this->deltaSince) {
            $options['--since'] = $this->deltaSince;
        }

        if ($this->deltaFrom) {
            $options['--from'] = $this->deltaFrom;
        }

        if ($this->deltaTo) {
            $options['--to'] = $this->deltaTo;
        }

        $options['--chunk-days'] = 1;
        $options['--pages'] = 0;
        $options['--page-size'] = 200;
        $options['--timeout'] = 25;
        $options['--retries'] = 3;

        if ($this->deltaDry) {
            $options['--dry'] = true;
        }

        $this->dispatchArtisan('acuity:delta-sync', $options, 'acuity', 'Delta sync queued');

        $this->deltaDry = false;
    }

    public function queueAppointmentsSync(): void
    {
        $options = $this->buildAppointmentOptions();

        if ($this->needsHeavyAppointmentsConfirm($options)) {
            $this->pendingAppointmentOptions = $options;
            $this->pendingAppointmentAction = 'sync';
            $this->heavyAppointmentsConfirmOpen = true;
            $this->dispatch('open-modal', id: 'heavy-appointments-modal');
            return;
        }

        $this->dispatchAppointments($options);
    }

    public function queueAppointmentsImportRun(): void
    {
        $options = $this->buildAppointmentOptions();
        unset($options['--sliceIndex']);

        if ($this->needsHeavyAppointmentsConfirm($options)) {
            $this->pendingAppointmentOptions = $options;
            $this->pendingAppointmentAction = 'bulk';
            $this->heavyAppointmentsConfirmOpen = true;
            $this->dispatch('open-modal', id: 'heavy-appointments-modal');
            return;
        }

        $this->dispatchImportRun($options);
    }

    public function confirmQueueAppointmentsSync(): void
    {
        if (empty($this->pendingAppointmentOptions)) {
            $this->heavyAppointmentsConfirmOpen = false;
            $this->dispatch('close-modal', id: 'heavy-appointments-modal');
            return;
        }

        if ($this->pendingAppointmentAction === 'bulk') {
            $this->dispatchImportRun($this->pendingAppointmentOptions, 'Bulk import queued (heavy)');
        } else {
            $this->dispatchAppointments($this->pendingAppointmentOptions, 'Appointments window queued (heavy)');
        }

        $this->pendingAppointmentOptions = [];
        $this->pendingAppointmentAction = null;
        $this->heavyAppointmentsConfirmOpen = false;
        $this->dispatch('close-modal', id: 'heavy-appointments-modal');
    }

    public function queueClientsSync(): void
    {
        $options = [];

        if ($this->clientLimit > 0) {
            $options['--limit'] = max(1, (int) $this->clientLimit);
        }

        $options['--page-size'] = max(25, min(1000, (int) $this->clientPageSize));

        $this->dispatchArtisan('acuity:sync-clients', $options, 'acuity', 'Clients sync queued');

        $this->clientLimit = 0;
        $this->clientPageSize = 200;
    }

    protected function buildAppointmentOptions(): array
    {
        $from = $this->apptFrom;
        $to = $this->apptTo;

        if (blank($from) || blank($to)) {
            $fromDate = Carbon::now()->startOfMonth()->toDateString();
            $toDate = Carbon::now()->endOfMonth()->toDateString();
            $this->apptFrom = $fromDate;
            $this->apptTo = $toDate;
            $from = $fromDate;
            $to = $toDate;
        }

        $options = [
            '--from' => $from,
            '--to' => $to,
            '--sliceDays' => max(1, min(30, (int) $this->apptSliceDays)),
            '--pageSize' => max(25, min(1000, (int) $this->apptPageSize)),
            '--maxRetries' => max(0, min(10, (int) $this->apptMaxRetries)),
            '--retryBaseMs' => max(0, min(5000, (int) $this->apptRetryBaseMs)),
        ];

        if ($this->apptLimit > 0) {
            $options['--limit'] = max(1, (int) $this->apptLimit);
        }

        if (! is_null($this->apptSliceIndex) && $this->apptSliceIndex > 0) {
            $options['--sliceIndex'] = (int) $this->apptSliceIndex;
        }

        if ($this->apptDryRun) {
            $options['--dryRun'] = true;
        }

        if ($this->apptLinkAfterSlice) {
            $options['--linkAfterSlice'] = true;
        }

        return $options;
    }

    protected function needsHeavyAppointmentsConfirm(array $options): bool
    {
        $slice = (int) ($options['--sliceDays'] ?? 0);
        $pageSize = (int) ($options['--pageSize'] ?? 0);

        return $slice > 14 || $pageSize > 500;
    }

    protected function dispatchAppointments(array $options, string $toastTitle = 'Appointments window queued'): void
    {
        $this->dispatchArtisan('acuity:sync-appointments', $options, 'acuity', $toastTitle);

        $this->apptLimit = 0;
        $this->apptSliceIndex = null;
        $this->apptDryRun = false;
        $this->apptLinkAfterSlice = false;
        $this->pendingAppointmentAction = null;
    }

    protected function dispatchImportRun(array $options, string $toastTitle = 'Bulk import queued'): void
    {
        $from = $options['--from'] ?? null;
        $to = $options['--to'] ?? null;

        if (! $from || ! $to) {
            Notification::make()
                ->title('Set both From and To before queuing a bulk import')
                ->danger()
                ->body('Choose an explicit date range so the importer can build slices.')
                ->send();
            return;
        }

        $sliceDays = max(1, (int) ($options['--sliceDays'] ?? 7));
        $pageSize = max(25, min(500, (int) ($options['--pageSize'] ?? 100)));
        $maxRetries = max(0, min(10, (int) ($options['--maxRetries'] ?? 5)));
        $retryBaseMs = max(0, min(5000, (int) ($options['--retryBaseMs'] ?? 500)));
        $limit = isset($options['--limit']) ? max(0, (int) $options['--limit']) : 0;
        $dryRun = array_key_exists('--dryRun', $options);
        $linkAfterSlice = array_key_exists('--linkAfterSlice', $options);

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        if ($start->greaterThan($end)) {
            Notification::make()
                ->title('Invalid date range')
                ->danger()
                ->body('Ensure the From date is before the To date for the bulk import.')
                ->send();
            return;
        }

        $totalDays = $start->diffInDays($end) + 1;
        $totalSlices = (int) ceil($totalDays / $sliceDays);

        $run = AcuityImportRun::create([
            'status' => AcuityImportRun::STATUS_PENDING,
            'window_start' => $start->toDateString(),
            'window_end' => $end->toDateString(),
            'slice_days' => $sliceDays,
            'page_size' => $pageSize,
            'max_retries' => $maxRetries,
            'retry_base_ms' => $retryBaseMs,
            'limit' => $limit > 0 ? $limit : null,
            'dry_run' => $dryRun,
            'link_after_slice' => $linkAfterSlice,
            'total_slices' => $totalSlices,
            'queued_by' => auth()->id(),
            'next_cursor' => null,
        ]);

        ProcessAcuityImportRun::dispatch($run->id);

        Notification::make()
            ->title($toastTitle)
            ->success()
            ->body("Run #{$run->id} queued. Track the slice progress below.")
            ->actions([
                NotificationAction::make('View bulk runs')
                    ->url(route('filament.admin.pages.control-panel') . '#bulk-imports'),
            ])
            ->send();

        $this->pendingAppointmentOptions = [];
        $this->pendingAppointmentAction = null;
        $this->heavyAppointmentsConfirmOpen = false;
    }

    protected function dispatchArtisan(string $command, array $options, string $queue, string $toastTitle): void
    {
        $log = SyncLog::create([
            'command' => $command,
            'params' => $options,
            'status' => 'queued',
            'ran_by' => auth()->id(),
        ]);

        RunArtisanCommand::dispatch($command, $options, auth()->id(), $log->id)
            ->onQueue($queue);

        Notification::make()
            ->title($toastTitle)
            ->success()
            ->body('Track progress in Recent Sync Logs.')
            ->actions([
                NotificationAction::make('View logs')
                    ->url(route('filament.admin.pages.control-panel') . '#sync-logs'),
            ])
            ->send();
    }

    public function pauseImportRun(int $runId): void
    {
        $run = AcuityImportRun::find($runId);
        if (! $run) {
            Notification::make()->title('Import run not found')->danger()->send();
            return;
        }

        if ($run->isFinished() || $run->status === AcuityImportRun::STATUS_PAUSED) {
            return;
        }

        $run->markPaused();

        Notification::make()
            ->title("Run #{$run->id} paused")
            ->success()
            ->send();
    }

    public function resumeImportRun(int $runId): void
    {
        $run = AcuityImportRun::find($runId);
        if (! $run) {
            Notification::make()->title('Import run not found')->danger()->send();
            return;
        }

        if ($run->isFinished()) {
            Notification::make()
                ->title("Run #{$run->id} already finished")
                ->warning()
                ->send();
            return;
        }

        $run->forceFill(['status' => AcuityImportRun::STATUS_PENDING])->save();
        ProcessAcuityImportRun::dispatch($run->id);

        Notification::make()
            ->title("Run #{$run->id} resumed")
            ->success()
            ->send();
    }

    public function cancelImportRun(int $runId): void
    {
        $run = AcuityImportRun::find($runId);
        if (! $run) {
            Notification::make()->title('Import run not found')->danger()->send();
            return;
        }

        if ($run->isFinished()) {
            Notification::make()
                ->title("Run #{$run->id} already finished")
                ->warning()
                ->send();
            return;
        }

        $run->markCancelled();

        Notification::make()
            ->title("Run #{$run->id} cancelled")
            ->warning()
            ->send();
    }

    public function runBackfillMetadata(): void
    {
        $this->dispatchArtisan('backfill:class-session-metadata', ['--limit' => 0], 'default', 'Backfill: class session metadata queued');
    }

    public function runTeacherAssignmentSync(): void
    {
        $this->dispatchArtisan('teacher-assignments:sync', [], 'default', 'Teacher assignment sync queued');
    }

    public function runBackfillRegionFlags(): void
    {
        $this->dispatchArtisan('students:backfill-region-flags', ['--chunk' => 500], 'default', 'Backfill: region flags queued');
    }

    public function runBackfillNextAppointment(): void
    {
        $this->dispatchArtisan('students:backfill-next-appointment', ['--chunk' => 500, '--horizon' => 365], 'default', 'Backfill: next appointment queued');
    }

    public function runBackfillFirstLast(): void
    {
        $this->dispatchArtisan('students:backfill-first-last', ['--chunk' => 1000], 'default', 'Backfill: first/last appointment queued');
    }

    public function runBackfillActiveFlag(): void
    {
        $this->dispatchArtisan('students:update-active-flag', [
            '--past' => 60,
            '--future' => 60,
            '--chunk' => 1000,
        ], 'default', 'Backfill: active flag queued');
    }

    public function auditCalendarWindow(): void
    {
        $params = [];

        if ($this->auditFrom) {
            $params['--from'] = $this->auditFrom;
        }

        if ($this->auditTo) {
            $params['--to'] = $this->auditTo;
        }

        if ($this->auditCalendarId) {
            $params['--calendarId'] = $this->auditCalendarId;
        }

        if ($this->auditCalendarName) {
            $params['--calendarName'] = $this->auditCalendarName;
        }

        if ($this->auditFillMissing) {
            $params['--fill-missing'] = true;
        }

        $log = SyncLog::create([
            'command' => 'acuity:audit-window',
            'params' => $params,
            'status' => 'queued',
            'ran_by' => auth()->id(),
        ]);

        RunArtisanCommand::dispatch('acuity:audit-window', $params, auth()->id(), $log->id)
            ->onQueue('acuity');

        $this->auditQueuedAt = Carbon::now()->toDateTimeString();
        $this->auditOutputLogId = null;
        $this->auditWaiting = true;
        $this->auditOutputModalOpen = true;
        $this->dispatch('open-modal', id: 'audit-output-modal');

        Notification::make()
            ->title('Audit window queued')
            ->success()
            ->body('Results will appear in the modal when the job starts.')
            ->actions([
                NotificationAction::make('View logs')
                    ->url(route('filament.admin.pages.control-panel') . '#sync-logs'),
            ])
            ->send();
    }

    public function showLastAuditOutput(): void
    {
        $log = SyncLog::query()
            ->where('command', 'acuity:audit-window')
            ->latest('id')
            ->first();

        if (! $log) {
            Notification::make()->title('No audit results yet')->warning()->send();
            return;
        }

        $this->auditOutputLogId = $log->id;
        $this->auditOutputModalOpen = true;
        $this->dispatch('open-modal', id: 'audit-output-modal');
    }

    public function rerunLastAudit(): void
    {
        $log = SyncLog::query()
            ->where('command', 'acuity:audit-window')
            ->latest('id')
            ->first();

        if (! $log) {
            Notification::make()->title('No previous audit to rerun')->warning()->send();
            return;
        }

        $params = $log->params ?? [];

        $this->auditFrom = $params['--from'] ?? null;
        $this->auditTo = $params['--to'] ?? null;
        $this->auditCalendarId = $params['--calendarId'] ?? null;
        $this->auditCalendarName = $params['--calendarName'] ?? null;
        $this->auditFillMissing = array_key_exists('--fill-missing', $params);

        $this->auditCalendarWindow();
    }

    public function checkForLatestAuditResult(): void
    {
        if (! $this->auditWaiting) {
            return;
        }

        $log = SyncLog::query()
            ->where('command', 'acuity:audit-window')
            ->when($this->auditQueuedAt, fn ($q) => $q->where('created_at', '>=', $this->auditQueuedAt))
            ->latest('id')
            ->first();

        if ($log) {
            $this->auditOutputLogId = $log->id;
            $this->auditWaiting = false;
        }
    }

    public function auditResultSummary(): array
    {
        if (! $this->auditOutputLogId) {
            return ['acuity' => null, 'db' => null, 'missing' => null];
        }

        $log = SyncLog::find($this->auditOutputLogId);
        if (! $log || ! $log->output) {
            return ['acuity' => null, 'db' => null, 'missing' => null];
        }

        $matches = [];
        if (preg_match('/Acuity count\s*:\s*(\d+)/i', $log->output, $match)) {
            $matches['acuity'] = (int) $match[1];
        }
        if (preg_match('/DB count\s*:\s*(\d+)/i', $log->output, $match)) {
            $matches['db'] = (int) $match[1];
        }
        if (preg_match('/Missing\s*:\s*(\d+)/i', $log->output, $match)) {
            $matches['missing'] = (int) $match[1];
        }

        return $matches + ['acuity' => null, 'db' => null, 'missing' => null];
    }

    public function restartHorizon(): void
    {
        $this->dispatchArtisan('horizon:terminate', [], 'default', 'Horizon restart queued');
    }

    public function retryAllFailedJobs(): void
    {
        $this->dispatchArtisan('queue:retry', ['id' => ['all']], 'default', 'Retry failed jobs queued');
    }

    public function flushFailedJobs(): void
    {
        $this->dispatchArtisan('queue:flush', [], 'default', 'Flush failed jobs queued');
    }

    public function clearCaches(): void
    {
        $this->dispatchArtisan('optimize:clear', [], 'default', 'Optimize clear queued');
    }

    public function reopenGuide(): void
    {
        $this->guideOpen = true;
    }

    public function closeGuide(): void
    {
        $this->guideOpen = false;
    }

    public function openWhyStuck(): void
    {
        $this->whyStuckOpen = true;
        $this->dispatch('open-modal', id: 'why-stuck-modal');
    }

    public function replayWebhookAppointment(int $id): void
    {
        $row = DBFacade::table('acuity_webhook_events')->where('id', $id)->first();
        if (! $row) {
            Notification::make()->title('Webhook not found')->danger()->send();
            return;
        }

        $appointmentId = $row->appointment_id ?? null;
        if (! $appointmentId) {
            Notification::make()->title('Webhook missing appointment ID')->danger()->send();
            return;
        }

        try {
            SyncAcuityAppointment::dispatch($appointmentId, (string) ($row->action ?? null))->onQueue('acuity');
            Notification::make()->title('Appointment replay queued')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Replay failed: '.$e->getMessage())->danger()->send();
        }
    }

    public function replayWebhookClient(int $id): void
    {
        $row = DBFacade::table('acuity_webhook_events')->where('id', $id)->first();
        if (! $row) {
            Notification::make()->title('Webhook not found')->danger()->send();
            return;
        }

        $payload = $row->payload ?? null;
        $decoded = is_string($payload) ? json_decode($payload, true) : (is_array($payload) ? $payload : null);
        $clientId = $decoded ? (data_get($decoded, 'client.id') ?? data_get($decoded, 'clientID') ?? data_get($decoded, 'clientId')) : null;

        if (! $clientId) {
            Notification::make()->title('No client ID in payload')->danger()->send();
            return;
        }

        try {
            SyncAcuityClient::dispatch($clientId)->onQueue('acuity');
            Notification::make()->title('Client replay queued')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Replay failed: '.$e->getMessage())->danger()->send();
        }
    }

    public function purgeOldWebhooks(int $days = 30): void
    {
        $count = DBFacade::table('acuity_webhook_events')
            ->where('received_at', '<', Carbon::now()->subDays($days))
            ->delete();

        Notification::make()
            ->title("Purged {$count} webhook events older than {$days} days")
            ->success()
            ->send();
    }

    public function openLogOutput(int $id): void
    {
        $this->outputLogId = $id;
        $this->dispatch('open-modal', id: 'output-modal');
    }

    public function commandsForGuide(): array
    {
        return [
            'Restart Horizon workers' => 'php artisan horizon:terminate',
            'Retry all failed jobs' => 'php artisan queue:retry --id=all',
            'Clear caches' => 'php artisan optimize:clear',
        ];
    }

    public function loomScriptLines(): array
    {
        $baseUrl = route('filament.admin.pages.control-panel');

        return [
            "Intro (0:00-0:15): We're on the Lumina demo Control Panel to load Acuity data for July onwards.",
            "Step 1 (0:15-0:35): Open Queue Health (" . $baseUrl . '#queue-health' . ") and confirm driver=redis, Horizon Running. If you see 'sync', update QUEUE_CONNECTION before proceeding.",
            "Step 2 (0:35-1:05): In Sync Tools → Clients Sync (" . $baseUrl . '#sync-tools' . ") click Quick Fill then 'Queue Clients Sync'. Wait for the toast and note the new entry under Recent Sync Logs.",
            "Step 3 (1:05-1:50): Still in Sync Tools, use Quick Fill 'July onward' to set From/To, keep Slice=7, Page=100, Limit=0, then run 'Queue Appointments Sync'. Mention heavy confirmation if prompted.",
            "Step 4 (1:50-2:10): Scroll to Recent Sync Logs (" . $baseUrl . '#sync-logs' . ") and open the latest entries. Call out created/updated counts in the output modal.",
            "Step 5 (2:10-2:40): Under Backfills (" . $baseUrl . '#backfills' . ") run Next Appointment, First/Last, Active Flag to refresh dashboards; explain why each matters.",
            "Outro (2:40-3:00): If a job fails, re-run from the same section or replay via logs; ping #lumina-platform with the Sync Log ID if it keeps failing.",
        ];
    }

    public function loomScriptText(): string
    {
        return implode("\n", $this->loomScriptLines());
    }
}
