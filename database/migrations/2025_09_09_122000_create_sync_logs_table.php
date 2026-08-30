<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('command');
            $table->json('params')->nullable();
            $table->string('status')->default('running'); // running|success|error
            $table->longText('output')->nullable();
            $table->unsignedBigInteger('ran_by')->nullable();
            $table->timestamps();
            $table->index(['command', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};

