<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class AttendanceReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Attendance Report';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?int $navigationSort = 1;
    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.attendance-report';

    public ?string $from = null;
    public ?string $to = null;
    public ?string $region = null; // UK/Spain/France
    public ?string $calendar = null; // calendar_name exact match

    public function mount(): void
    {
        $this->from = now()->subDays(30)->toDateString();
        $this->to = now()->toDateString();
        $this->region = null;
        $this->calendar = null;
    }

    public function form(Form $form): Form
    {
        $regionOptions = [
            'UK' => 'UK',
            'Spain' => 'Spain',
            'France' => 'France',
        ];
        $calendarOptions = DB::table('class_sessions')
            ->select(DB::raw("DISTINCT TRIM(COALESCE(calendar_name, '')) AS v"))
            ->whereRaw("TRIM(COALESCE(calendar_name, '')) <> ''")
            ->orderBy('v')
            ->pluck('v', 'v')
            ->toArray();

        return $form->schema([
            Forms\Components\Grid::make(['md' => 6])->schema([
                Forms\Components\DatePicker::make('from')->label('From')->default($this->from)->live(),
                Forms\Components\DatePicker::make('to')->label('To')->default($this->to)->live(),
                Forms\Components\Select::make('region')->label('Region')->options($regionOptions)->native(false)->live(),
                Forms\Components\Select::make('calendar')->label('Calendar')->options($calendarOptions)->searchable()->native(false)->live(),
            ]),
        ]);
    }

    public function getViewData(): array
    {
        $state = $this->form->getState() ?? [];
        $from = $state['from'] ?? $this->from;
        $to = $state['to'] ?? $this->to;
        $region = $state['region'] ?? $this->region;
        $calendar = $state['calendar'] ?? $this->calendar;

        $q = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->whereBetween('cs.session_date', [$from, $to]);
        if (!empty($region)) {
            $q->whereRaw('LOWER(cs.location) = ?', [strtolower($region)]);
        }
        if (!empty($calendar)) {
            $q->where('cs.calendar_name', $calendar);
        }

        $totals = (clone $q)
            ->select('ar.status', DB::raw('COUNT(*) as c'))
            ->groupBy('ar.status')
            ->pluck('c', 'status');

        $present = (int) ($totals['present'] ?? 0);
        $late = (int) ($totals['late'] ?? 0);
        $absent = (int) ($totals['absent'] ?? 0);
        $den = max(1, $present + $late + $absent);
        $rate = round(($present / $den) * 100, 2);

        // Top students by absences
        $topAbsences = (clone $q)
            ->select('ar.student_id', DB::raw("SUM(ar.status = 'absent') as absences"))
            ->groupBy('ar.student_id')
            ->orderByDesc('absences')
            ->limit(10)
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'region' => $region,
            'calendar' => $calendar,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'rate' => $rate,
            'topAbsences' => $topAbsences,
        ];
    }
}
