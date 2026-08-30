<?php

namespace App\Livewire;

use App\Models\Student;
use App\Models\StudentAssessment;
use App\Services\SkillCirclePresenter;
use App\Services\StudentSkillCircleService;
use App\Services\StudentSkillProgress;
use App\Support\Assessments\SkillCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Livewire\Component;

class StudentSkillProgressCard extends Component
{
    public int $studentId;
    public string $layout = 'admin';

    public array $skills = [];
    public bool $hasData = false;
    public array $skillHistory = [];
    public array $skillColumns = [];

    public function mount(int $studentId, string $layout = 'admin'): void
    {
        $this->studentId = $studentId;
        $this->layout = $layout;
        $this->loadSkills();
    }

    public function render()
    {
        return view('livewire.student-skill-progress-card');
    }

    public function refreshData(): void
    {
        $this->loadSkills();
    }

    private function loadSkills(): void
    {
        $student = Student::find($this->studentId);
        if (! $student) {
            $this->skills = [];
            $this->hasData = false;
            $this->skillHistory = [];
            return;
        }

        $aggregated = App::make(StudentSkillCircleService::class)->compute($student);
        $this->skills = App::make(SkillCirclePresenter::class)->present($aggregated);
        $this->hasData = collect($this->skills)->contains(fn ($skill) => ! $skill['is_empty']);

        $this->skillColumns = SkillCategory::labels();
        $progressEntries = App::make(StudentSkillProgress::class)
            ->build($student)
            ->filter(fn ($entry) => $entry['status'] === StudentAssessment::STATUS_FINAL);

        $this->skillHistory = $progressEntries->map(function ($entry) {
            $assessedAt = $entry['assessed_at']
                ? Carbon::parse($entry['assessed_at'])->timezone(config('app.timezone'))->format('d M Y H:i')
                : '—';

            $scoresBySkill = collect($entry['skills'])->keyBy('skill');

            $scores = collect($this->skillColumns)->mapWithKeys(function ($label, $skillKey) use ($scoresBySkill) {
                $row = $scoresBySkill->get($skillKey);
                if (! $row || ($row['is_empty'] ?? false)) {
                    return [$skillKey => null];
                }

                return [$skillKey => round((float) ($row['average'] ?? 0), 1)];
            })->all();

            $nonNullScores = array_values(array_filter($scores, fn ($value) => $value !== null));
            $average = count($nonNullScores)
                ? round(array_sum($nonNullScores) / count($nonNullScores), 1)
                : null;

            return [
                'assessed_at' => $assessedAt,
                'scores' => $scores,
                'average' => $average,
            ];
        })->values()->all();
    }
}
