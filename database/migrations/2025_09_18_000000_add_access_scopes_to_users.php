<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'access_regions')) {
                $table->json('access_regions')->nullable()->after('preferred_region');
            }

            if (! Schema::hasColumn('users', 'access_domains')) {
                $table->json('access_domains')->nullable()->after('access_regions');
            }

            if (! Schema::hasColumn('users', 'teacher_scope')) {
                $table->string('teacher_scope', 48)->nullable()->after('role');
            }

            if (! Schema::hasColumn('users', 'manager_scope')) {
                $table->string('manager_scope', 48)->nullable()->after('teacher_scope');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'manager_scope')) {
                $table->dropColumn('manager_scope');
            }

            if (Schema::hasColumn('users', 'teacher_scope')) {
                $table->dropColumn('teacher_scope');
            }

            if (Schema::hasColumn('users', 'access_domains')) {
                $table->dropColumn('access_domains');
            }

            if (Schema::hasColumn('users', 'access_regions')) {
                $table->dropColumn('access_regions');
            }
        });
    }
};
