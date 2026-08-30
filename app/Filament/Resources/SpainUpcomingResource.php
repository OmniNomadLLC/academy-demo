<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresRegionAccess;
use App\Filament\Resources\SpainUpcomingResource\Pages;
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

class SpainUpcomingResource extends Resource
{
    use RequiresRegionAccess;

    protected static string $requiredRegion = 'Spain';
    protected static ?string $model = ClassSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = 'Spain';
    protected static ?string $navigationLabel = 'Upcoming Classes';
    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Spain';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $today = now()->toDateString();
                // Use normalized category only (backfill ensures it exists)
                $catExpr = "LOWER(TRIM(COALESCE(category_norm, '')))";
                $categories = ['english','spanish','french','german','italian','lebanese','bni','marina'];
                $to = now()->addDays(60)->toDateString();
                $query->whereBetween('session_date', [$today, $to])
                    ->where(function ($w) {
                        $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']);
                    })
                    ->where(function ($w) use ($catExpr, $categories) {
                        foreach ($categories as $cat) {
                            $w->orWhereRaw("{$catExpr} LIKE ?", [$cat.'%']);
                        }
                    });
            })
            ->columns([
                Tables\Columns\TextColumn::make('session_date')->date('Y-m-d')->sortable()->label('Date'),
                Tables\Columns\TextColumn::make('start_time')->time('H:i')->sortable()->label('Start'),
                Tables\Columns\TextColumn::make('end_time')->time('H:i')->sortable()->label('End'),
                Tables\Columns\TextColumn::make('calendar_name')
                    ->label('Calendar')
                    ->badge()
                    ->searchable()
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
                        // First word + next initial for compactness
                        $parts = preg_split('/\s+/', $full);
                        $first = $parts[0] ?? $full;
                        $second = $parts[1] ?? null;
                        return $second ? ($first.' '.mb_substr($second, 0, 1).'.') : $first;
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // Search normalized and raw category fields
                        return $query->where(function ($w) use ($search) {
                            $w->orWhere('category_norm', 'like', '%'.$search.'%')
                              ->orWhereRaw("json_extract(acuity_data, '$.category') like ?", ['%'.$search.'%'])
                              ->orWhereRaw("json_extract(acuity_data, '$.Category') like ?", ['%'.$search.'%']);
                        });
                    })
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
                // Hidden columns solely for search support
                Tables\Columns\TextColumn::make('client_email')->searchable()->hidden(),
                Tables\Columns\TextColumn::make('student_email')->searchable()->hidden(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('calendar')
                    ->label('Acuity calendar')
                    ->options(function () {
                        $today = now()->toDateString();
                        $categories = ['english','spanish','french','german','italian','lebanese','bni','marina'];
                        $cals = DB::table('class_sessions')
                            ->whereDate('session_date', '>=', $today)
                            ->where(function ($w) { $w->where('canceled', false)->orWhereNull('canceled')->orWhereIn('status', ['scheduled','confirmed']); })
                            ->where(function ($w) use ($categories) {
                                $expr = "LOWER(TRIM(COALESCE(category_norm, COALESCE(json_extract(acuity_data, '$.category'), json_extract(acuity_data, '$.Category')))))";
                                foreach ($categories as $cat) {
                                    $w->orWhereRaw("{$expr} LIKE ?", [$cat.'%']);
                                }
                            })
                            ->select(DB::raw("DISTINCT COALESCE(\n                                json_extract(acuity_data, '$.calendar'),\n                                json_extract(acuity_data, '$.calendarName'),\n                                json_extract(acuity_data, '$.calendar.name'),\n                                json_extract(acuity_data, '$.Calendar'),\n                                json_extract(acuity_data, '$.CalendarName')\n                            ) as cal"))
                            ->orderBy('cal')
                            ->pluck('cal')
                            ->filter()
                            ->map(function ($v) { return trim(str_replace('"', '', (string) $v)); })
                            ->unique()
                            ->values();
                        // Build disambiguated labels (first word + initial when needed)
                        $vals = $cals->toArray();
                        $firstCounts = [];
                        foreach ($vals as $v) {
                            $fw = strtolower(strtok($v, ' '));
                            $firstCounts[$fw] = ($firstCounts[$fw] ?? 0) + 1;
                        }
                        $labels = [];
                        $used = [];
                        foreach ($vals as $v) {
                            $parts = preg_split('/\s+/', trim((string) $v));
                            $fw = $parts[0] ?? $v;
                            $label = $fw;
                            if (($firstCounts[strtolower($fw)] ?? 0) > 1) {
                                $second = $parts[1] ?? null;
                                if ($second) {
                                    $label = $fw.' '.mb_substr($second, 0, 1).'.';
                                } else {
                                    $last = $parts[count($parts)-1] ?? null;
                                    $label = $last && strtolower($last) !== strtolower($fw)
                                        ? $fw.' '.mb_substr($last, 0, 1).'.'
                                        : $fw;
                                }
                            }
                            $base = $label; $thirdTried = false;
                            while (in_array($label, $used, true)) {
                                if (!$thirdTried) {
                                    $third = $parts[2] ?? null;
                                    if ($third) { $label = $base.' '.mb_substr($third, 0, 1).'.'; $thirdTried = true; continue; }
                                }
                                $suffix = 2; while (in_array($base.' '.$suffix, $used, true)) { $suffix++; }
                                $label = $base.' '.$suffix;
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
                        $today = now('Europe/Madrid')->toDateString();
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
            ->actions([])
            ->bulkActions([])
            ->defaultSort('session_date', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSpainUpcoming::route('/'),
        ];
    }
}
