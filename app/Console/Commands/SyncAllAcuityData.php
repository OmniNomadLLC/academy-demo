<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncAllAcuityData extends Command
{
    protected $signature = 'acuity:sync-all 
                            {--quick : Quick sync with reduced limits}';
    
    protected $description = 'Sync all data from Acuity Scheduling';

    public function handle()
    {
        $this->info('🚀 Starting complete Acuity sync...');
        
        $isQuick = $this->option('quick');
        
        if ($isQuick) {
            $this->info('⚡ Running quick sync...');
            $clientLimit = 50;
            $appointmentDays = 14;
            $appointmentLimit = 100;
        } else {
            $this->info('🔄 Running full sync...');
            $clientLimit = 200;
            $appointmentDays = 30;
            $appointmentLimit = 300;
        }

        // Sync clients first
        $this->info('👥 1/2 Syncing clients...');
        $this->call('acuity:sync-clients', [
            '--limit' => $clientLimit
        ]);

        // Sync appointments
        $this->info('📅 2/2 Syncing appointments...');
        $this->call('acuity:sync-appointments', [
            '--days' => $appointmentDays,
            '--limit' => $appointmentLimit
        ]);

        $this->info('✅ Complete sync finished!');
        $this->info('🎯 Check your admin panel to see the synced data!');
        
        return Command::SUCCESS;
    }
}
