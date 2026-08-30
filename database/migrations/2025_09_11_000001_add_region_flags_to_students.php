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
            if (!Schema::hasColumn('students', 'in_uk')) {
                $table->boolean('in_uk')->default(false)->after('location');
                $table->index('in_uk');
            }
            if (!Schema::hasColumn('students', 'in_spain')) {
                $table->boolean('in_spain')->default(false)->after('in_uk');
                $table->index('in_spain');
            }
            if (!Schema::hasColumn('students', 'in_france')) {
                $table->boolean('in_france')->default(false)->after('in_spain');
                $table->index('in_france');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('students')) return;
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'in_france')) {
                $table->dropIndex(['in_france']);
                $table->dropColumn('in_france');
            }
            if (Schema::hasColumn('students', 'in_spain')) {
                $table->dropIndex(['in_spain']);
                $table->dropColumn('in_spain');
            }
            if (Schema::hasColumn('students', 'in_uk')) {
                $table->dropIndex(['in_uk']);
                $table->dropColumn('in_uk');
            }
        });
    }
};

