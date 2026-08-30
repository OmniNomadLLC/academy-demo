<?php

namespace App\Filament\Resources\Concerns;

use App\Filament\Resources\StudentResource\Widgets\StudentTimelineWidget;

trait HasStudentFooterWidgets
{
    protected function getFooterWidgets(): array
    {
        return [
            StudentTimelineWidget::make([
                'record' => $this->record,
            ]),
        ];
    }

    public function getFooterWidgetsColumns(): int|string|array
    {
        return 1;
    }
}
