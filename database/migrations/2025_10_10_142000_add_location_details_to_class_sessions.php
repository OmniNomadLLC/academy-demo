<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('class_sessions', 'class_location_id')) {
                $table->foreignId('class_location_id')
                    ->nullable()
                    ->after('school_class_id')
                    ->constrained('class_locations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('class_sessions', 'venue_name')) {
                $table->string('venue_name')->nullable()->after('location');
            }

            if (! Schema::hasColumn('class_sessions', 'venue_address')) {
                $table->text('venue_address')->nullable()->after('venue_name');
            }

            if (! Schema::hasColumn('class_sessions', 'is_virtual')) {
                $table->boolean('is_virtual')->default(false)->after('venue_address');
            }

            if (! Schema::hasColumn('class_sessions', 'virtual_meeting_url')) {
                $table->string('virtual_meeting_url')->nullable()->after('is_virtual');
            }

            if (! Schema::hasColumn('class_sessions', 'virtual_meeting_room')) {
                $table->string('virtual_meeting_room')->nullable()->after('virtual_meeting_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('class_sessions', 'virtual_meeting_room')) {
                $table->dropColumn('virtual_meeting_room');
            }
            if (Schema::hasColumn('class_sessions', 'virtual_meeting_url')) {
                $table->dropColumn('virtual_meeting_url');
            }
            if (Schema::hasColumn('class_sessions', 'is_virtual')) {
                $table->dropColumn('is_virtual');
            }
            if (Schema::hasColumn('class_sessions', 'venue_address')) {
                $table->dropColumn('venue_address');
            }
                if (Schema::hasColumn('class_sessions', 'venue_name')) {
                $table->dropColumn('venue_name');
            }
            if (Schema::hasColumn('class_sessions', 'class_location_id')) {
                $table->dropConstrainedForeignId('class_location_id');
            }
        });
    }
};
