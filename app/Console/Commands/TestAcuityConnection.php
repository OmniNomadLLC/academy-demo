<?php

namespace App\Console\Commands;

use App\Services\AcuityService;
use Illuminate\Console\Command;

class TestAcuityConnection extends Command
{
    protected $signature = 'acuity:test';
    protected $description = 'Test connection to Acuity Scheduling API';

    public function handle()
    {
        $this->info('Testing Acuity Scheduling API connection...');
        
        try {
            $acuity = new AcuityService();
            
            // Test basic connection
            if ($acuity->testConnection()) {
                $this->info('✅ API connection successful!');
                
                // Get appointment types
                $types = $acuity->getAppointmentTypes();
                $this->info("📋 Found " . count($types) . " appointment types:");
                
                foreach ($types as $type) {
                    $this->line("  - {$type['name']} (ID: {$type['id']})");
                }
                
                // Get recent appointments
                $appointments = $acuity->getRecentAppointments();
                $this->info("📅 Found " . count($appointments) . " appointments in last 30 days");
                
                return Command::SUCCESS;
                
            } else {
                $this->error('❌ API connection failed');
                return Command::FAILURE;
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}