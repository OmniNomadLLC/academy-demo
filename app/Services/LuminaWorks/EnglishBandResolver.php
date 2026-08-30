<?php

namespace App\Services\LuminaWorks;

use App\Models\Student;
use App\Models\StudentSkillProgress;

class EnglishBandResolver
{
    public const BAND_LOW = 'low';       // little to no functional English
    public const BAND_BASIC = 'basic';   // simple spoken instructions ok
    public const BAND_WORKING = 'working'; // can handle customer contact / written tasks
    public const BAND_UNKNOWN = 'unknown';

    /**
     * Coarse, non-sensitive English band from the latest skill log
     * (writing/reading/speaking on the existing 0-5 scale). This band — and
     * nothing else about the student — is what may later be shared with an
     * LLM for fit-scoring.
     */
    public function resolve(Student $student): string
    {
        $latest = StudentSkillProgress::where('student_id', $student->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        if (!$latest) {
            return self::BAND_UNKNOWN;
        }

        $scores = array_filter([
            $latest->speaking,
            $latest->reading,
            $latest->writing,
        ], fn ($v) => $v !== null);

        if ($scores === []) {
            return self::BAND_UNKNOWN;
        }

        $avg = array_sum($scores) / count($scores);

        return match (true) {
            $avg < 1.5 => self::BAND_LOW,
            $avg < 3.0 => self::BAND_BASIC,
            default => self::BAND_WORKING,
        };
    }
}
