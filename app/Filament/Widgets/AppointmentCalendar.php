<?php

namespace App\Filament\Widgets;

use App\Models\ClassSession;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class AppointmentCalendar extends Widget
{
    protected static bool $isDiscovered = false; // do not auto-appear on dashboard
    protected static string $view = 'filament.widgets.appointment-calendar';
    
    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        $sessions = ClassSession::with(['schoolClass'])
            ->whereDate('session_date', '>=', now()->startOfMonth())
            ->whereDate('session_date', '<=', now()->endOfMonth())
            ->get();

        $calendarData = [];
        foreach ($sessions as $session) {
            $calendarName = $session->acuity_data['calendar'] ?? 'Unknown Calendar';
            $calendarData[] = [
                'title' => $session->schoolClass->name,
                'start' => $session->session_date->format('Y-m-d') . 'T' . $session->start_time,
                'end' => $session->session_date->format('Y-m-d') . 'T' . $session->end_time,
                'calendar' => $calendarName,
                'status' => $session->status,
            ];
        }

        return [
            'sessions' => $sessions,
            'calendarData' => $calendarData,
        ];
    }
}
