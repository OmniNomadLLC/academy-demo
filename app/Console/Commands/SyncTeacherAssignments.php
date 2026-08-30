<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\TeacherAppointmentTypeAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncTeacherAssignments extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'teacher-assignments:sync
        {teacher? : Teacher ID or email address to target}
        {--chunk=50 : Number of teachers to process per batch}';

    /**
     * The console command description.
     */
    protected $description = 'Relink teacher-class assignments based on Acuity calendar + appointment-type mappings.';

    public function handle(): int
    {
        if (! Schema::hasTable('teacher_appointment_type_assignments')) {
            $this->components->error('Teacher appointment assignments table not found. Run migrations first.');

            return self::FAILURE;
        }

        $identifier = $this->argument('teacher');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $query = User::query()
            ->whereIn('role', User::TEACHING_ROLES)
            ->when($identifier, function ($inner) use ($identifier) {
                if (is_numeric($identifier)) {
                    $inner->where('id', (int) $identifier);

                    return;
                }

                $term = trim((string) $identifier);

                $inner->where(function ($sub) use ($term) {
                    $sub->where('email', $term)
                        ->orWhere('name', 'like', "%{$term}%");
                });
            })
            ->orderBy('id');

        if (! $query->exists()) {
            $message = $identifier
                ? sprintf('No teachers matched "%s".', $identifier)
                : 'No teachers found to sync.';

            $this->components->warn($message);

            return self::FAILURE;
        }

        $total = 0;

        $query->chunkById($chunkSize, function ($teachers) use (&$total) {
            foreach ($teachers as $teacher) {
                TeacherAppointmentTypeAllocator::sync($teacher);
                $this->components->info(sprintf('Synced %s (#%d)', $teacher->name ?? 'teacher', $teacher->id));
                $total++;
            }
        });

        $this->components->info(sprintf('Teacher assignments refreshed for %d teacher(s).', $total));

        return self::SUCCESS;
    }
}
