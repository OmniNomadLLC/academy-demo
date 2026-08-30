<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'is_online')) {
                $table->boolean('is_online')->default(false)->after('location');
            }
        });

        DB::table('students')
            ->where(function ($query) {
                $query->where('location', 'UK')
                    ->orWhereRaw('LOWER(location) = ?', ['uk']);
            })
            ->update(['is_online' => false]);

        DB::table('students')
            ->where(function ($query) {
                $query->where('location', 'UK')
                    ->orWhereRaw('LOWER(location) = ?', ['uk']);
            })
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('class_sessions')
                    ->whereColumn('class_sessions.student_id', 'students.id')
                    ->whereRaw("LOWER(COALESCE(class_sessions.category_norm, '')) LIKE '%online%'");
            })
            ->update(['is_online' => true]);

        DB::table('students')
            ->where(function ($query) {
                $query->where('location', 'UK')
                    ->orWhereRaw('LOWER(location) = ?', ['uk']);
            })
            ->where('is_online', false)
            ->whereNotNull('acuity_category')
            ->whereRaw("LOWER(acuity_category) LIKE '%online%'")
            ->update(['is_online' => true]);
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'is_online')) {
                $table->dropColumn('is_online');
            }
        });
    }
};
