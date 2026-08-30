<?php

namespace App\Console\Commands;

use App\Support\TeacherAppointmentTypeCatalogBuilder;
use App\Filament\Resources\TeacherAppointmentTypeAssignmentResource;
use Illuminate\Console\Command;

class RefreshTeacherAppointmentTypes extends Command
{
    protected $signature = 'teacher-appointment-types:refresh';

    protected $description = 'Rebuild the teacher appointment-type catalog and flush related caches';

    public function handle(): int
    {
        $this->info('Refreshing teacher appointment-type catalog...');

        TeacherAppointmentTypeCatalogBuilder::rebuild();

        $this->info('Flushing cached calendar/assignment options...');
        TeacherAppointmentTypeAssignmentResource::flushCalendarCaches();

        $this->info('Teacher appointment types refreshed.');

        return Command::SUCCESS;
    }
}
