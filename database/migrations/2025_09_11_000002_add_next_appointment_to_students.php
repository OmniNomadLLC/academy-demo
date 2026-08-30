<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('students')) return;
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'next_appointment_date')) {
                $table->date('next_appointment_date')->nullable()->after('last_appointment_date');
                $table->index('next_appointment_date');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('students')) return;
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'next_appointment_date')) {
                $table->dropIndex(['next_appointment_date']);
                $table->dropColumn('next_appointment_date');
            }
        });
    }
};

