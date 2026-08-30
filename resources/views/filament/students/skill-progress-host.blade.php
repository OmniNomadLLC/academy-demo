<div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    @livewire('student-skill-progress-card', [
        'studentId' => $studentId,
        'layout' => $layout ?? 'admin',
    ])
</div>
