<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('enrollment_last_sent_at')->nullable()->after('notes');
            $table->string('enrollment_last_channel')->nullable()->after('enrollment_last_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['enrollment_last_sent_at', 'enrollment_last_channel']);
        });
    }
};
