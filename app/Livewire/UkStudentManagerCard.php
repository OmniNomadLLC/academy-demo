<?php

namespace App\Livewire;

use App\Models\Manager;
use App\Models\Student;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class UkStudentManagerCard extends Component
{
    public int $studentId;
    public ?int $selectedManagerId = null;
    public bool $showCreateForm = false;

    public ?array $currentManager = null;

    public string $managerName = '';
    public string $managerEmail = '';
    public ?string $managerPhone = null;

    public array $managers = [];
    public bool $readOnly = false;
    public bool $isUkManagerViewer = false;

    public function mount(int $studentId): void
    {
        $this->studentId = $studentId;
        $this->readOnly = Gate::denies('update', Student::findOrFail($studentId));
        $this->isUkManagerViewer = optional(Auth::user())->isUkManager() ?? false;
        $this->loadState();
    }

    #[On('manager-updated')]
    public function handleManagerUpdated(int $studentId): void
    {
        if ($studentId === $this->studentId) {
            $this->loadState();
        }
    }

    public function render()
    {
        return view('livewire.uk-student-manager-card');
    }

    public function toggleCreateForm(): void
    {
        if ($this->readOnly) {
            return;
        }

        $this->showCreateForm = ! $this->showCreateForm;
        if (! $this->showCreateForm) {
            $this->resetCreateForm();
        }
    }

    public function assignExisting(): void
    {
        $student = Student::findOrFail($this->studentId);
        Gate::authorize('update', $student);

        $this->validate([
            'selectedManagerId' => ['required', 'exists:managers,id'],
        ]);

        $student->manager_id = $this->selectedManagerId;
        $student->save();

        $this->dispatch('manager-updated', studentId: $this->studentId);
        $this->loadState();
        Notification::make()->success()->title('Manager assigned')->send();
    }

    public function removeManager(): void
    {
        $student = Student::findOrFail($this->studentId);
        Gate::authorize('update', $student);
        $student->manager_id = null;
        $student->save();

        $this->dispatch('manager-updated', studentId: $this->studentId);
        $this->loadState();
        Notification::make()->info()->title('Manager removed')->send();
    }

    public function createAndAssign(): void
    {
        $student = Student::findOrFail($this->studentId);
        Gate::authorize('update', $student);

        $this->validate([
            'managerName' => ['required', 'string', 'max:255'],
            'managerEmail' => ['required', 'email', 'max:255'],
            'managerPhone' => ['nullable', 'string', 'max:255'],
        ]);

        $existing = Manager::whereRaw('LOWER(email) = ?', [Str::lower($this->managerEmail)])->first();
        if ($existing) {
            $this->selectedManagerId = $existing->id;
            $this->assignExisting();
            $this->showCreateForm = false;
            $this->resetCreateForm();
            return;
        }

        $manager = Manager::create([
            'name' => trim($this->managerName),
            'email' => Str::lower(trim($this->managerEmail)),
            'phone' => $this->managerPhone ? trim($this->managerPhone) : null,
        ]);

        $this->selectedManagerId = $manager->id;
        $this->assignExisting();
        $this->showCreateForm = false;
        $this->resetCreateForm();
        Notification::make()->success()->title('Manager created and assigned')->send();
    }

    private function loadState(): void
    {
        $student = Student::with('manager')->findOrFail($this->studentId);
        $this->currentManager = $student->manager
            ? [
                'id' => $student->manager->id,
                'name' => $student->manager->name,
                'email' => $student->manager->email,
                'phone' => $student->manager->phone,
            ]
            : null;

        $this->selectedManagerId = $this->currentManager['id'] ?? null;
        $this->managers = Manager::orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn($manager) => [
                'id' => $manager->id,
                'label' => trim($manager->name.' · '.$manager->email),
            ])->all();
    }

    private function resetCreateForm(): void
    {
        $this->managerName = '';
        $this->managerEmail = '';
        $this->managerPhone = null;
    }
}
