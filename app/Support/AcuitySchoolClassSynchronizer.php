<?php

namespace App\Support;

use App\Models\SchoolClass;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AcuitySchoolClassSynchronizer
{
    /**
     * Ensure the incoming Acuity appointment produces a single SchoolClass row with
     * a stable external identifier so downstream sessions can link safely.
     */
    public function syncFromAppointment(array $appointmentData, array $context = []): SchoolClass
    {
        $normalized = $this->normalizeAttributes($appointmentData, $context);
        $lookup = Arr::only($normalized, ['external_source', 'external_id']);
        $payload = Arr::except($normalized, ['external_source', 'external_id']);

        $schoolClass = SchoolClass::updateOrCreate($lookup, $payload);

        if ($schoolClass->external_source !== $normalized['external_source']
            || $schoolClass->external_id !== $normalized['external_id']) {
            $schoolClass->forceFill([
                'external_source' => $normalized['external_source'],
                'external_id' => $normalized['external_id'],
            ])->save();
        }

        return $schoolClass;
    }

    /**
     * Build a deterministic identifier for an already persisted school class row.
     * Used by the deduplication command to rewrite blank/inconsistent external IDs.
     */
    public function identifierFromRow(array $row, array $context = []): string
    {
        $normalized = [
            'name' => $row['name'] ?? 'Class',
            'language' => $row['language'] ?? 'General',
            'level' => $row['level'] ?? 'Mixed',
            'location' => $row['location'] ?? ($context['location'] ?? 'UK'),
            'duration_minutes' => (int) ($row['duration_minutes'] ?? 60),
            'max_students' => (int) ($row['max_students'] ?? 10),
            'acuity_appointment_type_id' => $row['acuity_appointment_type_id'] ?? null,
        ];

        $sourceData = [
            'calendar' => $context['calendar_name'] ?? $row['name'] ?? null,
            'calendarID' => $context['calendar_id'] ?? null,
        ];

        return $this->determineExternalId($sourceData, $normalized, $context);
    }

    /**
     * Normalize the key attributes that describe a SchoolClass from raw appointment payloads.
     */
    public function normalizeAttributes(array $appointmentData, array $context = []): array
    {
        $name = $this->resolveName($appointmentData);
        $language = $this->extractLanguage($appointmentData, $name);
        $level = $this->extractLevel($appointmentData, $name);
        $location = $this->determineLocation($appointmentData, $context);
        $duration = (int) ($appointmentData['duration'] ?? ($context['duration_minutes'] ?? 60));
        $capacity = (int) ($this->resolveCapacity($appointmentData) ?? ($context['max_students'] ?? 10));
        $appointmentTypeId = $this->resolveAppointmentTypeId($appointmentData, $context);

        $normalized = [
            'name' => $name,
            'description' => $this->resolveDescription($appointmentData, $name),
            'language' => $language,
            'level' => $level,
            'location' => $location,
            'duration_minutes' => $duration,
            'max_students' => $capacity,
            'is_active' => true,
            'acuity_appointment_type_id' => $appointmentTypeId,
            'external_source' => 'acuity',
        ];

        $normalized['external_id'] = $this->determineExternalId($appointmentData, $normalized, $context);

        return $normalized;
    }

    protected function determineExternalId(array $appointmentData, array $normalized, array $context = []): string
    {
        $typeId = $normalized['acuity_appointment_type_id'] ?? null;
        if ($typeId) {
            return 'type:' . (string) $typeId;
        }

        $calendarKey = $this->resolveCalendarKey($appointmentData, $context);
        $language = $this->slug($normalized['language'] ?? 'general');
        $level = $this->slug($normalized['level'] ?? 'mixed');
        $location = $this->slug($normalized['location'] ?? 'uk');
        $name = $this->slug($normalized['name'] ?? 'class');

        $parts = [
            $calendarKey,
            $name,
            $language,
            $level,
            $location,
            'd:' . (int) ($normalized['duration_minutes'] ?? 0),
            'c:' . (int) ($normalized['max_students'] ?? 0),
        ];

        return 'hash:' . sha1(implode('|', $parts));
    }

    protected function resolveCalendarKey(array $appointmentData, array $context = []): string
    {
        $candidates = [
            $context['calendar_id'] ?? null,
            data_get($appointmentData, 'calendarID'),
            data_get($appointmentData, 'calendarId'),
            data_get($appointmentData, 'calendar.id'),
            data_get($appointmentData, 'calendar'),
            data_get($appointmentData, 'calendarName'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->slug($candidate);
            }
            if (is_numeric($candidate)) {
                return 'cal-' . $candidate;
            }
        }

        return 'calendar';
    }

    protected function resolveAppointmentTypeId(array $appointmentData, array $context): ?string
    {
        $candidates = [
            data_get($appointmentData, 'appointmentTypeID'),
            data_get($appointmentData, 'appointmentTypeId'),
            data_get($appointmentData, 'appointmentType.id'),
            data_get($appointmentData, 'typeID'),
            data_get($appointmentData, 'typeId'),
            data_get($appointmentData, 'type.id'),
            $context['appointment_type_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
            if (is_numeric($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    protected function determineLocation(array $appointmentData, array $context): string
    {
        $candidates = [
            $context['location'] ?? null,
            data_get($appointmentData, 'location'),
            data_get($appointmentData, 'category'),
            data_get($appointmentData, 'category_norm'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtoupper(trim($candidate));
            }
        }

        return 'UK';
    }

    protected function resolveName(array $appointmentData): string
    {
        $appointmentTypeName = data_get($appointmentData, 'type')
            ?? data_get($appointmentData, 'appointmentType')
            ?? 'General Class';
        $calendarName = data_get($appointmentData, 'calendar')
            ?? data_get($appointmentData, 'calendarName')
            ?? data_get($appointmentData, 'calendar.name');

        if ($calendarName && strlen((string) $calendarName) > strlen((string) $appointmentTypeName)) {
            return (string) $calendarName;
        }

        return (string) $appointmentTypeName;
    }

    protected function resolveDescription(array $appointmentData, string $name): string
    {
        $notes = data_get($appointmentData, 'notes');
        if (is_string($notes) && trim($notes) !== '') {
            return trim($notes);
        }

        return "Auto-synced from Acuity for {$name}";
    }

    protected function extractLanguage(array $appointmentData, string $name): string
    {
        $text = strtolower(json_encode([
            $appointmentData['type'] ?? null,
            $appointmentData['appointmentType'] ?? null,
            $appointmentData['calendar'] ?? null,
            $appointmentData['calendarName'] ?? null,
            $name,
        ]));

        return match (true) {
            str_contains($text, 'french') => 'French',
            str_contains($text, 'spanish') => 'Spanish',
            str_contains($text, 'german') => 'German',
            str_contains($text, 'italian') => 'Italian',
            str_contains($text, 'portuguese') => 'Portuguese',
            str_contains($text, 'english') => 'English',
            default => 'General',
        };
    }

    protected function extractLevel(array $appointmentData, string $name): string
    {
        $text = strtolower(json_encode([
            $appointmentData['type'] ?? null,
            $appointmentData['appointmentType'] ?? null,
            $appointmentData['calendar'] ?? null,
            $appointmentData['calendarName'] ?? null,
            $name,
        ]));

        return match (true) {
            str_contains($text, 'beginner') || str_contains($text, 'a1') => 'Beginner',
            str_contains($text, 'elementary') || str_contains($text, 'a2') => 'Elementary',
            str_contains($text, 'intermediate') || str_contains($text, 'b1') || str_contains($text, 'b2') => 'Intermediate',
            str_contains($text, 'advanced') || str_contains($text, 'c1') || str_contains($text, 'c2') => 'Advanced',
            str_contains($text, 'kids') || str_contains($text, 'child') => 'Kids',
            default => 'Mixed',
        };
    }

    protected function resolveCapacity(array $appointmentData): ?int
    {
        $candidates = [
            data_get($appointmentData, 'maxAttendees'),
            data_get($appointmentData, 'capacity'),
            data_get($appointmentData, 'maxClients'),
            data_get($appointmentData, 'slots'),
            data_get($appointmentData, 'max_participants'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                $value = (int) $candidate;
                if ($value > 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    protected function slug(?string $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'na';
        }

        return Str::slug($value);
    }
}
