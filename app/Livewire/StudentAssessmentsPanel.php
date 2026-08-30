<?php

namespace App\Livewire;

use App\Models\AssessmentQuestion;
use App\Models\AssessmentTemplate;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentAssessmentAnswer;
use App\Services\AssessmentSkillAggregator;
use App\Services\AssessmentSnapshotReader;
use App\Services\AssessmentSnapshotWriter;
use App\Services\SkillCirclePresenter;
use App\Support\Assessments\SkillCategory;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Support\Contracts\TranslatableContentDriver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Carbon\Carbon;

class StudentAssessmentsPanel extends Component implements HasTable, HasForms
{
    use InteractsWithTable;
    use InteractsWithForms;
    use WithFileUploads;

    public Student $student;
    public bool $isUkStudent = false;
    public ?int $editingAssessmentId = null;
    public ?int $assessmentTemplateId = null;
    public string $assessedAtInput = '';
    public ?string $overallComments = null;
    public array $answers = [];
    public array $questionSections = [];
    public bool $showForm = false;
    public bool $viewOnly = false;
    public array $questionLabels = [];
    public array $questionNumbers = [];
    public ?string $formErrorMessage = null;
    public $photoUpload = null;
    public ?string $photoPreviewUrl = null;
    public bool $showPhotoModal = false;
    public bool $showPhotoViewer = false;
    public bool $assessmentLocked = false;
    public ?string $lockedAtDisplay = null;
    public array $skillCircles = [];
    // Locked: these are set only server-side (upload flow / DB hydration). Without
    // #[Locked] a client could tamper the synced value to an arbitrary private-disk
    // path and have performAttachmentRemoval() delete it or the download controller
    // disclose it. The Blade only reads these; nothing binds them via wire:model.
    #[Locked]
    public ?string $assessmentAttachmentPath = null;
    #[Locked]
    public ?string $assessmentAttachmentOriginalName = null;
    public $attachmentUpload = null;
    public bool $showAttachmentUploader = true;
    public bool $shouldCleanupAttachmentOnReset = false;
    protected array $messages = [
        'photoUpload.required' => 'Select a photo before uploading.',
        'photoUpload.image' => 'Upload a valid JPG, PNG, or WEBP image.',
        'photoUpload.max' => 'Photos must be smaller than 12MB.',
        'photoUpload.uploaded' => 'Upload failed. Try a smaller image or compress the photo.',
        'attachmentUpload.file' => 'Upload a valid PDF, DOC, DOCX, JPG, JPEG, or PNG file.',
        'attachmentUpload.mimes' => 'Attachment must be a PDF, DOC, DOCX, JPG, JPEG, or PNG file.',
        'attachmentUpload.max' => 'Attachment must be smaller than 10MB.',
    ];

