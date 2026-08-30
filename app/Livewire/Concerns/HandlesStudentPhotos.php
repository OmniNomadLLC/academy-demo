<?php

namespace App\Livewire\Concerns;

use App\Models\Student;
use App\Support\StudentPhotoProcessor;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use Throwable;

trait HandlesStudentPhotos
{
    use WithFileUploads;

    public bool $showPhotoViewer = false;
    public $photoUpload = null;
    public ?string $photoPreviewDataUrl = null;

    abstract protected function getStudentModel(): ?Student;

    public function openPhotoViewer(): void
    {
        $this->showPhotoViewer = true;
    }

    public function closePhotoViewer(): void
    {
        $this->showPhotoViewer = false;
    }

    public function savePhoto(): void
    {
        $student = $this->getStudentOrFail();
        Gate::authorize('update', $student);

        $this->validate([
            'photoUpload' => ['required', 'image', 'max:12288'],
        ], [
            'photoUpload.required' => 'Select a photo before uploading.',
            'photoUpload.image' => 'Upload a valid JPG/PNG/WEBP image.',
            'photoUpload.max' => 'Photos must be smaller than 12MB.',
        ]);

        $path = $this->photoUpload->store('student-photos', 'public');
        $absolutePath = Storage::disk('public')->path($path);
        StudentPhotoProcessor::normalize($absolutePath);

        if ($student->photo_path) {
            Storage::disk('public')->delete($student->photo_path);
        }

        $student->photo_path = $path;
        $student->save();
        $student->refresh();

        $this->resetPhotoUploadState();
        $this->showPhotoViewer = false;

        Notification::make()
            ->success()
            ->title('Student photo updated')
            ->send();
    }

    public function resetPhotoUploadState(): void
    {
        $this->photoUpload = null;
        $this->resetErrorBag('photoUpload');
        $this->showPhotoViewer = false;
        $this->photoPreviewDataUrl = null;
    }

    public function updatedPhotoUpload(): void
    {
        $this->resetErrorBag('photoUpload');

        if (! $this->photoUpload) {
            $this->photoPreviewDataUrl = null;
            return;
        }

        $this->showPhotoViewer = true;

        try {
            $this->photoPreviewDataUrl = $this->buildPreviewDataUrl();
        } catch (Throwable $exception) {
            report($exception);
            $this->photoPreviewDataUrl = null;
            $this->addError('photoUpload', 'Unable to process the selected image. Please choose another photo.');
        }
    }

    protected function getStudentOrFail(): Student
    {
        $student = $this->getStudentModel();

        if (! $student) {
            abort(404);
        }

        return $student;
    }

    protected function buildPreviewDataUrl(): ?string
    {
        $path = $this->photoUpload?->getRealPath();

        if (! $path) {
            return null;
        }

        return StudentPhotoProcessor::previewDataUri($path);
    }
}
