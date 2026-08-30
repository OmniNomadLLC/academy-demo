<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_location_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_location_id')->constrained('class_locations')->cascadeOnDelete();
            $table->string('calendar_slug');
            $table->string('calendar_norm')->nullable();
            $table->string('calendar_name')->nullable();
            $table->string('region')->nullable()->default('UK');
            $table->timestamps();

            $table->unique('calendar_slug');
            $table->index('calendar_norm');
            $table->index(['class_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_location_calendars');
    }
};
