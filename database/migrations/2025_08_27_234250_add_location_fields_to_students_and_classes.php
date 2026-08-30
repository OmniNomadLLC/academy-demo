<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('location')->default('UK')->after('email');
        });

        Schema::table('school_classes', function (Blueprint $table) {
            $table->string('location')->default('UK')->after('language');
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            // Do not rely on a prior 'calendar_name' column that may not exist in fresh installs
            $table->string('location')->default('UK');
        });
    }

    public function down()
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('location');
        });
        
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn('location');
        });
        
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
