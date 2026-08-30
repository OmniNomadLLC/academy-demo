<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_classes')) {
            return;
        }

        Schema::table('school_classes', function (Blueprint $table) {
            try {
                $table->index(['external_source', 'external_id', 'id'], 'school_classes_signature_index');
            } catch (\Throwable $e) {
                // Index already exists or driver doesn't support; ignore.
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('school_classes')) {
            return;
        }

        Schema::table('school_classes', function (Blueprint $table) {
            try {
                $table->dropIndex('school_classes_signature_index');
            } catch (\Throwable $e) {
                // Index missing; ignore.
            }
        });
    }
};
