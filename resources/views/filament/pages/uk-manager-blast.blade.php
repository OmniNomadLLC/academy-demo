<x-filament::page>
    <div class="space-y-4">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            <div class="text-sm text-gray-500 dark:text-gray-400">Quickly target every UK manager or open filters to refine the audience.</div>
            <div class="flex items-center gap-2">
                <x-filament::button type="button" color="primary" icon="heroicon-o-paper-airplane" wire:click="useAllManagers" wire:loading.attr="disabled">
                    Send to ALL
                </x-filament::button>
                <x-filament::button type="button" color="gray" icon="heroicon-o-adjustments-horizontal" wire:click="toggleFilters">
                    {{ $showFilters ? 'Hide filters' : 'Show filters' }}
                </x-filament::button>
            </div>
        </div>

        @if ($showFilters)
        <x-filament::card>
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 items-end">
                <div class="lg:col-span-3 space-y-2">
                    <label class="fi-input-label">Timeframe</label>
                    <select class="fi-input block w-full rounded-lg border-gray-300 py-2" wire:model="timeframe">
                        <option value="today">Today</option>
                        <option value="this_week">This week</option>
                        <option value="custom">Custom dates</option>
                    </select>
                </div>
                <div class="lg:col-span-6">
                    <div class="flex flex-col sm:flex-row sm:items-end sm:gap-3">
                        <div class="w-full space-y-2">
                            <label class="fi-input-label">From</label>
                            <x-filament::input type="date" wire:model="fromDate" class="py-2" />
                        </div>
                        <div class="w-full space-y-2">
                            <label class="fi-input-label">To</label>
                            <x-filament::input type="date" wire:model="toDate" class="py-2" />
                        </div>
                    </div>
                </div>
                <div class="lg:col-span-3 space-y-2">
                    <label class="fi-input-label">Attendance statuses</label>
                    <div class="flex flex-wrap gap-3 text-sm">
                        @foreach($this->statusOptions() as $value => $label)
                            <label class="inline-flex items-center gap-1">
                                <input type="checkbox" class="rounded border-gray-300" wire:model="statuses" value="{{ $value }}" />
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="lg:col-span-6">
                    <div class="flex flex-col sm:flex-row sm:items-end sm:gap-3">
                        <div class="w-full space-y-2">
                            <label class="fi-input-label">Calendar (optional)</label>
                            <select class="fi-input block w-full rounded-lg border-gray-300 py-2" wire:model.defer="calendar">
                                <option value="">All</option>
                                @foreach($this->availableUkCalendars() as $cal => $label)
                                    <option value="{{ $cal }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-full space-y-2">
                            <label class="fi-input-label">Manager (optional)</label>
                            <select class="fi-input block w-full rounded-lg border-gray-300 py-2" wire:model="managerId">
                                <option value="">All managers</option>
                                @foreach($this->managerOptions() as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                <p class="text-xs text-gray-500">Apply filters to rebuild the audience preview based on attendance activity.</p>
                <x-filament::button type="button" color="gray" wire:click="applyFilters" wire:loading.attr="disabled" icon="heroicon-m-funnel">Apply filters</x-filament::button>
            </div>
            <div class="mt-2 text-xs text-gray-500">
                {{ $matchingManagerCount }} manager(s) matched across {{ $matchingAttendanceCount }} attendance record(s).
            </div>
        </x-filament::card>
        @endif

        <x-filament::card>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div class="space-y-4">
                    <div class="space-y-2">
                        <label class="fi-input-label">Recipients</label>
                        <textarea class="fi-input block w-full rounded-lg border-gray-300 min-h-[120px] py-2" wire:model.defer="recipientList" readonly></textarea>
                    </div>
                    <div class="space-y-2">
                        <label class="fi-input-label">Subject</label>
                        <div class="rounded-lg border border-gray-300 shadow-sm">
                            <x-filament::input type="text" wire:model.defer="subject" class="py-2 border-0 shadow-none" />
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="fi-input-label">Message</label>
                        <div
                            class="space-y-2"
                            x-data="managerBlastEditor()"
                            x-init="init()"
                        >
                        <div class="flex flex-wrap gap-2 text-sm">
                            <button type="button" x-on:click.prevent="format('bold')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50 font-semibold" title="Bold"><span class="font-bold">B</span></button>
                            <button type="button" x-on:click.prevent="format('italic')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50 italic" title="Italic">I</button>
                            <button type="button" x-on:click.prevent="format('underline')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Underline"><span class="underline">U</span></button>
                            <button type="button" x-on:click.prevent="format('strikeThrough')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Strikethrough"><span class="line-through">S</span></button>
                            <button type="button" x-on:click.prevent="insertList('unordered')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Bullet list">• • •</button>
                            <button type="button" x-on:click.prevent="insertList('ordered')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Numbered list">1.2.3.</button>
                            <button type="button" x-on:click.prevent="formatBlock('blockquote')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Quote">“”</button>
                            <button type="button" x-on:click.prevent="formatBlock('p')" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Paragraph">¶</button>
                            <button type="button" x-on:click.prevent="insertLink()" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50" title="Insert link">🔗</button>
                            <button type="button" x-on:click.prevent="clear()" class="px-3 py-1 border rounded bg-white shadow-sm hover:bg-gray-50 text-red-600" title="Clear formatting">Clear</button>
                        </div>
                        <div
                            x-ref="editor"
                            class="fi-input block w-full rounded-lg border-gray-300 min-h-[220px] p-3 bg-white focus:outline-none focus:ring-2 focus:ring-primary-500 manager-blast-editor border border-gray-300"
                            contenteditable="true"
                            x-on:input="updateContent"
                            x-on:blur="sync"
                            x-on:paste="onPaste"
                        ></div>
                        <textarea x-ref="hiddenField" class="hidden" wire:model.defer="message"></textarea>
                    </div>
                </div>
            </div>
            <div class="mt-3 text-right">
                <x-filament::button color="primary" wire:click="send" wire:loading.attr="disabled" icon="heroicon-m-paper-airplane">Send</x-filament::button>
            </div>
        </x-filament::card>
    </div>
</x-filament::page>

@once
    @push('scripts')
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('managerBlastEditor', () => ({
                    init() {
                        const html = this.$refs.hiddenField?.value ?? '';
                        this.setEditorContent(html);
                        this.syncHidden();

                        Livewire.hook('message.processed', () => {
                            const updated = this.$refs.hiddenField?.value ?? '';
                            if (this.normalize(this.$refs.editor.innerHTML) !== this.normalize(updated)) {
                                this.setEditorContent(updated);
                                this.syncHidden();
                            }
                        });
                    },
                    setEditorContent(html) {
                        this.$refs.editor.innerHTML = html || '';
                    },
                    syncHidden() {
                        if (this.$refs.hiddenField) {
                            this.$refs.hiddenField.value = this.$refs.editor.innerHTML ?? '';
                        }
                    },
                    normalize(html) {
                        return (html || '').replace(/\s+/g, ' ').trim();
                    },
                    focusEditor() {
                        this.$refs.editor.focus({ preventScroll: true });
                    },
                    format(command, value = null) {
                        this.focusEditor();
                        document.execCommand(command, false, value);
                        this.afterInput();
                    },
                    formatBlock(tag) {
                        this.focusEditor();
                        const block = tag?.startsWith('<') ? tag : `<${tag}>`;
                        document.execCommand('formatBlock', false, block);
                        this.afterInput();
                    },
                    insertLink() {
                        const url = prompt('Enter URL');
                        if (! url) {
                            return;
                        }
                        this.focusEditor();
                        document.execCommand('createLink', false, url);
                        this.afterInput();
                    },
                    insertList(type) {
                        this.focusEditor();
                        if (type === 'ordered') {
                            document.execCommand('insertOrderedList');
                        } else {
                            document.execCommand('insertUnorderedList');
                        }
                        this.afterInput();
                    },
                    clear() {
                        this.$refs.editor.innerHTML = '';
                        this.afterInput();
                    },
                    updateContent() {
                        this.afterInput();
                    },
                    sync() {
                        this.afterInput();
                    },
                    onPaste(event) {
                        event.preventDefault();
                        const text = (event.clipboardData || window.clipboardData)?.getData('text/plain') ?? '';
                        this.focusEditor();
                        document.execCommand('insertText', false, text);
                        this.afterInput();
                    },
                    afterInput() {
                        const html = this.$refs.editor.innerHTML ?? '';
                        this.syncHidden();
                        if (this.$refs.hiddenField) {
                            const hidden = this.$refs.hiddenField;
                            hidden.value = html;
                            hidden.dispatchEvent(new Event('input', { bubbles: true }));
                            hidden.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    },
                }));
            });
        </script>
    @endpush
    @push('styles')
        <style>
            .manager-blast-editor ul,
            .manager-blast-editor ol {
                margin: 0.5rem 0 0.75rem 1.5rem;
                padding: 0;
            }

            .manager-blast-editor ul {
                list-style: disc outside;
            }

            .manager-blast-editor ol {
                list-style: decimal outside;
            }

            .manager-blast-editor li {
                margin-bottom: 0.35rem;
            }
        </style>
    @endpush
@endonce
