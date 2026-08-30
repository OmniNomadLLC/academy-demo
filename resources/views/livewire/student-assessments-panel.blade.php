<div class="space-y-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Assessments</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">Track completed assessments and capture new snapshots.</p>
        </div>
        @if($this->canManageAssessments())
            <x-filament::button
                type="button"
                icon="heroicon-m-plus"
                wire:click="startCreate"
            >
                New assessment
            </x-filament::button>
        @endif
    </div>

    @if($showPhotoViewer && $photoPreviewUrl)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/80 p-4">
            <div class="relative w-full max-w-2xl">
                <button type="button" wire:click="closePhotoViewer" class="absolute right-2 top-2 rounded-full bg-black/60 p-2 text-white">
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                </button>
                <img src="{{ $photoPreviewUrl }}" alt="Student photo enlarged" class="w-full rounded-2xl object-contain" />
            </div>
        </div>
    @endif

    <div>
        {{ $this->table }}
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Need older assessments? Check the Skill Development tab for the full history.</p>
    </div>

    @if($showForm)
            <div class="space-y-6">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div>
                            <h4 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                {{ $viewOnly ? 'View assessment' : ($editingAssessmentId ? 'Edit assessment' : 'New assessment') }}
                            </h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $viewOnly ? 'Snapshot of recorded answers' : 'Answer the questions you need and save when you have enough insights.' }}</p>
                        </div>
                        @if($assessmentLocked)
                            <span class="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200">
                                <x-filament::icon icon="heroicon-m-lock-closed" class="h-4 w-4" />
                                Completed assessment
                            </span>
                        @endif
                    </div>
                    @if($assessmentLocked && $lockedAtDisplay)
                        <p class="text-sm text-gray-500 dark:text-gray-400">Locked on {{ $lockedAtDisplay }}.</p>
                    @endif
                </div>

                @if(!empty($skillCircles))
                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                        @foreach($skillCircles as $circle)
                            <div class="flex flex-col items-center gap-2">
                                <x-gauges.skill-ring
                                    label=""
                                    :value="$circle['is_empty'] ? 0 : $circle['score']"
                                    :percent="$circle['is_empty'] ? 0 : $circle['percentage']"
                                    :max="10"
                                    :center-label="number_format($circle['score'], 1)"
                                />
                                <p class="text-sm font-semibold text-gray-600 dark:text-gray-300">{{ $circle['label'] }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $circle['is_empty'] ? 'No data yet' : $circle['percentage'] . '%' }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif

            <div class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="fi-input-label">Template</label>
                        <select class="fi-input block w-full rounded-lg border-gray-300 py-2" wire:model="assessmentTemplateId" @if($editingAssessmentId) disabled @endif>
                            <option value="">Select a template</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                        @error('assessmentTemplateId')
                            <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="fi-input-label">Assessed at</label>
                        <input type="datetime-local" class="fi-input block w-full rounded-lg border-gray-300 py-2" wire:model="assessedAtInput" @if($viewOnly) disabled @endif />
                    </div>
                </div>

                <div>
                    <label class="fi-input-label">Overall comments</label>
                    <textarea class="fi-input block w-full rounded-lg border-gray-300 py-2" rows="3" wire:model.defer="overallComments" @if($viewOnly) disabled @endif></textarea>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Paper/photo attachment</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">PDF, DOC, DOCX, or image files up to 10MB are stored privately.</p>
                        </div>
                        @if($assessmentLocked)
                            <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-300">Locked — view-only</span>
                        @endif
                    </div>

                    <div class="mt-4 space-y-3">
                        @if($this->shouldShowAttachmentUploader())
                            <div class="flex flex-col gap-2">
                                <input type="file" wire:model="attachmentUpload" accept="application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/*" class="fi-input block w-full text-sm" />
                                @error('attachmentUpload')
                                    <p class="text-xs text-danger-600">{{ $message }}</p>
                                @enderror
                                <p wire:loading wire:target="attachmentUpload" class="text-xs text-gray-500">Uploading…</p>
                            </div>
                        @endif

                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            {{ $assessmentAttachmentOriginalName ?? 'No attachment uploaded yet.' }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <x-filament::button type="button" size="xs" wire:click="enableAttachmentUploader">
                                {{ $assessmentAttachmentPath ? 'Replace file' : 'Upload file' }}
                            </x-filament::button>
                            <x-filament::button type="button" size="xs" color="danger" wire:click="removeAttachment">
                                Remove file
                            </x-filament::button>
                            <x-filament::button tag="a" size="xs" target="_blank" href="{{ $this->attachmentPreviewUrl() }}" :disabled="! $this->canPreviewAttachment()">
                                Preview file
                            </x-filament::button>
                            <x-filament::button tag="a" size="xs" href="{{ $this->attachmentPreviewUrl() }}" :disabled="! $this->canPreviewAttachment()" download>
                                Download file
                            </x-filament::button>
                        </div>

                        @if($assessmentAttachmentPath)
                            <p class="text-xs text-gray-500 dark:text-gray-400">Current attachment lives in <code>assessment-attachments</code> on the private disk.</p>
                        @endif
                    </div>
                </div>

                @if(empty($questionSections))
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">Select a template with active questions to continue.</div>
                @else
                    <div class="space-y-2 rounded-lg bg-gray-50 px-4 py-3 text-xs font-medium uppercase tracking-wide text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                        Score the questions you answer (1–10)
                    </div>
                    <div class="mt-4 space-y-8 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($questionSections as $section)
                            @php $sectionAverage = $this->sectionAverage($section); @endphp
                            <div class="space-y-4 pt-6">
                                <div class="flex items-center justify-between">
                                    <h1 class="text-2xl font-semibold text-primary-600 dark:text-primary-400">{{ $section['title'] }}</h1>
                                    @if($sectionAverage)
                                        <span class="text-sm font-semibold text-gray-600 dark:text-gray-300">Avg: {{ $sectionAverage }}</span>
                                    @endif
                                </div>
                                <ol class="space-y-4 list-none">
                                    @foreach($section['questions'] as $question)
                                        @php $selectedScore = (int) ($answers[$question['id']]['score'] ?? 0); @endphp
                                        <li class="space-y-3 py-4" wire:key="question-{{ $question['id'] }}">
                                            <p class="font-medium text-gray-900 dark:text-gray-100">
                                                <span class="mr-2 rounded-full bg-primary-600/10 px-2 py-0.5 text-sm font-semibold text-primary-700 dark:text-primary-300">{{ $loop->iteration }}.</span>
                                                {{ $question['text'] }}
                                            </p>
                                            <div class="flex flex-wrap gap-2" role="group" aria-label="Score options for {{ $question['text'] }}">
                                                @foreach(range(1, 10) as $option)
                                                    @php $isActive = $selectedScore === (int) $option; @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="$set('answers.{{ $question['id'] }}.score', {{ $option }})"
                                                        @if($viewOnly) disabled @endif
                                                        class="inline-flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold transition {{ $isActive ? 'bg-primary-600 text-white shadow-sm ring-2 ring-primary-200 dark:ring-primary-800' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' }} {{ $viewOnly ? 'cursor-not-allowed opacity-60' : '' }}"
                                                        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                                        aria-label="Set score {{ $option }} for {{ $question['text'] }}"
                                                    >
                                                        {{ $option }}
                                                    </button>
                                                @endforeach
                                            </div>
                                            @error('answers.' . $question['id'] . '.score')
                                                <p class="text-xs text-danger-600">{{ $message }}</p>
                                            @enderror
                                            <div>
                                                <textarea class="fi-input block w-full rounded-lg border-gray-300 py-2" rows="2" placeholder="Notes (optional)" wire:model.defer="answers.{{ $question['id'] }}.notes" @if($viewOnly) disabled @endif></textarea>
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($formErrorMessage)
                    <p class="text-sm text-danger-600 text-right">{{ $formErrorMessage }}</p>
                @endif

                <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    @unless($viewOnly)
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <x-filament::button type="button" wire:click="saveAssessment">
                                Save draft
                            </x-filament::button>
                            <x-filament::button type="button" color="success" wire:click="completeAssessment">
                                Save & complete
                            </x-filament::button>
                        </div>
                    @endunless
                    <x-filament::button type="button" color="gray" wire:click="closeForm">Close</x-filament::button>
                </div>
            </div>
        </div>
    @endif
</div>
