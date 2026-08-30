<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DbExpressions
{
    public static function castToString(string $expression): string
    {
        $expression = trim($expression);
        $normalized = strtolower($expression);

        $driver = DB::connection()->getDriverName();

        // When dealing with JSON_EXTRACT values we need to unwrap the quoted payloads
        // so string comparisons (`=`/`IN`) behave consistently across drivers.
        if (str_contains($normalized, 'json_extract') && ! str_contains($normalized, 'json_unquote')) {
            $expression = match ($driver) {
                'mysql', 'mariadb' => 'JSON_UNQUOTE(' . $expression . ')',
                'sqlite' => "TRIM($expression, '\"')",
                default => $expression,
            };
        }

        $target = match ($driver) {
            'mysql', 'mariadb' => 'CHAR',
            'sqlsrv' => 'NVARCHAR(MAX)',
            default => 'TEXT',
        };

        return "CAST($expression AS $target)";
    }
}
