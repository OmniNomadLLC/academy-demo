<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Connected roster</h2>
                    <p class="text-sm text-gray-500">
                        {{ $preferredRegionLabel === 'All Regions' ? 'Showing every linked student' : ('Showing students for '.$preferredRegionLabel) }}
                    </p>
                </div>
                @if(($context['role'] ?? null) === 'manager' && !empty($context['manager']))
                    <x-filament::badge color="primary">
                        {{ $context['manager']->name }}
                    </x-filament::badge>
                @endif
            </div>
        </div>

        @if(($context['missingManager'] ?? false) === true)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-m-information-circle" class="mt-0.5 h-5 w-5 text-amber-500" />
                    <div>
                        <p class="font-semibold">Manager profile not linked</p>
                        <p class="mt-1 text-amber-700">We couldn’t find a manager record that matches your login. Ask an admin to link one so students appear here.</p>
                    </div>
                </div>
            </div>
        @endif

        @if($students->isEmpty())
            <div class="flex flex-col items-center justify-center rounded-3xl border border-dashed border-gray-300 bg-white p-10 text-center">
                <x-filament::icon icon="heroicon-o-user-group" class="h-10 w-10 text-gray-400" />
                <h3 class="mt-3 text-lg font-semibold text-gray-900">No students yet</h3>
                <p class="mt-2 max-w-md text-sm text-gray-500">
                    Once your account is linked to students, they’ll appear here with attendance and skill progress at a glance.
                </p>
            </div>
        @else
            <div class="space-y-6">
                @foreach($students as $student)
                    @php
                        $attendance = (float) ($student->attendance_rate ?? 0);
                        $badgeColor = $attendance < 75 ? 'danger' : 'success';
                        $badgeLabel = $attendance < 75 ? 'Low attendance' : 'On track';
                        $upcomingKey = 'upcoming-'.$student->id;
                        $isUkStudent = is_string($student->location ?? null)
                            && strtolower(trim($student->location)) === 'uk';
                    @endphp
                    <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h3 class="text-xl font-semibold text-gray-900">{{ $student->full_name }}</h3>
                                <div class="mt-1 text-sm text-gray-500 flex flex-wrap items-center gap-2">
                                    <span>{{ $student->email }}</span>
                                    @if(!empty($student->phone))
                                        <span>• {{ $student->phone }}</span>
                                    @endif
                                    @if(!empty($student->location))
                                        <span>• {{ $student->location }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge color="{{ $badgeColor }}">
                                    {{ $badgeLabel }}
                                </x-filament::badge>
                                <x-filament::badge color="gray">
                                    {{ number_format($attendance, 1) }}% attendance
                                </x-filament::badge>
                                <x-filament::button size="sm" color="primary" icon="heroicon-o-user-circle"
                                    :href="route('filament.portal.pages.student-profile', ['record' => $student->id])">
                                    View profile
                                </x-filament::button>
                            </div>
                        </div>

                        <div class="mt-6 grid gap-6 lg:grid-cols-3">
                            <div class="rounded-2xl border border-dashed border-gray-200 p-4">
                                @livewire('student-attendance-card', ['studentId' => $student->id], key('attendance-'.$student->id))
                            </div>
                            <div class="rounded-2xl border border-dashed border-gray-200 p-4">
                                @livewire('student-skill-progress-card', ['studentId' => $student->id], key('skills-'.$student->id))
                            </div>
                            <div class="rounded-2xl border border-dashed border-gray-200 p-4">
                                @livewire('student-upcoming-classes', ['studentId' => $student->id, 'isUk' => $isUkStudent], key($upcomingKey))
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
