<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Resources\UKUpcomingResource\Pages;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Services\Acuity\AppointmentExtractor;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use App\Services\LocationMappingService;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UKUpcomingResource extends Resource
{
    use RequiresRegionAccess;

    protected static string $requiredRegion = 'UK';
    protected static ?string $model = ClassSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = 'UK';
    protected static ?string $navigationLabel = 'Upcoming Classes';
    protected static ?int $navigationSort = 9;

    public static function getNavigationGroup(): ?string
    {
        return 'UK';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $today = now()->toDateString();
                $keywords = LocationMappingService::keywordsForRegion('UK');
                $jsonCategory = "LOWER(COALESCE(json_extract(acuity_data, '$.category'), json_extract(acuity_data, '$.Category')))";
                $to = now()->addDays(60)->toDateString();
                $query->whereBetween('session_date', [$today, $to])
                    ->where(function ($w) {
                        $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']);
                    })
                    ->where(function ($w) use ($keywords, $jsonCategory) {
                        $w->whereRaw('LOWER(location) = ?', ['uk']);
                        if (!empty($keywords)) {
                            $w->orWhere(function ($qq) use ($keywords) {
                                foreach ($keywords as $kw) {
                                    $qq->orWhere('category_norm', 'like', '%'.$kw.'%');
                                }
                            })
                            ->orWhere(function ($qq) use ($keywords, $jsonCategory) {
                                foreach ($keywords as $kw) {
                                    $qq->orWhereRaw($jsonCategory.' like ?', ['%'.$kw.'%']);
                                }
                            });
                        }
                        $w->orWhereRaw("LOWER(TRIM(COALESCE(calendar_norm, ''))) <> ''");
                    });
            })
            ->columns([
                Tables\Columns\TextColumn::make('session_date')
                    ->label('Date')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => \Illuminate\Support\Carbon::parse($state)->format('d-m-Y')),
                Tables\Columns\TextColumn::make('start_time')->time('H:i')->sortable()->label('Start'),
                Tables\Columns\TextColumn::make('calendar_label')
                    ->label('Calendar')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? Str::title(Str::limit($state, 32)) : 'Unknown')
                    ->tooltip(fn (?string $state) => $state ? Str::title($state) : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('appointment_label')
                    ->label('Appointment type')
                    ->wrap()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->appointment_type_id ? 'Type ID: '.$record->appointment_type_id : null)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('teacher.name')
                    ->label('Teacher')
                    ->getStateUsing(function ($record) {
                        $t = $record->teacher;
                        if (!$t) return '—';
                        $name = trim((string) ($t->name ?? ''));
                        return $name !== '' ? $name : ($t->email ?? '—');
                    })
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('booking_count')
                    ->label('Students')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state) => number_format((int) $state))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('calendar')
                    ->label('Acuity calendar')
                    ->options(function () {
                        $today = now()->toDateString();
                        $cals = DB::table('class_sessions')
                            ->whereDate('session_date', '>=', $today)
                            ->where(function ($w) { $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']); })
                            ->whereRaw('LOWER(location) = ?', ['uk'])
                            ->select(DB::raw("DISTINCT COALESCE(\n                                json_extract(acuity_data, '$.calendar'),\n                                json_extract(acuity_data, '$.calendarName'),\n                                json_extract(acuity_data, '$.calendar.name'),\n                                json_extract(acuity_data, '$.Calendar'),\n                                json_extract(acuity_data, '$.CalendarName')\n                            ) as cal"))
                            ->orderBy('cal')
                            ->pluck('cal')
                            ->filter()
                            ->map(function ($v) { return trim(str_replace('"', '', (string) $v)); })
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
                            return $query->where(function ($w) use ($val) {
                                $w->whereRaw("json_extract(acuity_data, '$.calendar') = ?", [$val])
                                  ->orWhereRaw("json_extract(acuity_data, '$.calendarName') = ?", [$val])
                                  ->orWhereRaw("json_extract(acuity_data, '$.calendar.name') = ?", [$val])
                                  ->orWhereRaw("json_extract(acuity_data, '$.Calendar') = ?", [$val])
                                  ->orWhereRaw("json_extract(acuity_data, '$.CalendarName') = ?", [$val]);
                            });
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
                Tables\Actions\Action::make('manage')
                    ->label('Manage class')
                    ->icon('heroicon-o-user-group')
                    ->url(fn ($record) => static::getUrl('manage', ['record' => $record]))
                    ->visible(fn () => Auth::user()?->hasRole('admin', 'super_admin', 'manager') ?? false),
            ])
            ->bulkActions([])
            ->defaultSort('session_date', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUKUpcoming::route('/'),
            'manage' => Pages\ManageUpcomingClass::route('/{record}/manage'),
        ];
    }
}
