<?php

namespace App\Filament\Widgets;

use App\Models\ClassSession;
use App\Support\Concerns\FormatsSessionTimes;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;
use Filament\Tables\Actions\Action;
use Filament\Forms;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TodaysOperations extends BaseWidget
{
    use FormatsSessionTimes;
    protected static ?int $sort = -50;
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $tz = $this->resolveTimezone();
        $now = Carbon::now($tz);
        $inTwoHours = $now->copy()->addHours(2);
        $region = session('preferred_region') ?? request()->user()?->preferred_region ?? null;
        $norm = null;
        if (is_string($region)) {
            $r = strtolower(trim($region));
            $norm = $r === 'uk' ? 'UK' : ($r === 'spain' ? 'Spain' : ($r === 'france' ? 'France' : null));
        }

        $today = $now->toDateString();
        $tomorrow = $inTwoHours->toDateString();
        $wrapsMidnight = $tomorrow !== $today;
        $nowTime = $now->format('H:i:s');
        $laterTime = $inTwoHours->format('H:i:s');

        $query = ClassSession::query()
            ->select([
                'class_sessions.session_date',
                'class_sessions.start_time',
                'class_sessions.end_time',
                'class_sessions.location',
                'class_sessions.calendar_name',
                DB::raw('MIN(class_sessions.id) as id'),
                DB::raw('COUNT(DISTINCT class_sessions.id) as session_count'),
                DB::raw("ROUND(100.0 * COUNT(DISTINCT CASE WHEN ar.status IN ('present','late') THEN ar.student_id END) / NULLIF(COUNT(DISTINCT ar.student_id), 0), 1) as attendance_rate"),
                DB::raw('MAX(CASE WHEN ar.sent_at IS NOT NULL THEN 1 ELSE 0 END) as email_sent'),
                DB::raw("MAX(CASE WHEN "
                    ."LOWER(COALESCE(class_sessions.calendar_name, '')) LIKE '%assessment%' "
                    ."OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(class_sessions.acuity_data, '$.type')), '')) LIKE '%assessment%' "
                    ."OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(class_sessions.acuity_data, '$.appointmentType.name')), '')) LIKE '%assessment%' "
                    ."THEN 1 ELSE 0 END) as is_assessment"),
            ])
            ->leftJoin('attendance_records as ar', function ($join) {
                $join->on('class_sessions.id', '=', 'ar.class_session_id')
                    ->whereIn('ar.status', ['present','late','absent']);
            })
            ->where(function ($q) use ($today, $tomorrow, $wrapsMidnight, $nowTime, $laterTime) {
                $q
                // In-progress today (handles cross-midnight sessions where end_time < start_time)
                ->where(function ($w) use ($today, $nowTime) {
                    $w->whereDate('class_sessions.session_date', $today)
                      ->where(function ($x) use ($nowTime) {
                          $x->where(function ($y) use ($nowTime) {
                                $y->whereColumn('class_sessions.end_time', '>=', 'class_sessions.start_time')
                                  ->where('class_sessions.start_time', '<=', $nowTime)
                                  ->where('class_sessions.end_time', '>=', $nowTime);
                          })
                          ->orWhere(function ($y) use ($nowTime) {
                                $y->whereColumn('class_sessions.end_time', '<', 'class_sessions.start_time') // wraps midnight
                                  ->where(function ($z) use ($nowTime) {
                                      $z->where('class_sessions.start_time', '<=', $nowTime)
                                        ->orWhere('class_sessions.end_time', '>=', $nowTime);
                                  });
                          });
                      });
                })
                // OR upcoming within next 2 hours
                ->orWhere(function ($w) use ($today, $tomorrow, $wrapsMidnight, $nowTime, $laterTime) {
                    if ($wrapsMidnight) {
                        $w->where(function ($u) use ($today, $nowTime) {
                            $u->whereDate('class_sessions.session_date', $today)
                              ->where('class_sessions.start_time', '>=', $nowTime);
                        })->orWhere(function ($u) use ($tomorrow, $laterTime) {
                            $u->whereDate('class_sessions.session_date', $tomorrow)
                              ->where('class_sessions.start_time', '<=', $laterTime);
                        });
                    } else {
                        $w->whereDate('class_sessions.session_date', $today)
                          ->whereBetween('class_sessions.start_time', [$nowTime, $laterTime]);
                    }
                });
            })
            ->where(function ($w) { $w->where('class_sessions.canceled', false)->orWhereNull('class_sessions.canceled'); })
            ->when($norm, fn($q) => $q->where('class_sessions.location', $norm))
            ->groupBy('class_sessions.session_date', 'class_sessions.start_time', 'class_sessions.end_time', 'class_sessions.location', 'class_sessions.calendar_name')
            ->orderBy('class_sessions.session_date')
            ->orderBy('class_sessions.start_time');

        return $table
            ->query($query)
            ->deferLoading()
            ->columns([
                Tables\Columns\TextColumn::make('session_date')
                    ->label('Date')
                    ->date('d-m-Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_time')
                    ->label('Start')
                    ->sortable()
                    ->formatStateUsing(fn ($state, $record) => $this->formatTimeForUser($record, 'start_time')),
                Tables\Columns\TextColumn::make('calendar_name')->label('Calendar')->limit(40),
                Tables\Columns\TextColumn::make('location')->label('Region')->sortable(),
                Tables\Columns\TextColumn::make('session_count')->label('Sessions')->badge()->color('gray')->sortable(),
                Tables\Columns\TextColumn::make('attendance_rate')
                    ->label('Attendance')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_null($state) ? '—' : number_format((float) $state, 1).'%' )
                    ->color(function ($state) {
                        if (is_null($state)) { return 'gray'; }
                        $value = (float) $state;
                        return $value >= 90 ? 'success' : ($value >= 75 ? 'warning' : 'danger');
                    }),
                Tables\Columns\IconColumn::make('email_sent')
                    ->label('Emails')
                    ->boolean()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('takeAttendanceUk')
                    ->label('Take attendance')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn($record) => strtolower((string)($record->location ?? '')) === 'uk')
                    ->url(fn($record) => route('filament.admin.pages.take-attendance', ['sessionId' => $record->id]))
            ])
            ->recordClasses(fn ($record) => (int) ($record->is_assessment ?? 0) === 1
                // `!` modifier beats Filament's ->striped() `even:bg-gray-50` specificity.
                ? '!bg-purple-100 hover:!bg-purple-200 dark:!bg-purple-900/30 dark:hover:!bg-purple-900/50'
                : null)
            ->paginated(false)
            ->poll('30s')
            ->striped();
    }

    protected function getTableHeading(): string
    {
        $tz = $this->resolveTimezone();
        $time = Carbon::now($tz)->format('H:i');
        return "Starting soon (next 2 hours) · 🕒 $time $tz";
    }

    protected function getTableHeaderActions(): array
    {
        $user = Auth::user();
        $currentTz = $this->resolveTimezone();
        $tzOptions = collect(\DateTimeZone::listIdentifiers())->mapWithKeys(fn($z) => [$z => $z])->all();

        $currentRegion = $user?->preferred_region ?: null;
        $makeRegionBtn = function (string $label, ?string $value) use ($currentRegion) {
            $key = $value ? strtolower($value) : 'all';
            return Action::make('region_'.$key)
                ->label($label)
                ->color($currentRegion === $value ? 'primary' : 'gray')
                ->button()
                ->outlined($currentRegion !== $value)
                ->action(function () use ($value) {
                    $u = Auth::user();
                    if ($u) { $u->preferred_region = $value ?: null; $u->save(); }
                    $this->resetTable();
                });
        };

        return [
            // Timezone selector (region filter moved to Dashboard header)
            Action::make('setTimezone')
                ->label('Timezone')
                ->icon('heroicon-o-clock')
                ->form([
                    Forms\Components\Select::make('timezone')
                        ->label('Display timezone')
                        ->options($tzOptions)
                        ->searchable()
                        ->preload()
                        ->default($currentTz)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $user = Auth::user();
                    if ($user) {
                        $user->timezone = $data['timezone'] ?? null;
                        $user->save();
                        \date_default_timezone_set($user->timezone);
                    }
                })
                ->modalHeading('Select timezone')
                ->modalSubmitActionLabel('Save timezone'),
        ];
    }

    protected function resolveTimezone(): string
    {
        $user = Auth::user();
        $region = session('preferred_region') ?? $user?->preferred_region;

        if ($user?->timezone) {
            return $user->timezone;
        }

        $regionTz = match (strtolower((string) $region)) {
            'uk', 'united kingdom', 'gb', 'great britain' => 'Europe/London',
            'spain', 'es' => 'Europe/Madrid',
            'france', 'fr' => 'Europe/Paris',
            default => null,
        };

        if ($regionTz) {
            return $regionTz;
        }

        return \config('app.timezone');
    }
}
