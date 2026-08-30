<?php

namespace App\Console\Commands;

use App\Services\AcuityService;
use Illuminate\Console\Command;

class DebugAcuityCategories extends Command
{
    protected $signature = 'debug:acuity-categories';
    protected $description = 'Debug actual category values from Acuity';

    public function handle()
    {
        $this->info('Fetching appointments to see actual category values...');
        
        $acuityService = new AcuityService();
        $appointments = $acuityService->getAppointments(['max' => 50]);
        
        $categories = [];
        
        foreach ($appointments as $appointment) {
            $category = $appointment['category'] ?? 'NO_CATEGORY';
            $appointmentType = $appointment['type'] ?? 'NO_TYPE';
            
            if (!isset($categories[$category])) {
                $categories[$category] = [];
            }
            
            if (!in_array($appointmentType, $categories[$category])) {
                $categories[$category][] = $appointmentType;
            }
        }
        
        $this->info('Categories found in Acuity appointments:');
        
        foreach ($categories as $category => $types) {
            $this->line("\nCategory: '{$category}'");
            foreach ($types as $type) {
                $this->line("  - Type: {$type}");
            }
        }
        
        return Command::SUCCESS;
    }
}
