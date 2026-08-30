<?php

namespace App\Support\Concerns;

use Illuminate\Support\Facades\DB;

trait InterpretsAcuityFields
{
    protected function dbDriver(): string
    {
        return DB::getDriverName();
    }

    protected function calendarExpr(): string
    {
        $driver = $this->dbDriver();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $calendar = "JSON_UNQUOTE(COALESCE(\n                JSON_EXTRACT(acuity_data, '$.calendar'),\n                JSON_EXTRACT(acuity_data, '$.calendarName'),\n                JSON_EXTRACT(acuity_data, '$.calendar.name'),\n                JSON_EXTRACT(acuity_data, '$.Calendar'),\n                JSON_EXTRACT(acuity_data, '$.CalendarName')\n            ))";
        } elseif ($driver === 'pgsql') {
            $calendar = "COALESCE(\n                (acuity_data->>'calendar'),\n                (acuity_data->>'calendarName'),\n                ((acuity_data->'calendar')->>'name'),\n                (acuity_data->>'Calendar'),\n                (acuity_data->>'CalendarName')\n            )";
        } else {
            $calendar = "COALESCE(\n                json_extract(acuity_data, '$.calendar'),\n                json_extract(acuity_data, '$.calendarName'),\n                json_extract(acuity_data, '$.calendar.name'),\n                json_extract(acuity_data, '$.Calendar'),\n                json_extract(acuity_data, '$.CalendarName')\n            )";
        }

        return "LOWER(TRIM(COALESCE(calendar_norm, $calendar)))";
    }

    protected function categoryExpr(): string
    {
        $driver = $this->dbDriver();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $category = "JSON_UNQUOTE(COALESCE(\n                JSON_EXTRACT(acuity_data, '$.category'),\n                JSON_EXTRACT(acuity_data, '$.Category')\n            ))";
        } elseif ($driver === 'pgsql') {
            $category = "COALESCE((acuity_data->>'category'), (acuity_data->>'Category'))";
        } else {
            $category = "COALESCE(\n                json_extract(acuity_data, '$.category'),\n                json_extract(acuity_data, '$.Category')\n            )";
        }

        return "LOWER(TRIM(COALESCE(category_norm, $category)))";
    }

    protected function appointmentTypeIdExpr(): string
    {
        $driver = $this->dbDriver();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return "JSON_UNQUOTE(COALESCE(\n                JSON_EXTRACT(acuity_data, '$.appointmentTypeID'),\n                JSON_EXTRACT(acuity_data, '$.appointmentTypeId'),\n                JSON_EXTRACT(acuity_data, '$.appointmentType.id'),\n                JSON_EXTRACT(acuity_data, '$.typeID'),\n                JSON_EXTRACT(acuity_data, '$.TypeID')\n            ))";
        }

        if ($driver === 'pgsql') {
            return "COALESCE(\n                (acuity_data->>'appointmentTypeID'),\n                (acuity_data->>'appointmentTypeId'),\n                ((acuity_data->'appointmentType')->>'id'),\n                (acuity_data->>'typeID'),\n                (acuity_data->>'TypeID')\n            )";
        }

        return "COALESCE(\n            json_extract(acuity_data, '$.appointmentTypeID'),\n            json_extract(acuity_data, '$.appointmentTypeId'),\n            json_extract(acuity_data, '$.appointmentType.id'),\n            json_extract(acuity_data, '$.typeID'),\n            json_extract(acuity_data, '$.TypeID')\n        )";
    }

    protected function appointmentTypeLabelExpr(): string
    {
        $driver = $this->dbDriver();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return "JSON_UNQUOTE(COALESCE(\n                JSON_EXTRACT(acuity_data, '$.appointmentType'),\n                JSON_EXTRACT(acuity_data, '$.appointmentType.name'),\n                JSON_EXTRACT(acuity_data, '$.type'),\n                JSON_EXTRACT(acuity_data, '$.Type')\n            ))";
        }

        if ($driver === 'pgsql') {
            return "COALESCE(\n                (acuity_data->>'appointmentType'),\n                ((acuity_data->'appointmentType')->>'name'),\n                (acuity_data->>'type'),\n                (acuity_data->>'Type')\n            )";
        }

        return "COALESCE(\n            json_extract(acuity_data, '$.appointmentType'),\n            json_extract(acuity_data, '$.appointmentType.name'),\n            json_extract(acuity_data, '$.type'),\n            json_extract(acuity_data, '$.Type')\n        )";
    }
}
