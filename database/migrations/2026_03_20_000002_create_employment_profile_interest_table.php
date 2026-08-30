<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_profile_interest', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employment_profile_id')->constrained('employment_profiles')->cascadeOnDelete();
            $table->foreignId('employment_interest_id')->constrained('employment_interests')->cascadeOnDelete();

            $table->unique(['employment_profile_id', 'employment_interest_id'], 'employment_profile_interest_unique');
            $table->index('employment_profile_id');
            $table->index('employment_interest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_profile_interest');
    }
};
