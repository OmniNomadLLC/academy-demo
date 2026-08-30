<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\AcuityService;
use App\Services\LocationMappingService;
use Illuminate\Console\Command;

class SyncStudentLocationsFromAcuity extends Command
{
    protected $signature = 'acuity:sync-student-locations';
    protected $description = 'Update student locations based on Acuity appointment categories';

    public function handle()
    {
        $this->info('Syncing student locations from Acuity categories...');
        
        $acuityService = new AcuityService();
        $appointments = $acuityService->getAppointments(['max' => 10]); // Start with just 10
        $updated = 0;
        
        foreach ($appointments as $appointment) {
            // Debug each appointment
            $this->line("=== Processing Appointment {$appointment['id']} ===");
            $this->line("ClientID: " . ($appointment['clientID'] ?? 'MISSING'));
            $this->line("Category: '" . ($appointment['category'] ?? 'MISSING') . "'");
            
            if (!isset($appointment['clientID']) || !$appointment['clientID']) {
                $this->line("❌ Skipping - no clientID");
                continue;
            }
            
            $category = $appointment['category'] ?? null;
            if (!$category) {
                $this->line("❌ Skipping - no category");
                continue;
            }
            
            $location = LocationMappingService::getLocationFromCategory($category);
            $this->line("Mapping: '{$category}' -> {$location}");
            
            $student = Student::where('acuity_client_id', $appointment['clientID'])->first();
            
            if (!$student) {
                $this->line("❌ Student not found with acuity_client_id: {$appointment['clientID']}");
                continue;
            }
            
            $this->line("Student found: {$student->first_name} {$student->last_name}");
            $this->line("Current location: {$student->location}");
            
            if ($student->location !== $location) {
                $student->update(['location' => $location]);
                $updated++;
                $this->line("✅ Updated to: {$location}");
            } else {
                $this->line("⚪ Already correct location");
            }
            
            $this->line("");
        }
        
        $this->info("Updated {$updated} students.");
        return Command::SUCCESS;
    }
}
