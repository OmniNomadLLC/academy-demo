<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

class StudentAttendanceCard extends Component
{
    public int $studentId;

    public float $rate = 0.0;
    public array $totals = [
        'present' => 0,
        'late' => 0,
        'absent' => 0,
        'total' => 0,
    ];

    public function mount(int $studentId): void
    {
        $this->studentId = $studentId;
        $this->refreshData();
    }

    #[On('attendance-updated')]
    public function handleAttendanceUpdated(int $studentId): void
    {
        if ($studentId === $this->studentId) {
            $this->refreshData();
        }
    }

    public function refreshData(): void
    {
        $query = DB::table('attendance_records as ar')
            ->join('class_sessions as cs', 'cs.id', '=', 'ar.class_session_id')
            ->where('ar.student_id', $this->studentId)
            ->whereIn('ar.status', ['present', 'late', 'absent']);

        \App\Models\ClassSession::applyExcludeAssessmentsToQuery($query, 'cs');

        $stats = $query
            ->selectRaw("COUNT(DISTINCT CASE WHEN ar.status = 'present' THEN ar.class_session_id END) as present_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN ar.status = 'late' THEN ar.class_session_id END) as late_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN ar.status = 'absent' THEN ar.class_session_id END) as absent_count")
            ->selectRaw('COUNT(DISTINCT ar.class_session_id) as session_count')
            ->first();

        $present = (int) ($stats->present_count ?? 0);
        $late = (int) ($stats->late_count ?? 0);
        $absent = (int) ($stats->absent_count ?? 0);
        $total = (int) ($stats->session_count ?? 0);
        $attended = $present + $late;
        $this->rate = $total > 0 ? round(($attended / $total) * 100) : 0;
        $this->totals = [
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'total' => $total,
        ];
    }

    public function render()
    {
        return view('livewire.student-attendance-card');
    }
}
