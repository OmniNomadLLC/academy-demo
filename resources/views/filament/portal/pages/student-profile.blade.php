<x-filament-panels::page>
    @if ($student)
        @include('filament.students.profile-layout', [
            'student' => $student,
            'isUkStudent' => $isUkStudent,
            'showPersonalCard' => false,
            'layout' => 'portal',
            'activeTab' => $this->activeTab ?? 'assessments',
            'photoActions' => true,
        ])
    @else
        <x-filament::section class="max-w-none text-center">
            <x-slot name="heading">Student not found</x-slot>
            <x-slot name="description">Return to the student list to choose a learner linked to your calendar.</x-slot>
            <x-filament::button color="primary" tag="a" :href="route('filament.portal.pages.my-students')">
                Back to My Students
            </x-filament::button>
        </x-filament::section>
    @endif
</x-filament-panels::page>
