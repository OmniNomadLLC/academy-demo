<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Student;
use App\Support\DbExpressions;

class BackfillStudentsFromSessions extends Command
{
    protected $signature = 'students:backfill-from-sessions {--limit=0}';
    protected $description = 'Create/update Student records by extracting distinct clients from class_sessions.acuity_data';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $clientIdExpr = "COALESCE(\n            json_extract(class_sessions.acuity_data, '$.clientID'),\n            json_extract(class_sessions.acuity_data, '$.clientId'),\n            json_extract(class_sessions.acuity_data, '$.client.id'),\n            json_extract(class_sessions.acuity_data, '$.client_id'),\n            json_extract(class_sessions.acuity_data, '$.ClientID'),\n            json_extract(class_sessions.acuity_data, '$.ClientId'),\n            json_extract(class_sessions.acuity_data, '$.Client.id'),\n            json_extract(class_sessions.acuity_data, '$.Client_id')\n        )";
        $emailExpr = "LOWER(COALESCE(\n            json_extract(class_sessions.acuity_data, '$.email'),\n            json_extract(class_sessions.acuity_data, '$.client.email'),\n            json_extract(class_sessions.acuity_data, '$.Client.email')\n        ))";
        $firstNameExpr = "COALESCE(\n            json_extract(class_sessions.acuity_data, '$.firstName'),\n            json_extract(class_sessions.acuity_data, '$.client.firstName'),\n            json_extract(class_sessions.acuity_data, '$.Client.firstName')\n        )";
        $lastNameExpr = "COALESCE(\n            json_extract(class_sessions.acuity_data, '$.lastName'),\n            json_extract(class_sessions.acuity_data, '$.client.lastName'),\n            json_extract(class_sessions.acuity_data, '$.Client.lastName')\n        )";
        $categoryExpr = "COALESCE(\n            json_extract(class_sessions.acuity_data, '$.category'),\n            json_extract(class_sessions.acuity_data, '$.Category')\n        )";
        $phoneExpr = "COALESCE(\n            json_extract(class_sessions.acuity_data, '$.phone'),\n            json_extract(class_sessions.acuity_data, '$.client.phone'),\n            json_extract(class_sessions.acuity_data, '$.Client.phone')\n        )";

        $castClientId = DbExpressions::castToString($clientIdExpr);

        $q = DB::table('class_sessions')
            ->selectRaw("DISTINCT $castClientId as client_id, $emailExpr as email, $firstNameExpr as first_name, $lastNameExpr as last_name, $categoryExpr as category, $phoneExpr as phone")
            ->whereNotNull('acuity_data');
        if ($limit > 0) $q->limit($limit);
        $rows = $q->get();

        $created = 0; $updated = 0; $skipped = 0;
        foreach ($rows as $r) {
            $cid = $r->client_id ? trim(str_replace('"','', (string) $r->client_id)) : null;
            $email = $r->email ? trim(str_replace('"','', (string) $r->email)) : null;
            if (!$cid && !$email) { $skipped++; continue; }
            $first = $r->first_name ? trim(str_replace('"','', (string) $r->first_name)) : '';
            $last = $r->last_name ? trim(str_replace('"','', (string) $r->last_name)) : '';
            $phone = $r->phone ? trim(str_replace('"','', (string) $r->phone)) : null;
            $category = $r->category ? trim(str_replace('"','', (string) $r->category)) : null;

            $student = null;
            if ($cid) { $student = Student::where('acuity_client_id', (string) $cid)->first(); }
            if (!$student && $email) { $student = Student::whereRaw('LOWER(email) = ?', [strtolower($email)])->first(); }

            if (!$student) {
                $student = new Student();
                $student->acuity_client_id = $cid ?: null;
                $student->email = $email ?: null;
                $created++;
            } else {
                $updated++;
            }
            $student->first_name = $first ?: $student->first_name;
            $student->last_name = $last ?: $student->last_name;
            $student->phone = $phone ?: $student->phone;
            $student->registration_date = $student->registration_date ?: now()->toDateString();
            $student->is_active = $student->is_active ?? true;
            $student->setAcuityCategoryAndLocation($category);
            $student->save();
        }

        $this->info("Backfill complete. created={$created} updated={$updated} skipped={$skipped}");
        return self::SUCCESS;
    }
}
