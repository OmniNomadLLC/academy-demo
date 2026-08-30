<?php

namespace App\Support\Assessments;

class SkillCategory
{
    public const SPEAKING_LISTENING = 'speaking_listening';
    public const READING_WRITING = 'reading_writing';
    public const TO_LEARN = 'to_learn';
    public const WORK_READINESS = 'work_readiness';
    public const IT_SKILLS = 'it_skills';

    public static function all(): array
    {
        return [
            self::SPEAKING_LISTENING,
            self::READING_WRITING,
            self::TO_LEARN,
            self::WORK_READINESS,
            self::IT_SKILLS,
        ];
    }

    public static function label(string $category): string
    {
        return self::labels()[$category] ?? ucfirst($category);
    }

    public static function labels(): array
    {
        return [
            self::SPEAKING_LISTENING => 'Speaking & Listening',
            self::READING_WRITING => 'Reading & Writing',
            self::TO_LEARN => 'Motivation to Learn',
            self::WORK_READINESS => 'Work Readiness',
            self::IT_SKILLS => 'IT Skills',
        ];
    }

    public static function default(): string
    {
        return self::SPEAKING_LISTENING;
    }

    public static function fromSection(?string $section): string
    {
        $normalized = strtolower(trim((string) $section));

        return match ($normalized) {
            'speaking', 'listening', 'speaking & listening', 'speaking and listening assessment' => self::SPEAKING_LISTENING,
            'reading', 'writing', 'reading & writing' => self::READING_WRITING,
            'to learn', 'motivation to learn', 'learning', 'behaviour / attitude / ability', 'behavior / attitude / ability', 'behaviour', 'behavior' => self::TO_LEARN,
            'work readiness', 'work' => self::WORK_READINESS,
            'it', 'it skills', 'technology' => self::IT_SKILLS,
            default => self::default(),
        };
    }
}
