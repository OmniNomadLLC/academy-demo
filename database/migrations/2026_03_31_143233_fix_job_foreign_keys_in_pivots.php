<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_interest')) {
            Schema::table('job_interest', function (Blueprint $table) {
                $table->dropForeign(['job_id']);
                $table->foreign('job_id')
                    ->references('id')
                    ->on('job_listings')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('job_availability')) {
            Schema::table('job_availability', function (Blueprint $table) {
                $table->dropForeign(['job_id']);
                $table->foreign('job_id')
                    ->references('id')
                    ->on('job_listings')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('job_interest')) {
            Schema::table('job_interest', function (Blueprint $table) {
                $table->dropForeign(['job_id']);
                $table->foreign('job_id')
                    ->references('id')
                    ->on('jobs')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('job_availability')) {
            Schema::table('job_availability', function (Blueprint $table) {
                $table->dropForeign(['job_id']);
                $table->foreign('job_id')
                    ->references('id')
                    ->on('jobs')
                    ->cascadeOnDelete();
            });
        }
    }
};
