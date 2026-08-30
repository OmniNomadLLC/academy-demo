@props([
    'student',
    'isUkStudent' => false,
    'showPersonalCard' => true,
    'layout' => null,
    'activeTab' => 'assessments',
    'photoActions' => true,
    'allowAdminForms' => true,
])

@php
    $appTz = config('app.timezone');
    $deliveryMode = $isUkStudent ? ($student->is_online ? 'Online' : 'In person') : null;

    $student->loadMissing(['latestEnrollmentMessage.initiatedBy']);
    $skillLayout = ($layout ?? null) === 'portal' ? 'portal' : 'admin';
    $latestEnrollmentMessage = $student->latestEnrollmentMessage;
    $enrollmentSenderName = null;
    $enrollmentSummary = null;

    if ($latestEnrollmentMessage) {
        $channelLabel = match ($latestEnrollmentMessage->channel) {
            \App\Models\StudentEnrollmentMessage::CHANNEL_WHATSAPP => 'WhatsApp',
            \App\Models\StudentEnrollmentMessage::CHANNEL_SMS => 'SMS',
            default => ucfirst((string) $latestEnrollmentMessage->channel),
        };

        $sentAt = ($latestEnrollmentMessage->created_at ?? $latestEnrollmentMessage->status_updated_at)?->copy()->timezone($appTz);
        $deliveredAt = $latestEnrollmentMessage->delivered_at?->timezone($appTz);
        $readAt = $latestEnrollmentMessage->read_at?->timezone($appTz);

        $enrollmentSenderName = optional($latestEnrollmentMessage->initiatedBy)->name
            ?? optional($student->enrollmentLastSender)->name;

        $enrollmentSummary = [
            'channel' => $channelLabel,
            'sent_at' => $sentAt,
            'sent_human' => $sentAt?->diffForHumans(),
            'status' => $latestEnrollmentMessage->status ? ucfirst($latestEnrollmentMessage->status) : null,
            'delivered_at' => $deliveredAt,
            'delivered_human' => $deliveredAt?->diffForHumans(),
            'read_at' => $readAt,
            'read_human' => $readAt?->diffForHumans(),
            'email_sent' => (bool) ($latestEnrollmentMessage->meta['email_sent'] ?? false),
        ];
    } elseif ($isUkStudent && $student->enrollment_last_sent_at) {
        $channel = $student->enrollment_last_channel === 'whatsapp' ? 'WhatsApp' : 'SMS / Email';
        $enrollmentSummary = [
            'channel' => $channel,
            'sent_at' => $student->enrollment_last_sent_at->timezone($appTz),
            'sent_human' => $student->enrollment_last_sent_at->timezone($appTz)->diffForHumans(),
            'status' => 'Sent',
            'delivered_at' => null,
            'delivered_human' => null,
            'read_at' => null,
            'read_human' => null,
            'email_sent' => $student->enrollment_last_channel === 'sms_email',
        ];
        $enrollmentSenderName = optional($student->enrollmentLastSender)->name;
    }

    $currentUser = auth()->user();
    $isAdminView = ($layout ?? null) !== 'portal';
    $currentUserCanViewAssessments = $currentUser && (
        method_exists($currentUser, 'canViewAssessments')
            ? $currentUser->canViewAssessments()
            : $currentUser->canFillAssessments()
    );
    $isUkManagerViewer = $currentUser && method_exists($currentUser, 'isUkManager') && $currentUser->isUkManager();
    $canViewAssessments = $isUkStudent && $currentUserCanViewAssessments;
    $studentEligibleForEnrollmentSend = (bool) ($student->in_uk ?? false);
    if (! $studentEligibleForEnrollmentSend) {
        $ukSlugs = ['uk', 'riverside', 'southbank', 'parkside', 'northgate'];
        $studentEligibleForEnrollmentSend = in_array(strtolower((string) ($student->location ?? '')), $ukSlugs, true);
    }
    $canTriggerEnrollmentActions = $isAdminView
        && $studentEligibleForEnrollmentSend
        && $currentUser
        && method_exists($currentUser, 'hasRole')
        && $currentUser->hasRole('admin', 'super_admin')
        && isset($this)
        && method_exists($this, 'sendEnrollmentWhatsApp')
        && method_exists($this, 'sendEnrollmentSmsAndEmail');
    $studentPhotoUrl = $student->photo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($student->photo_path) : null;
    $previewPhotoUrl = $studentPhotoUrl;
    $hasTemporaryPhoto = false;
    $livewirePreview = isset($this) && property_exists($this, 'photoPreviewDataUrl') ? $this->photoPreviewDataUrl : null;

    if ($livewirePreview) {
        $previewPhotoUrl = $livewirePreview;
        $hasTemporaryPhoto = true;
    } elseif (isset($photoUpload) && $photoUpload instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
        try {
            $previewPhotoUrl = $photoUpload->temporaryUrl();
            $hasTemporaryPhoto = true;
        } catch (\Throwable $e) {
            $previewPhotoUrl = $studentPhotoUrl;
        }
    }
    $temporaryFileName = null;
    if ($hasTemporaryPhoto && isset($photoUpload) && method_exists($photoUpload, 'getClientOriginalName')) {
        $temporaryFileName = $photoUpload->getClientOriginalName();
    }

    $studentInitials = strtoupper(trim(mb_substr($student->first_name ?? '', 0, 1).mb_substr($student->last_name ?? '', 0, 1))) ?: 'ST';
    $allowAdminForms = (bool) ($allowAdminForms ?? true);
    $supportsPhotoActions = ($photoActions ?? (isset($this) && property_exists($this, 'showPhotoViewer'))) && $allowAdminForms;
