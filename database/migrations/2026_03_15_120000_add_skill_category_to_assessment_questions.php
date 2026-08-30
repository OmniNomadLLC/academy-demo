<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_questions', function (Blueprint $table) {
            if (! Schema::hasColumn('assessment_questions', 'skill_category')) {
                $table->string('skill_category', 64)->nullable()->after('section');
                $table->index('skill_category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assessment_questions', function (Blueprint $table) {
            if (Schema::hasColumn('assessment_questions', 'skill_category')) {
                $table->dropIndex(['skill_category']);
                $table->dropColumn('skill_category');
            }
        });
    }
};
