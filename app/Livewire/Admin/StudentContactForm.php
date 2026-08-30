<?php

namespace App\Livewire\Admin;

use App\Models\Student;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class StudentContactForm extends Component
{
    public int $studentId;
    public ?Student $student = null;

    public string $email = '';
    public ?string $phone = null;
    public ?string $location = null;
    public ?string $emergency_contact_name = null;
    public ?string $emergency_contact_phone = null;
    public ?string $address = null;

    public bool $readOnly = false;

    public function mount(int $studentId): void
    {
        $this->studentId = $studentId;
        $this->loadStudent();
        $this->readOnly = Gate::denies('update', $this->student ?? Student::findOrFail($studentId));
    }

    public function render()
    {
        return view('livewire.admin.student-contact-form');
    }

    public function save(): void
    {
        $student = $this->student ?? Student::findOrFail($this->studentId);
        Gate::authorize('update', $student);

        $data = $this->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('students', 'email')->ignore($student->id),
            ],
            'phone' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $student->fill($data);
        $student->save();
        $student->refresh();

        $this->student = $student;

        Notification::make()
            ->success()
            ->title('Contact details updated')
            ->send();
    }

    protected function loadStudent(): void
    {
        $this->student = Student::findOrFail($this->studentId);
        $this->email = (string) ($this->student->email ?? '');
        $this->phone = $this->student->phone;
        $this->location = $this->student->location;
        $this->emergency_contact_name = $this->student->emergency_contact_name;
        $this->emergency_contact_phone = $this->student->emergency_contact_phone;
        $this->address = $this->student->address;
    }
}