    protected $listeners = ['student-assessment-refresh' => '$refresh'];

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }

    public function mount(int $studentId, bool $isUkStudent = false): void
    {
        $this->student = Student::findOrFail($studentId);
        $this->isUkStudent = $isUkStudent;
        $this->refreshPhotoPreview();
        $this->assessmentAttachmentPath = null;
        $this->assessmentAttachmentOriginalName = null;
        $this->shouldCleanupAttachmentOnReset = false;
    }

    public function openPhotoModal(): void
    {
        abort_unless($this->canManageAssessments(), 403);

        $this->resetPhotoUploadState();
        $this->showPhotoModal = true;
    }

    public function closePhotoModal(): void
    {
        $this->resetPhotoUploadState();
        $this->showPhotoModal = false;
    }

    public function openPhotoViewer(): void
    {
        if (! $this->photoPreviewUrl) {
            return;
        }

        $this->showPhotoViewer = true;
    }

    public function closePhotoViewer(): void
    {
        $this->showPhotoViewer = false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getHistoryQuery())
            ->columns([
                Tables\Columns\TextColumn::make('assessed_at')
                    ->label('Date')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('template.name')
                    ->label('Template')
                    ->wrap(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => fn (string $state) => $state === StudentAssessment::STATUS_FINAL,
                        'warning' => fn (string $state) => $state === StudentAssessment::STATUS_DRAFT,
                    ])
                    ->formatStateUsing(fn (string $state) => $state === StudentAssessment::STATUS_FINAL ? 'Completed' : 'Draft'),
                Tables\Columns\TextColumn::make('assessedBy.name')
                    ->label('Assessed by')
                    ->wrap(),
                Tables\Columns\TextColumn::make('average_score')
                    ->label('Average score')
                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 2) : '—'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('')
                    ->icon('heroicon-m-eye')
                    ->tooltip('View assessment')
                    ->action(fn (StudentAssessment $record) => $this->openAssessment($record, true)),
                Tables\Actions\Action::make('edit')
                    ->label('')
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (StudentAssessment $record) => $this->canManageAssessments() && $record->isEditable())
                    ->tooltip('Edit assessment')
                    ->action(fn (StudentAssessment $record) => $this->openAssessment($record, false)),
                Tables\Actions\Action::make('download')
                    ->label('')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->url(fn (StudentAssessment $record): string => route('portal.assessments.download', [
                        'student' => $this->student->id,
                        'assessment' => $record->id,
                    ]))
                    ->openUrlInNewTab()
                    ->tooltip('Download PDF report'),
                Tables\Actions\Action::make('delete')
                    ->label('')
                    ->color('danger')
                    ->icon('heroicon-m-trash')
                    ->visible(fn (StudentAssessment $record) => $this->canManageAssessments() && $record->isEditable())
                    ->requiresConfirmation()
                    ->modalHeading('Delete assessment?')
                    ->modalDescription('This removes the selected assessment and its answers. This cannot be undone.')
                    ->tooltip('Delete assessment')
                    ->action(fn (StudentAssessment $record) => $this->deleteAssessment($record)),
            ])
            ->emptyStateHeading('No assessments yet')
            ->paginated(false);
    }

    public function render()
    {
        return view('livewire.student-assessments-panel', [
            'templates' => $this->availableTemplates(),
        ]);
    }

    public function startCreate(): void
    {
        abort_unless($this->canManageAssessments(), 403);

        $this->resetForm();
        $this->assessedAtInput = now(config('app.timezone'))->format('Y-m-d\TH:i');
        $this->showForm = true;
        $this->viewOnly = false;
        $this->assessmentLocked = false;
        $this->lockedAtDisplay = null;
        $this->assessmentAttachmentPath = null;
        $this->assessmentAttachmentOriginalName = null;
        $this->showAttachmentUploader = true;
        $this->attachmentUpload = null;
        $this->shouldCleanupAttachmentOnReset = false;
        $this->resetErrorBag(['attachmentUpload']);
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function openAssessment(StudentAssessment $assessment, bool $viewOnly = false): void
    {
        $assessment->load(['answers.question', 'template']);

        $isEditable = $assessment->isEditable();
        if (! $isEditable) {
            $viewOnly = true;
        }

        $this->assessmentLocked = ! $isEditable;
        $this->lockedAtDisplay = $assessment->locked_at
            ? $assessment->locked_at->timezone(config('app.timezone'))->format('d M Y H:i')
            : null;

        $snapshotItems = $this->snapshotReader()->getItems($assessment);
        // Phase 4 snapshot read switch (fallback safe)

        $this->editingAssessmentId = $assessment->id;
        $this->assessmentTemplateId = $assessment->assessment_template_id;
        $this->assessedAtInput = optional($assessment->assessed_at)
            ? $assessment->assessed_at->timezone(config('app.timezone'))->format('Y-m-d\TH:i')
            : now(config('app.timezone'))->format('Y-m-d\TH:i');
        $this->overallComments = $assessment->overall_comments;
        $this->answers = [];

        if ($snapshotItems->isNotEmpty()) {
            $this->questionSections = $this->buildSectionsFromSnapshotItems($snapshotItems);
            $this->questionLabels = $snapshotItems
                ->filter(fn ($item) => ! empty($item->template_question_id))
                ->mapWithKeys(fn ($item) => [$item->template_question_id => $item->question_text ?? 'Question'])
                ->all();

            $existingAnswers = $assessment->answers->keyBy('assessment_question_id');

            foreach ($snapshotItems as $item) {
                $questionId = $item->template_question_id;
                if (! $questionId) {
                    continue;
                }

                $answer = $existingAnswers->get($questionId);
                $this->answers[$questionId] = [
                    'score' => $answer?->score,
                    'notes' => $answer?->notes,
                ];
            }

            $unmappedAnswers = $assessment->answers
                ->reject(fn (StudentAssessmentAnswer $answer) => array_key_exists($answer->assessment_question_id, $this->answers));

            foreach ($unmappedAnswers as $answer) {
                $this->answers[$answer->assessment_question_id] = [
                    'score' => $answer->score,
                    'notes' => $answer->notes,
                ];
            }
        } else {
            $this->questionSections = $this->buildSectionsFromAnswers($assessment);
            $this->questionLabels = $assessment->answers
                ->mapWithKeys(function (StudentAssessmentAnswer $answer) {
                    $question = $answer->question;
                    return [$answer->assessment_question_id => $question?->question_text ?? 'Question'];
                })
                ->all();

            foreach ($assessment->answers as $answer) {
                $this->answers[$answer->assessment_question_id] = [
                    'score' => $answer->score,
                    'notes' => $answer->notes,
                ];
            }
        }

        $this->refreshQuestionNumbers();

        $skills = $this->skillAggregator()->aggregate($assessment);
        $this->skillCircles = $this->skillCirclePresenter()->present($skills);
        $this->assessmentAttachmentPath = $assessment->attachment_path;
        $this->assessmentAttachmentOriginalName = $assessment->attachment_original_name ?: ($this->assessmentAttachmentPath ? basename($this->assessmentAttachmentPath) : null);
        $this->showAttachmentUploader = $assessment->isEditable();
        $this->attachmentUpload = null;
        $this->shouldCleanupAttachmentOnReset = false;

        $this->showForm = true;
        $this->viewOnly = $viewOnly;
    }

    public function updatedAssessmentTemplateId($value): void
    {
        if ($this->editingAssessmentId !== null) {
            return;
        }

        $this->assessmentTemplateId = $value ? (int) $value : null;
        $this->loadTemplateQuestions($this->assessmentTemplateId);
    }

    public function saveAssessment(): void
    {
        $this->persistAssessment(finalize: false);
    }

    public function completeAssessment(): void
    {
        $this->persistAssessment(finalize: true);
    }

    protected function persistAssessment(bool $finalize = false): void
    {
        abort_unless($this->canManageAssessments(), 403);

        if ($this->viewOnly) {
            $this->notifyLockedAssessment();
            return;
        }

        $wasEditing = (bool) $this->editingAssessmentId;

        if ($this->editingAssessmentId) {
            $assessment = StudentAssessment::where('student_id', $this->student->id)
                ->with('answers')
                ->findOrFail($this->editingAssessmentId);

            if (! $assessment->isEditable()) {
                $this->notifyLockedAssessment();
                return;
            }
        } else {
            $assessment = new StudentAssessment();
            $assessment->student_id = $this->student->id;
            $assessment->assessment_template_id = $this->assessmentTemplateId;
            $assessment->assessed_by_user_id = Auth::id();
            $assessment->status = StudentAssessment::STATUS_DRAFT;
        }

        $questions = $this->currentQuestionIds();

        if (empty($questions)) {
            $this->addError('assessmentTemplateId', 'Select a template with active questions.');
            return;
        }

        $this->formErrorMessage = null;
        $scores = [];

        foreach ($questions as $questionId) {
            $score = $this->answers[$questionId]['score'] ?? null;

            if (! $this->isValidScore($score)) {
                continue;
            }

            $scores[$questionId] = (int) $score;
        }

        if (empty($scores)) {
            $this->formErrorMessage = 'Add at least one score before saving the assessment.';
            return;
        }

        $assessedAt = $this->assessedAtInput
            ? Carbon::createFromFormat('Y-m-d\TH:i', $this->assessedAtInput, config('app.timezone'))
            : now(config('app.timezone'));

        $average = round(array_sum($scores) / count($scores), 2);

        DB::transaction(function () use ($assessment, $assessedAt, $average, $scores, $finalize) {
            $assessment->assessed_at = $assessedAt;
            $assessment->overall_comments = $this->overallComments;
            $assessment->average_score = $average;
            $assessment->attachment_path = $this->assessmentAttachmentPath;
            $assessment->attachment_original_name = $this->assessmentAttachmentOriginalName;

            if ($finalize) {
                $assessment->markFinal();
            } else {
                $assessment->status = StudentAssessment::STATUS_DRAFT;
                $assessment->locked_at = null;
            }

            $assessment->save();

            if ($assessment->wasRecentlyCreated) {
                $records = [];
                foreach ($scores as $questionId => $score) {
                    $records[] = [
                        'assessment_question_id' => $questionId,
                        'score' => $score,
                        'notes' => $this->answers[$questionId]['notes'] ?? null,
                    ];
                }
                $assessment->answers()->createMany($records);
            } else {
                $assessment->answers()->each(function (StudentAssessmentAnswer $answer) {
                    $payload = $this->answers[$answer->assessment_question_id] ?? null;
                    if ($payload === null) {
                        return;
                    }

                    $answer->update([
                        'score' => (int) $payload['score'],
                        'notes' => $payload['notes'] ?? null,
                    ]);
                });
            }
        });

        $this->shouldCleanupAttachmentOnReset = false;

        if ($finalize) {
            app(AssessmentSnapshotWriter::class)->snapshot($assessment->fresh());
        }

        $this->closeForm();
        $this->resetTable();

        Notification::make()
            ->title($finalize ? 'Assessment completed' : ($wasEditing ? 'Assessment updated' : 'Assessment saved'))
            ->success()
            ->send();
    }

    public function savePhoto(): void
    {
        abort_unless($this->canManageAssessments(), 403);

        $this->validate([
            'photoUpload' => ['required', 'image', 'max:12288'],
        ]);

        $path = $this->photoUpload->storePublicly('student-photos', 'public');

        if ($this->student->photo_path) {
            Storage::disk('public')->delete($this->student->photo_path);
        }

        $this->student->photo_path = $path;
        $this->student->save();

        Log::info('student.photo.uploaded', [
            'student_id' => $this->student->id,
            'path' => $path,
            'exists' => Storage::disk('public')->exists($path),
        ]);

        $this->resetPhotoUploadState();
        $this->showPhotoModal = false;
        $this->refreshPhotoPreview();

        Notification::make()
            ->title('Photo updated')
            ->success()
            ->send();
    }

    public function enableAttachmentUploader(): void
    {
        $this->showAttachmentUploader = true;
        $this->attachmentUpload = null;
        $this->resetErrorBag(['attachmentUpload']);
    }

    public function updatedAttachmentUpload(): void
    {
        if (! $this->attachmentUpload) {
            return;
        }

        $this->validate([
            'attachmentUpload' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'],
        ]);

        if (! $this->canReplaceAttachment()) {
            $this->attachmentUpload = null;
            return;
        }

        $originalName = $this->attachmentUpload->getClientOriginalName();
        $path = Storage::disk('private')->putFile('assessment-attachments', $this->attachmentUpload);

        Log::info('assessment_attachment.uploaded', [
            'student_id' => $this->student->id,
            'assessment_id' => $this->editingAssessmentId,
            'stored_path' => $path,
            'exists' => Storage::disk('private')->exists($path),
            'original_name' => $originalName,
        ]);

        $this->attachmentUpload = null;
        $this->applyUploadedAttachment($path, $originalName);
    }

    public function removeAttachment(): void
    {
        if (! $this->canRemoveAttachment()) {
            return;
        }

        $this->performAttachmentRemoval(notify: true);
    }

    public function deleteAssessment(StudentAssessment $assessment): void
    {
        abort_unless($this->canManageAssessments(), 403);

        abort_unless($assessment->student_id === $this->student->id, 403);

        if (! $assessment->isEditable()) {
            $this->notifyLockedAssessment();
            return;
        }

        if ($this->editingAssessmentId === $assessment->id) {
            $this->closeForm();
        }

        $assessment->delete();

        $this->resetTable();

        Notification::make()
            ->title('Assessment deleted')
            ->success()
            ->send();
    }

    protected function notifyLockedAssessment(): void
    {
        Notification::make()
            ->title('Completed assessments are read-only')
            ->body('Create a new assessment if you need to make changes.')
            ->warning()
            ->send();
    }

    protected function loadTemplateQuestions(?int $templateId = null): void
    {
        $this->resetTemplateState();

        if (! $templateId) {
            return;
        }

        $template = AssessmentTemplate::with(['questions' => function ($query) {
            $query->where('is_active', true)->orderBy('sort_order');
        }])->find($templateId);

        if (! $template || $template->questions->isEmpty()) {
            return;
        }

        $this->questionSections = $this->buildSectionsFromQuestions($template->questions);
        $this->questionLabels = $template->questions
            ->mapWithKeys(fn (AssessmentQuestion $question) => [$question->id => $question->question_text])
            ->all();
        $this->refreshQuestionNumbers();

        foreach ($template->questions as $question) {
            $this->answers[$question->id] = [
                'score' => null,
                'notes' => null,
            ];
        }
    }

    protected function resetTemplateState(): void
    {
        $this->questionSections = [];
        $this->questionLabels = [];
        $this->answers = [];
        $this->questionNumbers = [];
        $this->formErrorMessage = null;
    }

    protected function buildSectionsFromQuestions(Collection $questions): array
    {
        return $questions
            ->groupBy(fn (AssessmentQuestion $question) => $question->skill_category ?? SkillCategory::default())
            ->map(function (Collection $items, $category) {
                $label = SkillCategory::label($category ?? SkillCategory::default());

                return [
                    'title' => $label,
                    'questions' => $items->map(fn (AssessmentQuestion $question) => [
                        'id' => $question->id,
                        'text' => $question->question_text,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    protected function buildSectionsFromAnswers(StudentAssessment $assessment): array
    {
        return $assessment->answers
            ->groupBy(function (StudentAssessmentAnswer $answer) {
                $question = $answer->question;
                $category = $question?->skill_category ?? null;

                if (! $category && $question?->section) {
                    $category = SkillCategory::fromSection($question->section);
                }

                return $category ?? SkillCategory::default();
            })
            ->map(function (Collection $items, $category) {
                $label = SkillCategory::label($category ?? SkillCategory::default());

                return [
                    'title' => $label,
                    'questions' => $items->map(function (StudentAssessmentAnswer $answer) {
                        $question = $answer->question;

                        return [
                            'id' => $answer->assessment_question_id,
                            'text' => $question?->question_text ?? 'Question',
                        ];
                    })->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    protected function buildSectionsFromSnapshotItems(Collection $items): array
    {
        return $items
            ->groupBy(function ($item) {
                $category = $item->skill_category ?? null;

                if (! $category && ! empty($item->section_name)) {
                    $category = SkillCategory::fromSection($item->section_name);
                }

                return $category ?? SkillCategory::default();
            })
            ->map(function (Collection $questions, $category) {
                $label = SkillCategory::label($category ?? SkillCategory::default());

                return [
                    'title' => $label,
                    'questions' => $questions->map(function ($item) {
                        return [
                            'id' => $item->template_question_id,
                            'text' => $item->question_text ?? 'Question',
                        ];
                    })->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    protected function currentQuestionIds(): array
    {
        return collect($this->questionSections)
            ->flatMap(fn ($section) => collect($section['questions']))
            ->pluck('id')
            ->all();
    }

    public function shouldShowAttachmentUploader(): bool
    {
        if (! $this->canManageAssessments() || $this->assessmentLocked) {
            return false;
        }

        return $this->showAttachmentUploader;
    }

    public function canReplaceAttachment(): bool
    {
        return $this->canManageAssessments() && ! $this->assessmentLocked;
    }

    public function canRemoveAttachment(): bool
    {
        return $this->canReplaceAttachment() && filled($this->assessmentAttachmentPath);
    }

    public function canPreviewAttachment(): bool
    {
        return filled($this->assessmentAttachmentPath) && $this->editingAssessmentId !== null;
    }

    public function attachmentPreviewUrl(): ?string
    {
        if (! $this->canPreviewAttachment()) {
            return null;
        }

        return route('assessments.attachment.download', $this->editingAssessmentId);
    }

    protected function applyUploadedAttachment(string $path, ?string $originalName = null): void
    {
        $previous = $this->assessmentAttachmentPath;
        $this->assessmentAttachmentPath = $path;
        $this->assessmentAttachmentOriginalName = $originalName ?: basename($path);
        $this->resetErrorBag(['attachmentUpload']);

        if ($this->editingAssessmentId) {
            $assessment = StudentAssessment::where('student_id', $this->student->id)
                ->find($this->editingAssessmentId);

            if (! $assessment || ! $assessment->isEditable()) {
                $this->notifyLockedAssessment();
                $this->assessmentAttachmentPath = $assessment?->attachment_path;
                $this->assessmentAttachmentOriginalName = $assessment?->attachment_original_name;
                return;
            }

            $existing = $assessment->attachment_path;
            $assessment->attachment_path = $path;
            $assessment->attachment_original_name = $this->assessmentAttachmentOriginalName;
            $assessment->save();

            if ($existing && $existing !== $path) {
                Storage::disk('private')->delete($existing);
                Log::info('assessment_attachment.replaced', [
                    'student_id' => $this->student->id,
                    'assessment_id' => $assessment->id,
                    'deleted_path' => $existing,
                ]);
            }
            $this->shouldCleanupAttachmentOnReset = false;
        } elseif ($previous && $previous !== $path) {
            Storage::disk('private')->delete($previous);
            Log::info('assessment_attachment.replaced_pending', [
                'student_id' => $this->student->id,
                'deleted_path' => $previous,
            ]);
            $this->shouldCleanupAttachmentOnReset = true;
        } else {
            $this->shouldCleanupAttachmentOnReset = true;
        }

        $this->showAttachmentUploader = $this->canReplaceAttachment();
        $this->resetValidation('attachmentUpload');

        Notification::make()
            ->title('Attachment updated')
            ->success()
            ->send();
    }

    protected function performAttachmentRemoval(bool $notify = false): void
    {
        $path = $this->assessmentAttachmentPath;

        if (! $path) {
            $this->assessmentAttachmentPath = null;
            $this->assessmentAttachmentOriginalName = null;
            $this->showAttachmentUploader = true;
            $this->resetErrorBag(['attachmentUpload']);
            return;
        }

        if ($this->editingAssessmentId) {
            $assessment = StudentAssessment::where('student_id', $this->student->id)
                ->find($this->editingAssessmentId);

            if (! $assessment || ! $assessment->isEditable()) {
                $this->notifyLockedAssessment();
                return;
            }

            $assessment->attachment_path = null;
            $assessment->save();
        }

        Storage::disk('private')->delete($path);
        Log::info('assessment_attachment.removed', [
            'student_id' => $this->student->id,
            'assessment_id' => $this->editingAssessmentId,
            'deleted_path' => $path,
        ]);

        $this->assessmentAttachmentPath = null;
        $this->assessmentAttachmentOriginalName = null;
        $this->showAttachmentUploader = true;
        $this->shouldCleanupAttachmentOnReset = false;
        $this->resetErrorBag(['attachmentUpload']);

        if ($notify) {
            Notification::make()
                ->title('Attachment removed')
                ->success()
                ->send();
        }
    }

    protected function resetForm(): void
    {
        if ($this->shouldCleanupAttachmentOnReset && $this->assessmentAttachmentPath) {
            Storage::disk('private')->delete($this->assessmentAttachmentPath);
            Log::info('assessment_attachment.abandoned', [
                'student_id' => $this->student->id,
                'deleted_path' => $this->assessmentAttachmentPath,
            ]);
        }

        $this->editingAssessmentId = null;
        $this->assessmentTemplateId = null;
        $this->assessedAtInput = '';
        $this->overallComments = null;
        $this->answers = [];
        $this->questionSections = [];
        $this->questionLabels = [];
        $this->questionNumbers = [];
        $this->formErrorMessage = null;
        $this->viewOnly = false;
        $this->assessmentLocked = false;
        $this->lockedAtDisplay = null;
        $this->skillCircles = [];
        $this->assessmentAttachmentPath = null;
        $this->assessmentAttachmentOriginalName = null;
        $this->showAttachmentUploader = true;
        $this->attachmentUpload = null;
        $this->shouldCleanupAttachmentOnReset = false;
    }

    protected function availableTemplates(): Collection
    {
        return AssessmentTemplate::query()
            ->where('region', 'uk')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    protected function snapshotReader(): AssessmentSnapshotReader
    {
        return app(AssessmentSnapshotReader::class);
    }

    protected function skillAggregator(): AssessmentSkillAggregator
    {
        return app(AssessmentSkillAggregator::class);
    }

    protected function skillCirclePresenter(): SkillCirclePresenter
    {
        return app(SkillCirclePresenter::class);
    }

    protected function refreshQuestionNumbers(): void
    {
        $this->questionNumbers = [];

        foreach ($this->questionSections as $section) {
            $title = $section['title'] ?? 'Questions';
            foreach (($section['questions'] ?? []) as $index => $question) {
                $number = $index + 1;
                $this->questionNumbers[$question['id']] = $title.' – Question '.$number;
            }
        }
    }

    public function sectionAverage(array $section): ?string
    {
        $scores = collect($section['questions'] ?? [])
            ->pluck('id')
            ->map(fn ($id) => $this->answers[$id]['score'] ?? null)
            ->filter(fn ($score) => $this->isValidScore($score));

        if ($scores->isEmpty()) {
            return null;
        }

        return number_format($scores->avg(), 2);
    }

    protected function isValidScore($value): bool
    {
        return in_array((int) $value, range(1, 10), true);
    }

    public function canManageAssessments(): bool
    {
        /** @var Authenticatable|\App\Models\User|null $user */
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return $user->canFillAssessments();
    }

    protected function getHistoryQuery(): Builder
    {
        return StudentAssessment::query()
            ->with(['template', 'assessedBy'])
            ->where('student_id', $this->student->id)
            ->latest('assessed_at')
            ->limit(2);
    }

    protected function refreshPhotoPreview(): void
    {
        $this->photoPreviewUrl = $this->student->photo_path
            ? Storage::disk('public')->url($this->student->photo_path)
            : null;
    }

    protected function resetPhotoUploadState(): void
    {
        $this->photoUpload = null;
        $this->resetValidation('photoUpload');
    }
}
