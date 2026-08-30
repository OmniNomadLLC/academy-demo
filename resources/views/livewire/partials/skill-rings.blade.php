<div class="flex flex-col items-center w-full">
    <div class="w-40 h-40 lg:w-48 lg:h-48">
        <x-gauges.skill-ring label="Write" :value="$current['writing']" />
    </div>
    <span class="mt-4 text-sm font-medium text-gray-600 dark:text-gray-300">Write</span>
</div>
<div class="flex flex-col items-center w-full">
    <div class="w-40 h-40 lg:w-48 lg:h-48">
        <x-gauges.skill-ring label="Read" :value="$current['reading']" />
    </div>
    <span class="mt-4 text-sm font-medium text-gray-600 dark:text-gray-300">Read</span>
</div>
<div class="flex flex-col items-center w-full">
    <div class="w-40 h-40 lg:w-48 lg:h-48">
        <x-gauges.skill-ring label="Speak" :value="$current['speaking']" />
    </div>
    <span class="mt-4 text-sm font-medium text-gray-600 dark:text-gray-300">Speak</span>
</div>
