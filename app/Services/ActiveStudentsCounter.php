<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Student;
use Illuminate\Support\Facades\Cache;

class ActiveStudentsCounter
{
    public function countForRegion(?string $region): int
    {
        $key = 'dash_kpi_active_students_v5_'.($region ? strtolower($region) : 'all');

        return Cache::remember($key, 120, function () use ($region) {
            $today = today()->toDateString();
            $future = today()->addDays(45)->toDateString();

            $query = ClassSession::query()
                ->whereBetween('session_date', [$today, $future])
                ->where(function ($w) {
                    $w->where('canceled', false)->orWhereNull('canceled');
                })
                ->where(function ($w) {
                    $w->where('status', '!=', 'cancelled')->orWhereNull('status');
                })
                ->whereNotNull('student_id')
                ->whereIn('student_id', Student::excludingArchived()->select('id'));

            if ($region) {
                $query->whereRaw('LOWER(location) = ?', [strtolower($region)]);
            }

            return (int) $query->distinct('student_id')->count('student_id');
        });
    }
}
