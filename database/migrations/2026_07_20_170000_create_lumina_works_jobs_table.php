<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lumina_works_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->default('adzuna');
            $table->string('external_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('employer_name')->nullable();
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('region', 64)->nullable();
            $table->string('category', 128)->nullable();
            $table->string('contract_time', 16)->nullable();
            $table->string('contract_type', 16)->nullable();
            $table->decimal('salary_min', 10, 2)->nullable();
            $table->decimal('salary_max', 10, 2)->nullable();
            $table->string('apply_url', 2048);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('english_level_estimate', 16)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['region', 'contract_time']);
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lumina_works_jobs');
    }
};
