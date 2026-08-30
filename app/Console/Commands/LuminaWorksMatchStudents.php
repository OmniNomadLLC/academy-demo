<?php

namespace App\Console\Commands;

use App\Models\EmploymentProfile;
use App\Services\LuminaWorks\JobMatcher;
use App\Services\LuminaWorks\PostcodeGeocoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LuminaWorksMatchStudents extends Command
{
    protected $signature = 'luminaworks:match-students
        {--student= : Only match this student id}
        {--top= : Matches to keep per student (default 10)}';

    protected $description = 'Run stage-1 job matching for active employment profiles (Lumina Works)';

    public function handle(JobMatcher $matcher, PostcodeGeocoder $geocoder): int
    {
        if (!config('luminaworks.enabled')) {
            $this->warn('Lumina Works is disabled (LUMINAWORKS_ENABLED=false).');

            return self::SUCCESS;
        }

        $keepTop = (int) ($this->option('top') ?: 10);

        $profiles = EmploymentProfile::query()
            ->where('is_active', true)
            ->when($this->option('student'), fn ($q, $id) => $q->where('student_id', $id))
            ->with('student')
            ->get();

        $matched = 0;
        $skippedNoLocation = 0;

        foreach ($profiles as $profile) {
            // Lazily geocode profiles that have a postcode but no coordinates yet.
            if ($profile->latitude === null && $profile->postcode) {
                if ($coords = $geocoder->geocode($profile->postcode)) {
                    $profile->forceFill($coords)->save();
                }
            }

            if ($profile->latitude === null) {
                $skippedNoLocation++;
                continue;
            }

            $written = $matcher->matchProfile($profile, $keepTop);
            $matched += $written;

            if ($written > 0) {
                $this->line("Student {$profile->student_id}: {$written} matches");
            }
        }

        Log::info('Lumina Works matching completed', [
            'profiles' => $profiles->count(),
            'matches_written' => $matched,
            'skipped_no_location' => $skippedNoLocation,
        ]);

        $this->info("Done: {$matched} matches over {$profiles->count()} profielen ({$skippedNoLocation} zonder postcode/locatie overgeslagen).");

        return self::SUCCESS;
    }
}
