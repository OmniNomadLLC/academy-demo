<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            $table->string('status')->default('draft')->after('overall_comments');
            $table->timestamp('locked_at')->nullable()->after('status');
        });

        DB::table('student_assessments')->update([
            'status' => 'final',
            'locked_at' => DB::raw('assessed_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            $table->dropColumn(['locked_at', 'status']);
        });
    }
};
