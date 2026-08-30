<?php

namespace App\Livewire\Admin;

use App\Models\Student;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class StudentNotesForm extends Component
{
    public int $studentId;
    public string $notes = '';
    public ?Student $student = null;
    public bool $readOnly = false;

    public function mount(int $studentId): void
    {
        $this->studentId = $studentId;
        $this->loadStudent();
        $this->readOnly = Gate::denies('update', $this->student ?? Student::findOrFail($studentId));
    }

    public function render()
    {
        return view('livewire.admin.student-notes-form');
    }

    public function save(): void
    {
        $student = $this->student ?? Student::findOrFail($this->studentId);
        Gate::authorize('update', $student);

        $data = $this->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $student->notes = $data['notes'];
        $student->save();
        $student->refresh();
        $this->student = $student;

        Notification::make()
            ->success()
            ->title('Student notes updated')
            ->send();
    }

    protected function loadStudent(): void
    {
        $this->student = Student::findOrFail($this->studentId);
        $this->notes = (string) ($this->student->notes ?? '');
    }
}
