<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\HasCheckboxOptions;
use App\Models\EmploymentAvailabilityOption;
use App\Models\EmploymentInterest;
use App\Models\EmploymentProfile;
use App\Models\Student;
use App\Services\EmploymentMatchingService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class StudentEmploymentProfileForm extends Component
{
    use HasCheckboxOptions;

    public int $studentId;

    public bool $readOnly = false;
    public bool $formVisible = false;

    public bool $hasWorkExperience = false;
    public string $preferredHours = 'either';
    public ?string $notes = null;
    public ?string $postcode = null;
    public int $maxTravelKm = 15;

    public array $employmentInterests = [];
    public array $employmentAvailabilityOptions = [];
    public array $selectedInterestIds = [];
    public array $selectedAvailabilityIds = [];
    public array $topMatches = [];

    protected ?Student $student = null;
    protected ?EmploymentProfile $profile = null;
    protected EmploymentMatchingService $matchingService;

    public function boot(EmploymentMatchingService $matchingService): void
    {
        $this->matchingService = $matchingService;
    }

    public function mount(int $studentId, bool $readOnly = false): void
    {
        $this->studentId = $studentId;
        $this->readOnly = $readOnly;

        $this->loadStudent();
        $this->loadOptions();
        $this->hydrateActiveProfile();
        $this->assertOptionsAreValid($this->employmentInterests);
        $this->assertOptionsAreValid($this->employmentAvailabilityOptions);
        $this->refreshTopMatches();
    }

    public function hydrate(): void
    {
        $this->ensureArray($this->selectedInterestIds);
        $this->ensureArray($this->selectedAvailabilityIds);
        $this->selectedInterestIds = $this->normalizeIds($this->selectedInterestIds);
        $this->selectedAvailabilityIds = $this->normalizeIds($this->selectedAvailabilityIds);
        $this->assertOptionsAreValid($this->employmentInterests);
        $this->assertOptionsAreValid($this->employmentAvailabilityOptions);
        $this->refreshTopMatches();
    }

    public function render()
    {
        return view('livewire.admin.student-employment-profile-form');
    }

    public function startCreating(): void
    {
        if ($this->readOnly) {
            return;
        }

        $this->profile = null;
        $this->formVisible = true;
        $this->resetFormValues();
    }

    public function save(): void
    {
        if ($this->readOnly) {
            return;
        }

        $student = $this->getStudent();
        Gate::authorize('update', $student);

        $this->ensureArray($this->selectedInterestIds);
        $this->ensureArray($this->selectedAvailabilityIds);
        $this->selectedInterestIds = $this->normalizeIds($this->selectedInterestIds);
        $this->selectedAvailabilityIds = $this->normalizeIds($this->selectedAvailabilityIds);
        $this->assertIdsAreValid($this->selectedInterestIds);
        $this->assertIdsAreValid($this->selectedAvailabilityIds);

        $this->validate($this->rules());

        $payload = [
            'is_active' => true,
            'has_work_experience' => (bool) $this->hasWorkExperience,
            'preferred_hours' => $this->preferredHours,
            'notes' => $this->notes,
            'postcode' => $this->postcode ? strtoupper(trim($this->postcode)) : null,
            'max_travel_km' => $this->maxTravelKm,
        ];

        DB::transaction(function () use ($student, $payload) {
            $profile = $this->getOrCreateActiveProfile($student);
            $profile->fill($payload);

            if (! $profile->exists) {
                $profile->student()->associate($student);
            }

            $profile->save();

            $student->employmentProfiles()
                ->whereKeyNot($profile->getKey())
                ->update(['is_active' => false]);

            $this->safeSync($profile->employmentInterests(), $this->selectedInterestIds);
            $this->safeSync($profile->employmentAvailabilityOptions(), $this->selectedAvailabilityIds);

            $this->profile = $profile->fresh();
        });

        // Re-geocode when the postcode changed (or was never resolved).
        if (config('luminaworks.enabled') && $this->profile?->postcode) {
            $coords = app(\App\Services\LuminaWorks\PostcodeGeocoder::class)->geocode($this->profile->postcode);
            if ($coords) {
                $this->profile->forceFill($coords)->save();
            }
        }

        $this->hydrateActiveProfile();
        $this->formVisible = true;
        $this->refreshTopMatches();

        Notification::make()
            ->success()
            ->title('Employment profile saved')
            ->send();
    }

    protected function rules(): array
    {
        return [
            'hasWorkExperience' => ['boolean'],
            'preferredHours' => ['required', Rule::in(['full_time', 'part_time', 'either'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'postcode' => ['nullable', 'string', 'max:10'],
            'maxTravelKm' => ['integer', 'min:1', 'max:100'],
            'selectedInterestIds' => ['array'],
            'selectedInterestIds.*' => ['integer', 'exists:employment_interests,id'],
            'selectedAvailabilityIds' => ['array'],
            'selectedAvailabilityIds.*' => ['integer', 'exists:employment_availability_options,id'],
        ];
    }

    protected function loadStudent(): void
    {
        $this->student = Student::findOrFail($this->studentId);

        if (Gate::denies('update', $this->student)) {
            $this->readOnly = true;
        }
    }

    protected function loadOptions(): void
    {
        $this->employmentInterests = $this->normalizeOptions(
            EmploymentInterest::query()->orderBy('name')->get(['id', 'name'])
        );

        $this->employmentAvailabilityOptions = $this->normalizeOptions(
            EmploymentAvailabilityOption::query()->orderBy('name')->get(['id', 'name'])
        );
    }

    protected function hydrateActiveProfile(): void
    {
        $this->profile = $this->student?->employmentProfiles()
            ->where('is_active', true)
            ->first();

        if ($this->profile) {
            $this->formVisible = true;
            $this->hasWorkExperience = (bool) $this->profile->has_work_experience;
            $this->preferredHours = $this->profile->preferred_hours ?? 'either';
            $this->notes = $this->profile->notes;
            $this->postcode = $this->profile->postcode;
            $this->maxTravelKm = (int) ($this->profile->max_travel_km ?? 15);
            $this->selectedInterestIds = $this->hydratePivotIds($this->profile->employmentInterests());
            $this->selectedAvailabilityIds = $this->hydratePivotIds($this->profile->employmentAvailabilityOptions());
        } else {
            $this->formVisible = false;
            $this->resetFormValues();
        }
    }

    protected function refreshTopMatches(): void
    {
        if (! isset($this->matchingService)) {
            $this->topMatches = [];
            return;
        }

        $student = $this->student ?? null;

        if (! $student) {
            $this->topMatches = [];
            return;
        }

        $matches = $this->matchingService->getTopMatches($student, 5);

        $this->topMatches = collect($matches)
            ->map(fn ($match) => [
                'job' => [
                    'id' => $match['job']->id,
                    'title' => $match['job']->title,
                ],
                'score' => $match['score'],
                'reasons' => $match['reasons'] ?? [],
            ])
            ->all();
    }

    protected function resetFormValues(): void
    {
        $this->hasWorkExperience = false;
        $this->preferredHours = 'either';
        $this->notes = null;
        $this->postcode = null;
        $this->maxTravelKm = 15;
        $this->selectedInterestIds = [];
        $this->selectedAvailabilityIds = [];
    }

    protected function getStudent(): Student
    {
        return $this->student ??= Student::findOrFail($this->studentId);
    }

    protected function getOrCreateActiveProfile(Student $student): EmploymentProfile
    {
        if ($this->profile && $this->profile->exists) {
            return $this->profile;
        }

        return $student->employmentProfiles()
            ->where('is_active', true)
            ->first()
            ?? new EmploymentProfile(['is_active' => true]);
    }
}
