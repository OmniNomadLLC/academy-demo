<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AcademicUpcomingResource\Pages;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Services\Acuity\AppointmentExtractor;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AcademicUpcomingResource extends Resource
{
    protected static ?string $model = ClassSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = 'Academic Management';
    protected static ?string $navigationLabel = 'Upcoming Classes';
    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $today = now()->toDateString();
                $jsonCategory = "LOWER(COALESCE(json_extract(acuity_data, '$.category'), json_extract(acuity_data, '$.Category')))";
                $to = now()->addDays(60)->toDateString();
                $query->whereBetween('session_date', [$today, $to])
                    ->where(function ($w) {
                        $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']);
                    });
            })
            ->columns([
                Tables\Columns\TextColumn::make('session_date')->date('Y-m-d')->sortable()->label('Date'),
                Tables\Columns\TextColumn::make('start_time')->time('H:i')->sortable()->label('Start'),
                Tables\Columns\TextColumn::make('end_time')->time('H:i')->sortable()->label('End'),
                Tables\Columns\TextColumn::make('location')->badge()->label('Region')->sortable(),
                Tables\Columns\TextColumn::make('calendar_name')
                    ->label('Calendar')
                    ->badge()
                    ->tooltip(function ($record) {
                        $data = $record->acuity_data ?? [];
                        $candidates = [
                            $data['calendar'] ?? null,
                            $data['calendarName'] ?? null,
                            data_get($data, 'calendar.name'),
                            $data['Calendar'] ?? null,
                            $data['CalendarName'] ?? null,
                        ];
                        foreach ($candidates as $v) {
                            if (is_string($v) && trim($v) !== '') return trim($v);
                        }
                        return null;
                    })
                    ->getStateUsing(function ($record) {
                        $data = $record->acuity_data ?? [];
                        $candidates = [
                            $data['calendar'] ?? null,
                            $data['calendarName'] ?? null,
                            data_get($data, 'calendar.name'),
                            $data['Calendar'] ?? null,
                            $data['CalendarName'] ?? null,
                        ];
                        $full = null;
                        foreach ($candidates as $v) {
                            if (is_string($v) && trim($v) !== '') { $full = trim($v); break; }
                        }
                        if (!$full) return 'Unknown';
                        $parts = preg_split('/\s+/', $full);
                        $first = $parts[0] ?? $full;
                        $second = $parts[1] ?? null;
                        return $second ? ($first.' '.mb_substr($second, 0, 1).'.') : $first;
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->tooltip(fn($record) => $record->category_norm ?: null)
                    ->getStateUsing(function ($record) {
                        $data = $record->acuity_data ?? [];
                        $cat = $data['category'] ?? ($data['Category'] ?? null);
                        return $cat ? trim($cat) : ($record->category_norm ?: '');
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('client')
                    ->label('Client')
                    ->getStateUsing(function ($record) {
                        $data = $record->acuity_data ?? [];
                        $first = $data['firstName'] ?? data_get($data, 'client.firstName');
                        $last = $data['lastName'] ?? data_get($data, 'client.lastName');
                        $email = $data['email'] ?? data_get($data, 'client.email');
                        $name = trim(trim((string) $first).' '.trim((string) $last));
                        return trim($name !== '' ? $name : ($email ?? ''));
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()->label('Status')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('location')
                    ->label('Region')
                    ->options([
                        'UK' => 'UK',
                        'Spain' => 'Spain',
                        'France' => 'France',
                    ])
                    ->multiple(),
                Tables\Filters\SelectFilter::make('calendar')
                    ->label('Acuity calendar')
                    ->options(function () {
                        $today = now()->toDateString();
                        $cals = DB::table('class_sessions')
                            ->whereDate('session_date', '>=', $today)
                            ->where(function ($w) { $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']); })
                            ->select(DB::raw("DISTINCT LOWER(TRIM(COALESCE(calendar_norm, ''))) as cal"))
                            ->orderBy('cal')
                            ->pluck('cal')
                            ->filter()
                            ->unique()
                            ->values();
                        $vals = $cals->toArray();
                        $firstCounts = [];
                        foreach ($vals as $v) { $fw = strtolower(strtok($v, ' ')); $firstCounts[$fw] = ($firstCounts[$fw] ?? 0) + 1; }
                        $labels = []; $used = [];
                        foreach ($vals as $v) {
                            $parts = preg_split('/\s+/', trim((string) $v)); $fw = $parts[0] ?? $v; $label = $fw;
                            if (($firstCounts[strtolower($fw)] ?? 0) > 1) {
                                $second = $parts[1] ?? null; $label = $second ? ($fw.' '.mb_substr($second, 0, 1).'.') : $fw;
                            }
                            $base = $label; $thirdTried = false;
                            while (in_array($label, $used, true)) {
                                if (!$thirdTried) { $third = $parts[2] ?? null; if ($third) { $label = $base.' '.mb_substr($third, 0, 1).'.'; $thirdTried = true; continue; } }
                                $suffix = 2; while (in_array($base.' '.$suffix, $used, true)) { $suffix++; } $label = $base.' '.$suffix;
                            }
                            $used[] = $label; $labels[$v] = $label;
                        }
                        return $labels;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $val = $data['value'] ?? null;
                        if ($val) {
                            return $query->whereRaw("LOWER(TRIM(COALESCE(calendar_norm, ''))) = ?", [$val]);
                        }
                        return $query;
                    }),
                Tables\Filters\Filter::make('today')
                    ->label('Today')
                    ->toggle()
                    ->query(function (Builder $query): Builder {
                        $today = now()->toDateString();
                        return $query->whereDate('session_date', $today);
                    }),
                Tables\Filters\Filter::make('next7')
                    ->label('Next 7 days')
                    ->toggle()
                    ->query(function (Builder $query): Builder {
                        $from = now()->toDateString();
                        $to = now()->addDays(7)->toDateString();
                        return $query->whereBetween('session_date', [$from, $to]);
                    }),
                Tables\Filters\Filter::make('next30')
                    ->label('Next 30 days')
                    ->toggle()
                    ->query(function (Builder $query): Builder {
                        $from = now()->toDateString();
                        $to = now()->addDays(30)->toDateString();
                        return $query->whereBetween('session_date', [$from, $to]);
                    }),
                Tables\Filters\Filter::make('date_range')
                    ->label('Date range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;
                        if ($from) $query->whereDate('session_date', '>=', $from);
                        if ($until) $query->whereDate('session_date', '<=', $until);
                        return $query;
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) $indicators['from'] = 'From '.$data['from'];
                        if ($data['until'] ?? null) $indicators['until'] = 'Until '.$data['until'];
                        return $indicators;
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options([
                        'scheduled' => 'Scheduled',
                        'confirmed' => 'Confirmed',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ]),
                Tables\Filters\SelectFilter::make('teacher_id')
                    ->label('Teacher')
                    ->options(function () {
                        return User::whereIn('role', User::TEACHING_ROLES)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn($u) => [$u->id => trim(($u->name ?? '').' ('.$u->email.')')])
                            ->toArray();
                    })
                    ->multiple()
                    ->searchable(),
                Tables\Filters\Filter::make('mine')
                    ->label('My classes')
                    ->visible(fn () => optional(Auth::user())->isTeachingRole())
                    ->toggle()
                    ->query(function (Builder $query): Builder {
                        $uid = Auth::id();
                        return $uid ? $query->where('teacher_id', $uid) : $query;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('linkStudent')
                    ->label('Link')
                    ->icon('heroicon-o-link')
                    ->visible(fn (ClassSession $record) => empty($record->student_id))
                    ->action(function (ClassSession $record) {
                        $data = is_array($record->acuity_data) ? $record->acuity_data : [];
                        $ex = AppointmentExtractor::extract($data);
                        $clientId = $ex['clientId'];
                        $email = $ex['clientEmail'] ?? null;

                        $student = null;
                        if ($clientId) {
                            $student = Student::where('acuity_client_id', (string) $clientId)->first();
                        }
                        if (!$student && $email) {
                            $student = Student::whereRaw('LOWER(email) = ?', [$email])->first();
                        }

                        if ($student) {
                            $record->student_id = $student->id;
                            $record->link_status = 'linked';
                            $record->save();
                            Notification::make()->title('Linked to '.$student->first_name.' '.$student->last_name)->success()->send();
                        } else {
                            Notification::make()->title('No matching student found (by clientId or email)').warning()->send();
                        }
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('session_date', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAcademicUpcoming::route('/'),
        ];
    }

    protected static function isSuperAdmin(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
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
