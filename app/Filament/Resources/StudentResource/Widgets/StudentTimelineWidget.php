<?php

namespace App\Filament\Resources\StudentResource\Widgets;

use Filament\Widgets\Widget;
use App\Models\Student;
use Illuminate\Support\Facades\Auth;

class StudentTimelineWidget extends Widget
{
    protected static string $view = 'filament.resources.student-resource.widgets.timeline-info';

    protected int|string|array $columnSpan = 'full';

    public $record = null;

    public function mount($record = null): void
    {
        $this->record = $record;
    }

    protected function getViewData(): array
    {
        $record = $this->record;

        return [
            'record' => $record,
            'isSuperAdmin' => optional(Auth::user())->role === 'super_admin',
        ];
    }
}
