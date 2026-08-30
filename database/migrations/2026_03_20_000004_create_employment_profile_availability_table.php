<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('employment_profile_availability');

        Schema::create('employment_profile_availability', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employment_profile_id');
            $table->unsignedBigInteger('employment_availability_option_id');

            $table->foreign('employment_profile_id', 'epa_profile_fk')
                ->references('id')
                ->on('employment_profiles')
                ->cascadeOnDelete();

            $table->foreign('employment_availability_option_id', 'epa_availability_fk')
                ->references('id')
                ->on('employment_availability_options')
                ->cascadeOnDelete();

            $table->unique(['employment_profile_id', 'employment_availability_option_id'], 'employment_profile_availability_unique');
            $table->index('employment_profile_id', 'epa_profile_idx');
            $table->index('employment_availability_option_id', 'epa_availability_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_profile_availability');
    }
};
