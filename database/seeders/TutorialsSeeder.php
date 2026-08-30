<?php

namespace Database\Seeders;

use App\Models\Tutorial;
use Illuminate\Database\Seeder;

class TutorialsSeeder extends Seeder
{
    public function run(): void
    {
        Tutorial::updateOrCreate(
            ['title' => 'Duplicate Students — How to review and merge'],
            [
                'description'      => 'Step-by-step guide for reviewing duplicate student records detected by the system and merging them correctly.',
                'category'         => 'Students',
                'content_type'     => 'pdf',
                'file_path'        => 'tutorials/duplicate_students_tutorial.pdf',
                'visible_to_roles' => ['super_admin', 'admin', 'uk_manager'],
                'sort_order'       => 10,
            ]
        );

        $this->command?->info('Tutorial seeded. Place the PDF at:');
        $this->command?->line('  storage/app/public/tutorials/duplicate_students_tutorial.pdf');
        $this->command?->line('(scp upload to the server, or upload via /admin/tutorials after deploy)');
    }
}
