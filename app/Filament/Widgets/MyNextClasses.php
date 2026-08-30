<?php

namespace App\Filament\Widgets;

use App\Models\ClassSession;
use App\Models\Manager;
use App\Models\User;
use App\Support\TeacherRoster;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class MyNextClasses extends BaseWidget
{
    // Place after "Today’s Upcoming Classes"
    protected static ?int $sort = -48;
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        $role = Str::lower((string) $user->role);

        return in_array($role, array_merge(['admin', 'manager', 'super_admin'], User::TEACHING_ROLES), true);
    }

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $today = Carbon::today()->toDateString();
        $role = Str::of($user?->role ?? '')->lower()->value();

        $region = session('preferred_region') ?? ($user?->preferred_region ?: null);
        $norm = null;
        if (is_string($region)) {
            $r = Str::lower(trim($region));
            $norm = match ($r) {
                'uk' => 'UK',
                'spain' => 'Spain',
                'france' => 'France',
                default => null,
            };
        }

        $allowedRegions = $user ? $user->allowedRegions() : null;
        if ($user && $user->restrictsByRegion()) {
            if (! $norm || ! in_array($norm, $allowedRegions, true)) {
                $norm = $allowedRegions[0] ?? $norm;
                if ($norm) {
                    session(['preferred_region' => $norm]);
                }
            }
        }

        $query = ClassSession::query()->whereRaw('1 = 0');

        if (in_array($role, User::TEACHING_ROLES, true)) {
            $query = TeacherRoster::sessions($user)
                ->whereDate('session_date', $today)
                ->when($norm && $user->restrictsByRegion(), fn ($q) => $q->where('location', $norm))
                ->orderBy('session_date')
                ->orderBy('start_time');
        } elseif ($role === 'manager') {
            $manager = Manager::query()
                ->whereRaw('LOWER(email) = ?', [Str::lower((string) $user?->email)])
                ->first();

            if ($manager) {
                $query = ClassSession::query()
                    ->whereDate('session_date', $today)
                    ->where(function ($w) {
                        $w->where('canceled', false)->orWhereNull('canceled');
                    })
                    ->when($norm, fn ($q) => $q->where('location', $norm))
                    ->when($user && $user->restrictsByRegion(), function ($q) use ($allowedRegions) {
                        return $q->whereIn('location', $allowedRegions ?? []);
                    })
                    ->orderBy('session_date')
                    ->orderBy('start_time');
                $query->whereIn('student_id', function ($sub) use ($manager) {
                    $sub->select('id')->from('students')->where('manager_id', $manager->id);
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif (in_array($role, ['admin', 'super_admin'], true)) {
            // Admin users see all regions filtered by preference only.
            $query = ClassSession::query()
                ->whereDate('session_date', $today)
                ->where(function ($w) {
                    $w->where('canceled', false)->orWhereNull('canceled');
                })
                ->when($norm, fn ($q) => $q->where('location', $norm))
                ->when($user && $user->restrictsByRegion(), function ($q) use ($allowedRegions) {
                    return $q->whereIn('location', $allowedRegions ?? []);
                })
                ->orderBy('session_date')
                ->orderBy('start_time');
        } else {
            $query->whereRaw('1 = 0');
        }

        return $table
            ->query($query)
            ->deferLoading()
            ->columns([
                Tables\Columns\TextColumn::make('session_date')->label('Date')->date('Y-m-d'),
                Tables\Columns\TextColumn::make('start_time')->label('Start'),
                Tables\Columns\TextColumn::make('end_time')->label('End'),
                Tables\Columns\TextColumn::make('calendar_name')->label('Calendar')->limit(40),
                Tables\Columns\TextColumn::make('location')->label('Region'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'scheduled' => 'primary',
                        'cancelled', 'canceled' => 'danger',
                        default => 'success',
                    }),
            ])
            ->paginated(false)
            ->striped();
    }

    protected function getTableHeading(): string
    {
        $user = Auth::user();
        $role = Str::of($user?->role ?? '')->lower()->value();
        $region = $user?->preferred_region ?: 'All Regions';

        if ($role === 'manager') {
            return "Today’s Classes · Managed roster · {$region}";
        }

        if (in_array($role, User::TEACHING_ROLES, true)) {
            return "Today’s Classes · {$region}";
        }

        return "Today’s Classes · {$region}";
    }

    // Region quick filters moved to Dashboard header
}
