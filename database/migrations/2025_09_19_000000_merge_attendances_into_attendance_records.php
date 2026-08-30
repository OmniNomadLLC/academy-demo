<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_records')) {
            return;
        }

        if (! Schema::hasColumn('attendance_records', 'sent_at')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->timestamp('sent_at')->nullable()->after('marked_at');
                $table->index('sent_at');
            });
        }

        if (! Schema::hasTable('attendances')) {
            return;
        }

        DB::table('attendances')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $existing = DB::table('attendance_records')
                        ->where('class_session_id', $row->class_session_id)
                        ->where('student_id', $row->student_id)
                        ->first();

                    $status = in_array($row->status, ['present', 'late', 'absent', 'cancelled'], true)
                        ? $row->status
                        : 'present';

                    $timestamp = $row->updated_at ?? $row->marked_at ?? $row->created_at ?? now();

                    if ($existing) {
                        $update = [
                            'status' => $status,
                            'marked_by' => $row->marked_by,
                            'updated_at' => $timestamp,
                        ];

                        if ($row->marked_at) {
                            $update['marked_at'] = $row->marked_at;
                        }

                        if (! is_null($row->sent_at)) {
                            $update['sent_at'] = $row->sent_at;
                        }

                        DB::table('attendance_records')
                            ->where('id', $existing->id)
                            ->update($update);
                    } else {
                        DB::table('attendance_records')->insert([
                            'class_session_id' => $row->class_session_id,
                            'student_id' => $row->student_id,
                            'status' => $status,
                            'marked_by' => $row->marked_by,
                            'marked_at' => $row->marked_at,
                            'sent_at' => $row->sent_at,
                            'notes' => null,
                            'created_at' => $row->created_at ?? $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                    }
                }
            });

        Schema::dropIfExists('attendances');
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_records')) {
            return;
        }

        if (! Schema::hasTable('attendances')) {
            Schema::create('attendances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('class_session_id');
                $table->unsignedBigInteger('student_id');
                $table->enum('status', ['present', 'late', 'absent'])->index();
                $table->unsignedBigInteger('marked_by')->nullable();
                $table->timestamp('marked_at')->useCurrent();
                $table->timestamp('sent_at')->nullable();
                $table->string('region', 16)->default('UK');
                $table->timestamps();

                $table->unique(['class_session_id', 'student_id']);
                $table->index('class_session_id');
                $table->index('student_id');

                $table->foreign('class_session_id')->references('id')->on('class_sessions')->cascadeOnDelete();
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
                $table->foreign('marked_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        DB::table('attendance_records')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $region = DB::table('class_sessions')
                        ->where('id', $row->class_session_id)
                        ->value('location');

                    if (! in_array($row->status, ['present', 'late', 'absent'], true)) {
                        $status = 'present';
                    } else {
                        $status = $row->status;
                    }

                    DB::table('attendances')->updateOrInsert(
                        [
                            'class_session_id' => $row->class_session_id,
                            'student_id' => $row->student_id,
                        ],
                        [
                            'status' => $status,
                            'marked_by' => $row->marked_by,
                            'marked_at' => $row->marked_at ?? $row->created_at ?? now(),
                            'sent_at' => $row->sent_at,
                            'region' => $region ?: 'UK',
                            'updated_at' => $row->updated_at ?? now(),
                            'created_at' => $row->created_at ?? now(),
                        ]
                    );
                }
            });

        if (Schema::hasColumn('attendance_records', 'sent_at')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->dropColumn('sent_at');
            });
        }
    }
};
