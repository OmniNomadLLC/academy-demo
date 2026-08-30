<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // String rather than enum: later migrations widen the role set
            // (manager, super_admin, ...) and 2025_09_05_203000 converts the
            // MySQL enum to a string anyway. Defining it as a string here keeps
            // SQLite (whose emulated enums become CHECK constraints that cannot
            // be widened in place) on the same final shape.
            $table->string('role', 32)->default('teacher');
            $table->string('acuity_calendar_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'acuity_calendar_id', 'is_active', 'last_login_at']);
        });
    }
};