<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('class_sessions', 'category_norm')) {
                $table->string('category_norm')->nullable()->after('acuity_data');
                $table->index('category_norm');
            }
        });

        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'first_appointment_date')) {
                $table->date('first_appointment_date')->nullable()->after('registration_date');
                $table->index('first_appointment_date');
            }
            if (!Schema::hasColumn('students', 'last_appointment_date')) {
                $table->date('last_appointment_date')->nullable()->after('first_appointment_date');
                $table->index('last_appointment_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('class_sessions', 'category_norm')) {
                $table->dropIndex(['category_norm']);
                $table->dropColumn('category_norm');
            }
        });

        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'first_appointment_date')) {
                $table->dropIndex(['first_appointment_date']);
                $table->dropColumn('first_appointment_date');
            }
            if (Schema::hasColumn('students', 'last_appointment_date')) {
                $table->dropIndex(['last_appointment_date']);
                $table->dropColumn('last_appointment_date');
            }
        });
    }
};

