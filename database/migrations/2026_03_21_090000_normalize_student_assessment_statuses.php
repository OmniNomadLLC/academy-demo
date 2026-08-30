<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('student_assessments')
            ->where('status', 'completed')
            ->update(['status' => 'final']);
    }

    public function down(): void
    {
        // No rollback — legacy 'completed' statuses are unsupported on purpose.
    }
};
