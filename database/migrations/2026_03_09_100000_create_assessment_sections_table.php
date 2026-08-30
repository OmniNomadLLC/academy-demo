<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_template_id')
                ->constrained('assessment_templates')
                ->cascadeOnDelete();
            $table->string('name');
            $table->integer('sort_order');
            $table->decimal('weight', 5, 2)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('assessment_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_sections');
    }
};
