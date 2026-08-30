<x-filament-panels::page>
    @php
        $grouped = $this->getGroupedTutorials();
        $manageUrl = $this->manageUrl();
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div class="max-w-xl">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Guides and documentation for the Lumina platform. Click a card to open the PDF in a new tab.
                </p>
            </div>

            <div class="flex items-center gap-3">
                <div class="w-full md:w-72">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search title, description, category…"
                        class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                    />
                </div>

                @if ($manageUrl)
                    <a
                        href="{{ $manageUrl }}"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-500"
                    >
                        <x-heroicon-o-cog-6-tooth class="h-4 w-4" />
                        Manage tutorials
                    </a>
                @endif
            </div>
        </div>

        @if ($grouped->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                No tutorials available for your role yet.
            </div>
        @else
            @foreach ($grouped as $category => $items)
                <section class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ $category }}
                    </h3>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                        @foreach ($items as $tutorial)
                            <article class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md dark:border-gray-700 dark:bg-gray-900">
                                <div class="flex items-start gap-3">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-purple-100 text-purple-700 dark:bg-purple-500/10 dark:text-purple-300">
                                        @if ($tutorial->content_type === 'article')
                                            <x-heroicon-o-book-open class="h-5 w-5" />
                                        @else
                                            <x-heroicon-o-document-text class="h-5 w-5" />
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h4 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                            {{ $tutorial->title }}
                                        </h4>
                                        @if ($tutorial->category)
                                            <span class="mt-1 inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                                {{ $tutorial->category }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                @if ($tutorial->description)
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ $tutorial->description }}
                                    </p>
                                @endif

                                <div class="mt-auto pt-1">
                                    @if ($tutorial->content_type === 'pdf' && $tutorial->file_url)
                                        <a
                                            href="{{ $tutorial->file_url }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-primary-500"
                                        >
                                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                                            Open PDF
                                        </a>
                                    @elseif ($tutorial->content_type === 'article')
                                        <span class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                            Article (coming soon)
                                        </span>
                                    @else
                                        <span class="text-sm italic text-gray-400">File not uploaded yet</span>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </div>
</x-filament-panels::page>
