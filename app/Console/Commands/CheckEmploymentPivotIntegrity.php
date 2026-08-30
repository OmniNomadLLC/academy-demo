<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckEmploymentPivotIntegrity extends Command
{
    protected $signature = 'check:employment-pivot-integrity';

    protected $description = 'Ensure employment pivot tables are free from duplicates, nulls, and orphan records.';

    public function handle(): int
    {
        try {
            $this->checkDuplicates();
            $this->checkNulls();
            $this->checkOrphans();
        } catch (Throwable $e) {
            $this->error('[ERROR] Integrity check failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected function checkDuplicates(): void
    {
        $this->info('Checking for duplicate rows...');

        $interestDuplicates = DB::table('employment_profile_interest')
            ->select('employment_profile_id', 'employment_interest_id', DB::raw('COUNT(*) as total'))
            ->groupBy('employment_profile_id', 'employment_interest_id')
            ->having('total', '>', 1)
            ->get();

        if ($interestDuplicates->isEmpty()) {
            $this->info('[OK] No duplicates in employment_profile_interest.');
        } else {
            $this->error('[ERROR] Duplicate rows found in employment_profile_interest:');
            $this->table(['employment_profile_id', 'employment_interest_id', 'count'], $interestDuplicates->toArray());
        }

        $availabilityDuplicates = DB::table('employment_profile_availability')
            ->select('employment_profile_id', 'employment_availability_option_id', DB::raw('COUNT(*) as total'))
            ->groupBy('employment_profile_id', 'employment_availability_option_id')
            ->having('total', '>', 1)
            ->get();

        if ($availabilityDuplicates->isEmpty()) {
            $this->info('[OK] No duplicates in employment_profile_availability.');
        } else {
            $this->error('[ERROR] Duplicate rows found in employment_profile_availability:');
            $this->table(['employment_profile_id', 'employment_availability_option_id', 'count'], $availabilityDuplicates->toArray());
        }
    }

    protected function checkNulls(): void
    {
        $this->info('Checking for NULL values...');

        $interestNulls = DB::table('employment_profile_interest')
            ->whereNull('employment_profile_id')
            ->orWhereNull('employment_interest_id')
            ->get();

        if ($interestNulls->isEmpty()) {
            $this->info('[OK] No NULL values in employment_profile_interest.');
        } else {
            $this->error('[ERROR] NULL values found in employment_profile_interest:');
            $this->table(['id', 'employment_profile_id', 'employment_interest_id'], $interestNulls->toArray());
        }

        $availabilityNulls = DB::table('employment_profile_availability')
            ->whereNull('employment_profile_id')
            ->orWhereNull('employment_availability_option_id')
            ->get();

        if ($availabilityNulls->isEmpty()) {
            $this->info('[OK] No NULL values in employment_profile_availability.');
        } else {
            $this->error('[ERROR] NULL values found in employment_profile_availability:');
            $this->table(['id', 'employment_profile_id', 'employment_availability_option_id'], $availabilityNulls->toArray());
        }
    }

    protected function checkOrphans(): void
    {
        $this->info('Checking for orphan records...');

        $profileIds = DB::table('employment_profiles')->pluck('id')->all();
        $interestIds = DB::table('employment_interests')->pluck('id')->all();
        $availabilityIds = DB::table('employment_availability_options')->pluck('id')->all();

        $interestOrphansQuery = DB::table('employment_profile_interest');
        if (! empty($profileIds)) {
            $interestOrphansQuery->whereNotIn('employment_profile_id', $profileIds);
        } else {
            $interestOrphansQuery->whereRaw('1=1');
        }

        if (! empty($interestIds)) {
            $interestOrphansQuery->orWhereNotIn('employment_interest_id', $interestIds);
        } else {
            $interestOrphansQuery->orWhereRaw('1=1');
        }

        $interestOrphans = $interestOrphansQuery->get();

        if ($interestOrphans->isEmpty()) {
            $this->info('[OK] No orphan records in employment_profile_interest.');
        } else {
            $this->error('[ERROR] Orphan records found in employment_profile_interest:');
            $this->table(['id', 'employment_profile_id', 'employment_interest_id'], $interestOrphans->toArray());
        }

        $availabilityOrphansQuery = DB::table('employment_profile_availability');
        if (! empty($profileIds)) {
            $availabilityOrphansQuery->whereNotIn('employment_profile_id', $profileIds);
        } else {
            $availabilityOrphansQuery->whereRaw('1=1');
        }

        if (! empty($availabilityIds)) {
            $availabilityOrphansQuery->orWhereNotIn('employment_availability_option_id', $availabilityIds);
        } else {
            $availabilityOrphansQuery->orWhereRaw('1=1');
        }

        $availabilityOrphans = $availabilityOrphansQuery->get();

        if ($availabilityOrphans->isEmpty()) {
            $this->info('[OK] No orphan records in employment_profile_availability.');
        } else {
            $this->error('[ERROR] Orphan records found in employment_profile_availability:');
            $this->table(['id', 'employment_profile_id', 'employment_availability_option_id'], $availabilityOrphans->toArray());
        }
    }
}
