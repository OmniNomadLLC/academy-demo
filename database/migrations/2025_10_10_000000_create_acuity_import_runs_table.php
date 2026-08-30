<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acuity_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->date('window_start');
            $table->date('window_end');
            $table->unsignedTinyInteger('slice_days')->default(7);
            $table->unsignedSmallInteger('page_size')->default(100);
            $table->unsignedTinyInteger('max_retries')->default(5);
            $table->unsignedSmallInteger('retry_base_ms')->default(500);
            $table->unsignedInteger('limit')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->boolean('link_after_slice')->default(false);
            $table->unsignedInteger('total_slices')->default(0);
            $table->unsignedInteger('processed_slices')->default(0);
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unlinked_count')->default(0);
            $table->unsignedInteger('matched_email_count')->default(0);
            $table->unsignedInteger('matched_id_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('retries')->default(0);
            $table->timestamp('next_cursor')->nullable();
            $table->timestamp('current_slice_start')->nullable();
            $table->timestamp('current_slice_end')->nullable();
            $table->unsignedInteger('current_slice_index')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('queued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acuity_import_runs');
    }
};
