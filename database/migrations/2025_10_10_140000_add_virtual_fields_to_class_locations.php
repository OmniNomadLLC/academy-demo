<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_locations', function (Blueprint $table) {
            if (! Schema::hasColumn('class_locations', 'region')) {
                $table->string('region')->default('UK')->after('country');
            }

            if (! Schema::hasColumn('class_locations', 'is_virtual')) {
                $table->boolean('is_virtual')->default(false)->after('region');
            }

            if (! Schema::hasColumn('class_locations', 'virtual_meeting_url')) {
                $table->string('virtual_meeting_url')->nullable()->after('is_virtual');
            }

            if (! Schema::hasColumn('class_locations', 'virtual_meeting_room')) {
                $table->string('virtual_meeting_room')->nullable()->after('virtual_meeting_url');
            }

            if (! Schema::hasColumn('class_locations', 'notes')) {
                $table->text('notes')->nullable()->after('virtual_meeting_room');
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_locations', function (Blueprint $table) {
            if (Schema::hasColumn('class_locations', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('class_locations', 'virtual_meeting_room')) {
                $table->dropColumn('virtual_meeting_room');
            }
            if (Schema::hasColumn('class_locations', 'virtual_meeting_url')) {
                $table->dropColumn('virtual_meeting_url');
            }
            if (Schema::hasColumn('class_locations', 'is_virtual')) {
                $table->dropColumn('is_virtual');
            }
            if (Schema::hasColumn('class_locations', 'region')) {
                $table->dropColumn('region');
            }
        });
    }
};
