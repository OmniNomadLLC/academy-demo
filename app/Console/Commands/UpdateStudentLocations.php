<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\ClassSession;
use App\Services\LocationMappingService;
use Illuminate\Console\Command;

class UpdateStudentLocations extends Command
{
    protected $signature = 'students:update-locations';
    protected $description = 'Update student locations based on their class sessions';

    public function handle()
    {
        $this->info('Updating student locations...');
        
        $students = Student::whereNull('location')->orWhere('location', 'UK')->get();
        $updated = 0;
        
        foreach ($students as $student) {
            // Find the student's most recent class session
            $session = ClassSession::where('student_id', $student->id)
                ->whereHas('schoolClass', function($query) {
                    $query->whereNotNull('name');
                })
                ->with('schoolClass')
                ->latest()
                ->first();
                
            if ($session && $session->schoolClass) {
                $location = LocationMappingService::getLocationFromCategory($session->schoolClass->name);
                
                if ($location !== 'UK' || $student->location !== 'UK') {
                    $student->update(['location' => $location]);
                    $updated++;
                    $this->line("Updated {$student->first_name} {$student->last_name} -> {$location}");
                }
            }
        }
        
        $this->info("Updated {$updated} students with correct locations.");
        
        // Show distribution
        $ukCount = Student::where('location', 'UK')->count();
        $spainCount = Student::where('location', 'Spain')->count();
        $franceCount = Student::where('location', 'France')->count();
        
        $this->table(
            ['Location', 'Student Count'],
            [
                ['UK', $ukCount],
                ['Spain', $spainCount], 
                ['France', $franceCount]
            ]
        );
        
        return Command::SUCCESS;
    }
}