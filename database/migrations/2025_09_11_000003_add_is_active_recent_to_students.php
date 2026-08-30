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
            if (!Schema::hasColumn('students', 'is_active_recent')) {
                $table->boolean('is_active_recent')->default(false)->after('next_appointment_date');
                $table->index('is_active_recent');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('students')) return;
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'is_active_recent')) {
                $table->dropIndex(['is_active_recent']);
                $table->dropColumn('is_active_recent');
            }
        });
    }
};

