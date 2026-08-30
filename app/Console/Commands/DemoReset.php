<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Rebuild the demo database from scratch with generated sample data (destructive; demo deployments only).';

    public function handle(): int
    {
        if (! config('app.demo_mode')) {
            $this->error('Refusing to run: app.demo_mode is not enabled.');

            return self::FAILURE;
        }

        $this->info('Resetting demo database...');
        Artisan::call('migrate:fresh', ['--force' => true], $this->output);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DemoSeeder', '--force' => true], $this->output);
        Artisan::call('optimize:clear', [], $this->output);

        $this->info('Demo reset complete.');

        return self::SUCCESS;
    }
}
