<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorials', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();
            $table->enum('content_type', ['pdf', 'article'])->default('pdf');
            $table->string('file_path')->nullable();
            $table->longText('content')->nullable();
            $table->json('visible_to_roles');
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('sort_order');
            $table->index('category');
            $table->index('content_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorials');
    }
};
