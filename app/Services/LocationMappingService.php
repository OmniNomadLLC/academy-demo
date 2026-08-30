<?php

namespace App\Services;

class LocationMappingService
{
    protected static $categoryLocationMap = [
        // UK Categories - based on Acuity category structure
        '6. Southbank' => 'UK',
        '4. Harbour Online' => 'UK', 
        '2. Northgate' => 'UK',
        '3. Northgate Online' => 'UK',
        '5. Parkside' => 'UK',
        '1. Riverside' => 'UK',
        'Riverside' => 'UK',
        'Northgate' => 'UK',
        'Parkside' => 'UK',
        'Southbank' => 'UK',
        'Harbour Online' => 'UK',

        // Internal ops should stay in Academic view only
        'Internal Meetings' => 'Academic',
        
        // Spain Categories - exact matches from Acuity  
        'English' => 'Spain',        
        'Spanish' => 'Spain',
        'French' => 'Spain', 
        'German' => 'Spain',
        'Italian' => 'Spain',
        'Lebanese' => 'Spain',
        'BNI' => 'Spain',
        'Marina' => 'Spain',
        
        // France Categories
        'CPF' => 'France',
    ];

    // Cached normalized mapping built from $categoryLocationMap
    protected static ?array $normalizedMap = null;

    public static function normalizeCategory(?string $name): ?string
    {
        if ($name === null) return null;
        $name = trim($name);
        // Strip leading digits and punctuation like "1. ", "2) ", etc.
        $name = preg_replace('/^\s*\d+[\.)-]?\s*/', '', $name);
        // Collapse whitespace and lowercase
        $name = strtolower(preg_replace('/\s+/', ' ', $name));
        return $name;
    }

    protected static function buildNormalizedMap(): void
    {
        if (self::$normalizedMap !== null) return;
        self::$normalizedMap = [];
        foreach (self::$categoryLocationMap as $key => $region) {
            $norm = self::normalizeCategory($key);
            if ($norm !== null && $norm !== '') {
                self::$normalizedMap[$norm] = $region;
            }
        }
    }

    public static function regionForCategory(?string $category): ?string
    {
        if ($category === null) return null;
        self::buildNormalizedMap();
        $norm = self::normalizeCategory($category);
        if ($norm === null || $norm === '') return null;

        // Exact normalized match first
        if (isset(self::$normalizedMap[$norm])) {
            return self::$normalizedMap[$norm];
        }
        // Partial matching as fallback (normalized)
        foreach (self::$normalizedMap as $key => $region) {
            if (stripos($norm, $key) !== false) {
                return $region;
            }
        }
        return null;
    }

    public static function isOnlineCategory(?string $category): bool
    {
        if ($category === null) {
            return false;
        }

        $norm = self::normalizeCategory($category);

        return is_string($norm) && $norm !== '' && str_contains($norm, 'online');
    }

    public static function getLocationFromCategory(string $category): string
    {
        // Backwards-compatible API: default to 'UK' if not mapped
        return self::regionForCategory($category) ?? 'UK';
    }
    
    public static function getAllLocations(): array
    {
        return ['UK', 'Spain', 'France', 'Academic'];
    }

    public static function keywordsForRegion(string $region): array
    {
        $map = [
            'UK' => ['Riverside', 'Northgate', 'Northgate Online', 'Parkside', 'Southbank', 'Harbour', 'Harbour Online'],
            'Spain' => [
                'English', 'Spanish', 'German', 'Italian', 'Lebanese', 'BNI', 'Marina', 'French',
                // Broader Spain markers seen in categories
                'ES', 'FLEX', 'Duo', 'Adults', 'Business', 'Oracle',
            ],
            'France' => ['CPF'],
            'Academic' => [],
        ];
        $keys = $map[$region] ?? [];
        // Normalize like category_norm
        return array_values(array_unique(array_map(function ($v) {
            return self::normalizeCategory($v);
        }, $keys)));
    }
}
