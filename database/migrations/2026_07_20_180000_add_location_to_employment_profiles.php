<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_profiles', function (Blueprint $table) {
            $table->string('postcode', 10)->nullable()->after('preferred_hours');
            $table->decimal('latitude', 10, 7)->nullable()->after('postcode');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedSmallInteger('max_travel_km')->default(15)->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('employment_profiles', function (Blueprint $table) {
            $table->dropColumn(['postcode', 'latitude', 'longitude', 'max_travel_km']);
        });
    }
};
