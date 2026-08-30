<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_assessment_items', function (Blueprint $table) {
            $table->string('skill_category', 64)->nullable()->after('section_name');
            $table->index('skill_category');
        });
    }

    public function down(): void
    {
        Schema::table('student_assessment_items', function (Blueprint $table) {
            $table->dropIndex(['skill_category']);
            $table->dropColumn('skill_category');
        });
    }
};
