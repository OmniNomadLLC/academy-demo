<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('jobs', 'title')) {
                $table->string('title')->after('id');
            }

            if (! Schema::hasColumn('jobs', 'preferred_hours')) {
                $table->enum('preferred_hours', ['full_time', 'part_time', 'either'])->default('either')->after('title');
            }

            if (! Schema::hasColumn('jobs', 'requires_experience')) {
                $table->boolean('requires_experience')->default(false)->after('preferred_hours');
            }

            if (! Schema::hasColumn('jobs', 'created_at') && ! Schema::hasColumn('jobs', 'updated_at')) {
                $table->timestamps();
            } elseif (! Schema::hasColumn('jobs', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            } elseif (! Schema::hasColumn('jobs', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            if (Schema::hasColumn('jobs', 'requires_experience')) {
                $table->dropColumn('requires_experience');
            }

            if (Schema::hasColumn('jobs', 'preferred_hours')) {
                $table->dropColumn('preferred_hours');
            }

            if (Schema::hasColumn('jobs', 'title')) {
                $table->dropColumn('title');
            }

            if (Schema::hasColumn('jobs', 'created_at')) {
                $table->dropColumn('created_at');
            }

            if (Schema::hasColumn('jobs', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};