@endphp

<div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <div class="mb-6">
        <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-gray-900 shadow-xl border border-gray-200 dark:border-gray-700">
            <div class="absolute left-0 top-0 h-full w-1.5 bg-gradient-to-b from-primary-400 via-primary-500 to-primary-600"></div>
            <div class="flex items-center justify-between gap-6 p-6 pl-8">
                <div>
                    <p class="text-sm uppercase tracking-widest text-gray-400 dark:text-gray-500">Student profile</p>
                    <h1 class="text-4xl font-semibold text-gray-900 dark:text-white mt-1">
                        {{ $student->full_name ?? trim(($student->first_name ?? '').' '.($student->last_name ?? '')) }}
                    </h1>
                </div>

                <div class="relative ml-auto">
                    @if($previewPhotoUrl)
                        @if($supportsPhotoActions)
                            <button type="button" wire:click="openPhotoViewer" class="block w-20 h-20 rounded-full overflow-hidden ring-4 ring-white dark:ring-gray-900 shadow-2xl focus:outline-none focus:ring-4 focus:ring-primary-300">
                                <img src="{{ $previewPhotoUrl }}" alt="Student photo" class="w-full h-full object-cover">
                            </button>
                        @else
                            <div class="w-20 h-20 rounded-full overflow-hidden ring-4 ring-white dark:ring-gray-900 shadow-2xl">
                                <img src="{{ $previewPhotoUrl }}" alt="Student photo" class="w-full h-full object-cover">
                            </div>
                        @endif
                    @else
                        @if($supportsPhotoActions)
                            <label for="student-photo-upload" class="flex h-20 w-24 cursor-pointer items-center justify-center rounded-full border border-primary-500 bg-white px-3 text-center text-sm font-semibold uppercase tracking-wide text-primary-600 shadow-lg dark:border-primary-400 dark:bg-gray-900 dark:text-primary-300">
                                Upload photo
                            </label>
                        @else
                            <div class="flex h-20 w-24 items-center justify-center rounded-full border border-dashed border-gray-300 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-600 dark:text-gray-300">
                                Upload photo
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($supportsPhotoActions)
        <input
            type="file"
            id="student-photo-upload"
            class="hidden"
            accept="image/*"
            wire:model="photoUpload"
        />
    @endif

    @if($supportsPhotoActions && ($this->showPhotoViewer ?? false))
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
            <div class="relative w-full max-w-md space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-2xl dark:border-gray-700 dark:bg-gray-900">
                <button type="button" wire:click="closePhotoViewer" class="absolute right-3 top-3 rounded-full bg-black/10 p-1.5 text-gray-600 hover:text-gray-900">
                    <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                </button>
                <div class="space-y-4">
                    <div class="mx-auto aspect-square w-full max-w-[400px] overflow-hidden rounded-xl bg-gray-100">
                        <img src="{{ $previewPhotoUrl ?? $studentPhotoUrl ?? '' }}" alt="Student photo preview" class="h-full w-full object-cover">
                    </div>
                    <div class="text-xs text-center text-gray-600 space-y-3">
                        @if($hasTemporaryPhoto && $temporaryFileName)
                            <p class="font-semibold text-gray-800">Selected file: {{ $temporaryFileName }}</p>
                        @endif
        
                        <div class="mt-4 flex flex-wrap justify-center gap-3">
                            <label for="student-photo-upload" class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-primary-500 bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white shadow hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-400">
                                <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-4 w-4" />
                                Replace
                            </label>
                            @if($hasTemporaryPhoto)
                                <x-filament::button color="primary" size="sm" wire:click="savePhoto" wire:loading.attr="disabled">
                                    Save
                                </x-filament::button>
                                <x-filament::button color="gray" size="sm" wire:click="resetPhotoUploadState" wire:loading.attr="disabled">
                                    Cancel
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                    @error('photoUpload')
                        <p class="text-center text-xs text-danger-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>
    @endif

