<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lumina_works_employer_verifications', function (Blueprint $table) {
            $table->id();
            // Explicit short FK name — the auto-generated one exceeds MySQL's
            // 64-char identifier limit (caused the failed prod deploy 2026-07-26).
            $table->foreignId('lumina_works_application_id')
                ->constrained(table: 'lumina_works_applications', indexName: 'lw_employer_verif_application_fk')
                ->cascadeOnDelete();
            $table->string('employer_name');
            $table->string('contact_name')->nullable();
            $table->string('result', 16)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index('lumina_works_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lumina_works_employer_verifications');
    }
};
