<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'preferred_region')) {
                $table->string('preferred_region', 32)->nullable()->after('timezone');
                $table->index('preferred_region', 'users_preferred_region_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'preferred_region')) {
                $table->dropIndex('users_preferred_region_idx');
                $table->dropColumn('preferred_region');
            }
        });
    }
};