<div class="flex gap-3 overflow-x-auto whitespace-nowrap" style="padding-top: 50px;">
        @if($isAdminView)
            <button
                type="button"
                wire:click="setProfileTab('attendance')"
                @class([
                    'px-3 pb-2 text-sm font-medium transition border-b-2 border-transparent focus:outline-none',
                    'text-primary-600 border-primary-600' => $activeTab === 'attendance',
                    'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $activeTab !== 'attendance',
                ])
            >
                Attendance
            </button>
        @endif
        @if($canViewAssessments)
            <button
                type="button"
                wire:click="setProfileTab('assessments')"
                @class([
                    'px-3 pb-2 text-sm font-medium transition border-b-2 border-transparent focus:outline-none',
                    'text-primary-600 border-primary-600' => $activeTab === 'assessments',
                    'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $activeTab !== 'assessments',
                ])
            >
                Assessments
            </button>
        @endif
        <button
            type="button"
            wire:click="setProfileTab('classes')"
            @class([
                'px-3 pb-2 text-sm font-medium transition border-b-2 border-transparent focus:outline-none',
                'text-primary-600 border-primary-600' => $activeTab === 'classes',
                'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $activeTab !== 'classes',
            ])
        >
            Classes
        </button>
        @if($isAdminView && $showPersonalCard)
            <button
                type="button"
                wire:click="setProfileTab('contact')"
                @class([
                    'px-3 pb-2 text-sm font-medium transition border-b-2 border-transparent focus:outline-none',
                    'text-primary-600 border-primary-600' => $activeTab === 'contact',
                    'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $activeTab !== 'contact',
                ])
            >
                Contact info
            </button>
        @endif
        @if($isAdminView)
            <button
                type="button"
                wire:click="setProfileTab('employment')"
                @class([
                    'px-3 pb-2 text-sm font-medium transition border-b-2 border-transparent focus:outline-none',
                    'text-primary-600 border-primary-600' => $activeTab === 'employment',
                    'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $activeTab !== 'employment',
                ])
            >
                Employment
            </button>
            <button
                type="button"
                wire:click="setProfileTab('manager')"
                @class([
                    'px-3 pb-2 text-sm font-medium transition border-b-2 border-transparent focus:outline-none',
                    'text-primary-600 border-primary-600' => $activeTab === 'manager',
                    'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $activeTab !== 'manager',
                ])
            >
                Manager
            </button>
        @endif
        <button
            type="button"
            wire:click="setProfileTab('notes')"
            @class([
                'px-3 pb-2 text-sm font-medium transition border-b-2 border-transparent focus:outline-none',
                'text-primary-600 border-primary-600' => $activeTab === 'notes',
                'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $activeTab !== 'notes',
            ])
        >
            Notes
        </button>
        <button
            type="button"
            wire:click="setProfileTab('skills')"
            @class([
                'px-3 pb-2 text-sm font-medium transition border-b-2 border-transparent focus:outline-none',
                'text-primary-600 border-primary-600' => $activeTab === 'skills',
                'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $activeTab !== 'skills',
            ])
        >
            Skill development
        </button>
    </div>

    @if($isAdminView && $activeTab === 'attendance')
        <div class="w-full rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            @include('filament.students.attendance-card-host', ['studentId' => $student->id])
        </div>
        <div class="w-full rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            @include('filament.infolists.student-attendance-history', ['studentId' => $student->id])
        </div>
    @endif

    @if($activeTab === 'assessments' && $canViewAssessments)
        @include('filament.students.assessments-host', ['studentId' => $student->id, 'isUkStudent' => $isUkStudent])
    @endif

    @if($activeTab === 'classes')
        <div class="space-y-6">
            <details class="group overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" open>
                <summary class="flex cursor-pointer items-center justify-between px-6 py-4 text-base font-semibold text-gray-800 transition hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-800">
                    <span>Upcoming classes</span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-5 w-5 transition group-open:-rotate-180" />
                </summary>
                <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                    @include('filament.students.upcoming-classes-host', ['studentId' => $student->id, 'isUk' => $isUkStudent])
                </div>
            </details>

            <details class="group overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <summary class="flex cursor-pointer items-center justify-between px-6 py-4 text-base font-semibold text-gray-800 transition hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-800">
                    <span>Past classes</span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-5 w-5 transition group-open:-rotate-180" />
                </summary>
                <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                    @include('filament.students.past-classes-host', ['studentId' => $student->id, 'isUk' => $isUkStudent])
                </div>
            </details>
        </div>
    @endif

    @if($isAdminView && $activeTab === 'contact' && $showPersonalCard)
        <div class="space-y-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <livewire:admin.student-contact-form :student-id="$student->id" :read-only="!$allowAdminForms" />
            </div>
            @unless($isUkManagerViewer)
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">Enrollment delivery</h3>
                            <p class="text-sm text-gray-500">Latest message status, channel, and sender.</p>
                        </div>
                        @if($enrollmentSenderName)
                            <span class="text-xs text-gray-500">Last sent by {{ $enrollmentSenderName }}</span>
                        @endif
                    </div>
                    @include('filament.students.partials.enrollment-summary-table', [
                        'summary' => $enrollmentSummary,
                        'sender' => $enrollmentSenderName,
                    ])

                    @if($canTriggerEnrollmentActions)
                        <div class="mt-6 flex flex-wrap gap-3">
                            <x-filament::button color="success" wire:click="sendEnrollmentWhatsApp" wire:loading.attr="disabled">
                                <x-filament::icon icon="heroicon-o-chat-bubble-bottom-center-text" class="h-4 w-4" />
                                <span>Send enrolment via WhatsApp</span>
                            </x-filament::button>
                            <x-filament::button color="primary" wire:click="sendEnrollmentSmsAndEmail" wire:loading.attr="disabled">
                                <x-filament::icon icon="heroicon-o-envelope" class="h-4 w-4" />
                                <span>Send enrolment via SMS / Email</span>
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @endunless
        </div>
    @endif

    @if($isAdminView && $activeTab === 'employment')
        <div class="w-full rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <livewire:admin.student-employment-profile-form :student-id="$student->id" :read-only="!$allowAdminForms" />
        </div>
        @if (config('luminaworks.enabled'))
            <livewire:admin.lumina-works-matches-card :student-id="$student->id" :read-only="!$allowAdminForms" />
        @endif
    @endif

    @if($isAdminView && $activeTab === 'manager')
        <div class="space-y-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <livewire:uk-student-manager-card :student-id="$student->id" :read-only="!$allowAdminForms" />
            </div>
        </div>
    @endif

    @if($activeTab === 'notes')
        <div class="space-y-6">
            <details class="group overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" open>
                <summary class="flex cursor-pointer items-center justify-between px-6 py-4 text-base font-semibold text-gray-800 transition hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-800">
                    <span>Progress notes</span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-5 w-5 transition group-open:-rotate-180" />
                </summary>
                <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                    @include('filament.students.progress-notes-host', ['studentId' => $student->id])
                </div>
            </details>

            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <livewire:admin.student-notes-form :student-id="$student->id" :read-only="!$allowAdminForms" />
            </div>
        </div>
    @endif

    @if($activeTab === 'skills')
        <div class="space-y-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">Skill development</h2>
                <div class="mt-3">
                    @include('filament.students.skill-progress-host', ['studentId' => $student->id, 'layout' => $skillLayout])
                </div>
            </div>
        </div>
    @endif
</div>
