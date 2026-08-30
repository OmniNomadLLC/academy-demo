<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'acuity_category')) {
                $table->string('acuity_category')->nullable()->after('location');
            }
        });

        // Make location nullable if the column exists
        if (Schema::hasColumn('students', 'location')) {
            Schema::table('students', function (Blueprint $table) {
                $table->string('location')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'acuity_category')) {
                $table->dropColumn('acuity_category');
            }
        });
        // We won't force location non-nullable on down to avoid data loss
    }
};

