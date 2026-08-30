<x-filament-widgets::widget>
    <x-filament::section>
        <div class="space-y-4">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-medium">Appointment Calendar</h3>
                <div class="flex space-x-2">
                    <span class="px-3 py-1 text-sm bg-blue-100 text-blue-800 rounded">{{ now()->format('F Y') }}</span>
                </div>
            </div>
            
            <!-- Calendar Grid -->
            <div class="grid grid-cols-7 gap-1 border border-gray-200 rounded-lg overflow-hidden">
                <!-- Days of week header -->
                @foreach(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day)
                    <div class="p-3 text-center text-sm font-medium text-gray-700 bg-gray-50 border-b border-gray-200">
                        {{ $day }}
                    </div>
                @endforeach
                
                <!-- Calendar days -->
                @php
                    $startOfMonth = now()->startOfMonth();
                    $endOfMonth = now()->endOfMonth();
                    $startDate = $startOfMonth->copy()->startOfWeek();
                    $endDate = $endOfMonth->copy()->endOfWeek();
                    $currentDate = $startDate->copy();
                @endphp
                
                @while($currentDate <= $endDate)
                    <div class="min-h-24 p-2 border-b border-r border-gray-200 bg-white {{ $currentDate->month !== now()->month ? 'bg-gray-50' : '' }}">
                        <div class="text-sm font-medium {{ $currentDate->isToday() ? 'text-blue-600 font-bold' : 'text-gray-900' }}">
                            {{ $currentDate->day }}
                        </div>
                        
                        <!-- Sessions for this day -->
                        @foreach($sessions->where('session_date', $currentDate->format('Y-m-d')) as $session)
                            @php
                                $calendarName = $session->acuity_data['calendar'] ?? 'Unknown';
                                $color = match(true) {
                                    str_contains($calendarName, 'English') => 'bg-blue-100 text-blue-800',
                                    str_contains($calendarName, 'French') => 'bg-green-100 text-green-800',
                                    str_contains($calendarName, 'Spanish') => 'bg-red-100 text-red-800',
                                    str_contains($calendarName, 'Harbour') => 'bg-purple-100 text-purple-800',
                                    default => 'bg-gray-100 text-gray-800'
                                };
                            @endphp
                            <div class="text-xs p-1 mt-1 {{ $color }} rounded truncate" title="{{ $session->schoolClass->name }} - {{ $calendarName }}">
                                <div class="font-medium">{{ $session->start_time }}</div>
                                <div class="truncate">{{ $session->schoolClass->name }}</div>
                                <div class="text-xs opacity-75">{{ $calendarName }}</div>
                            </div>
                        @endforeach
                    </div>
                    @php $currentDate->addDay(); @endphp
                @endwhile
            </div>
            
            <!-- Legend -->
            <div class="flex flex-wrap gap-4 text-sm">
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 bg-blue-100 rounded"></div>
                    <span>English</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 bg-green-100 rounded"></div>
                    <span>French</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 bg-red-100 rounded"></div>
                    <span>Spanish</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 bg-purple-100 rounded"></div>
                    <span>Harbour</span>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>