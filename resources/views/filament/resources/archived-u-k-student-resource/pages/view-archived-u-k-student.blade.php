<x-filament::page>
    @php
        $student = $this->record;
        $activeTab = $this->activeTab ?? 'assessments';
        $isUk = true;
    @endphp

    @if($student)
        @include('filament.students.profile-layout', [
            'student' => $student,
            'isUkStudent' => $isUk,
            'showPersonalCard' => true,
            'layout' => 'admin',
            'activeTab' => $activeTab,
            'photoActions' => false,
            'allowAdminForms' => false,
        ])
    @else
        <x-filament::section>
            <x-slot name="heading">Student not found</x-slot>
        </x-filament::section>
    @endif
</x-filament::page>
