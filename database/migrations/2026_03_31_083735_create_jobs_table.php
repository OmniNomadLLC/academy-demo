<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('jobs')) {
            Schema::table('jobs', function (Blueprint $table) {
                if (! Schema::hasColumn('jobs', 'title')) {
                    $table->string('title')->after('id');
                }

                if (! Schema::hasColumn('jobs', 'preferred_hours')) {
                    $table->enum('preferred_hours', ['full_time', 'part_time', 'either'])
                        ->default('either')
                        ->after('title');
                }

                if (! Schema::hasColumn('jobs', 'requires_experience')) {
                    $table->boolean('requires_experience')->default(false)->after('preferred_hours');
                }
            });

            return;
        }

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->enum('preferred_hours', ['full_time', 'part_time', 'either'])->default('either');
            $table->boolean('requires_experience')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
