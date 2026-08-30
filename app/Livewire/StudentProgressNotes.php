<?php

namespace App\Livewire;

use App\Models\StudentProgressNote;
use App\Support\Html\RichTextSanitizer;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class StudentProgressNotes extends Component implements HasForms
{
    use WithFileUploads;
    use InteractsWithForms;

    public int $studentId;
    public array $notes = [];
    public int $page = 1;
    public int $perPage = 5;
    public bool $hasMore = false;
    public bool $showingAll = false;
    public ?array $data = [
        'note' => '',
    ];
    public ?TemporaryUploadedFile $upload = null;
    public bool $importModalOpen = false;

    public function mount(int $studentId): void
    {
        $this->studentId = $studentId;
        $this->loadNotes(resetPage: true);
        $this->form->fill();
    }

    #[On('progress-note-updated')]
    public function refreshNotes(int $studentId): void
    {
        if ($studentId === $this->studentId) {
            $this->loadNotes(resetPage: true);
        }
    }

    public function save(): void
    {
        $state = $this->form->getState();
        // Sanitize server-side: the RichEditor's client-side sanitization can be
        // bypassed by posting a crafted Livewire payload, so never trust the raw HTML.
        $payload = RichTextSanitizer::clean((string) ($state['note'] ?? ''));

        if ($payload === '') {
            $this->addError('data.note', 'Please enter a note before saving.');
            return;
        }

        DB::transaction(function () use ($payload) {
            StudentProgressNote::create([
                'student_id' => $this->studentId,
                'body' => $payload,
                'recorded_at' => now(),
                'created_by' => Auth::id(),
            ]);
        });

        $this->form->fill(['note' => '']);
        $this->loadNotes(resetPage: true);

        Notification::make()
            ->title('Progress note saved')
            ->success()
            ->send();

        $this->dispatch('progress-note-updated', studentId: $this->studentId);
    }

    public function delete(int $id): void
    {
        $note = StudentProgressNote::where('student_id', $this->studentId)->find($id);
        if (! $note) {
            return;
        }

        $note->delete();
        $this->loadNotes(resetPage: true);

        Notification::make()
            ->title('Progress note removed')
            ->success()
            ->send();
    }

    public function importUpload(): void
    {
        $this->validate([
            'upload' => ['required', 'file', 'mimes:txt,doc,docx', 'max:2048'],
        ]);

        $contents = $this->extractTextFromUpload($this->upload);

        if ($contents === '') {
            throw ValidationException::withMessages([
                'upload' => 'We could not read any text from that file. Try saving it as a different format and upload again.',
            ]);
        }

        $paragraphs = $this->convertTextToParagraphs($contents);

        if ($paragraphs === '') {
            throw ValidationException::withMessages([
                'upload' => 'We could not find any paragraphs to import from that file.',
            ]);
        }

        $current = trim((string) ($this->form->getState()['note'] ?? ''));
        $separator = $current === '' ? '' : '<p><br></p>';
        $this->form->fill([
            'note' => $current.$separator.$paragraphs,
        ]);

        $this->reset('upload');
        $this->importModalOpen = false;
        $this->dispatch('close-modal', id: 'student-note-import');

        Notification::make()
            ->title('Text imported into note')
            ->success()
            ->send();

        $this->dispatch('progress-note-updated', studentId: $this->studentId);
    }

    public function showImportModal(): void
    {
        $this->resetErrorBag('upload');
        $this->reset('upload');
        $this->importModalOpen = true;
        $this->dispatch('open-modal', id: 'student-note-import');
    }

    public function cancelImport(): void
    {
        $this->reset('upload');
        $this->importModalOpen = false;
        $this->dispatch('close-modal', id: 'student-note-import');
    }

    public function loadMore(): void
    {
        if ($this->hasMore) {
            $this->page++;
            $this->loadNotes();
        } else {
            $this->showingAll = true;
            $this->loadNotes();
        }
    }

    protected function loadNotes(bool $resetPage = false): void
    {
        if ($resetPage) {
            $this->page = 1;
            $this->showingAll = false;
        }

        $query = StudentProgressNote::query()
            ->where('student_id', $this->studentId)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $records = $query
            ->forPage($this->page, $this->perPage)
            ->get();

        $this->hasMore = ($this->page * $this->perPage) < $total;
        $this->showingAll = ! $this->hasMore && $this->page > 1;

        $this->notes = $records
            ->map(fn (StudentProgressNote $note) => [
                'id' => $note->id,
                // Sanitize on read too, so any payload stored before this guard
                // existed is neutralized before {!! !!} renders it.
                'body' => RichTextSanitizer::clean($note->body),
                'recorded_at' => optional($note->recorded_at)->format('d M Y H:i'),
                'author' => optional($note->author)->name,
            ])
            ->toArray();
    }

    public function showLess(): void
    {
        $this->showingAll = false;
        $this->loadNotes(resetPage: true);
    }

    protected function extractTextFromUpload(?TemporaryUploadedFile $file): string
    {
        if (! $file) {
            return '';
        }

        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: ''));

        $text = match ($extension) {
            'docx' => $this->extractDocxText($file),
            'doc' => $this->extractDocText($file),
            default => $this->normalizeText($file->get()),
        };

        return $this->hasReadableText($text) ? $text : '';
    }

    protected function extractDocxText(TemporaryUploadedFile $file): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($file->getRealPath()) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return '';
        }

        $xml = preg_replace('/<w:br\\b[^>]*\/>/i', "\n", $xml ?? '');
        $xml = str_replace(['</w:p>', '</w:tr>', '</w:tbl>'], "\n", $xml);
        $text = strip_tags((string) $xml);

        return $this->normalizeText($text);
    }

    protected function extractDocText(TemporaryUploadedFile $file): string
    {
        $contents = @file_get_contents($file->getRealPath());

        if ($contents === false) {
            return '';
        }

        $contents = str_replace("\r", "\n", $contents);
        $contents = preg_replace('/\x00+/', "\n", $contents);
        $contents = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $contents);

        return $this->normalizeText($contents ?? '');
    }

    protected function hasReadableText(string $text): bool
    {
        $trimmed = preg_replace('/\s+/u', '', $text ?? '');

        if ($trimmed === '') {
            return false;
        }

        $printable = preg_match_all('/[A-Za-z0-9À-ÿ]/u', $trimmed, $matches);
        $length = mb_strlen($trimmed, 'UTF-8');

        if ($length === 0) {
            return false;
        }

        return ($printable / $length) >= 0.2;
    }

    protected function normalizeText(string $text): string
    {
        $normalized = trim($text);

        if ($normalized === '') {
            return '';
        }

        if (! mb_check_encoding($normalized, 'UTF-8')) {
            $normalized = mb_convert_encoding($normalized, 'UTF-8');
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);
        $normalized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $normalized);
        $normalized = preg_replace('/[ \t]+/', ' ', $normalized);
        $normalized = preg_replace('/\n{3,}/', "\n\n", $normalized);

        return trim($normalized);
    }

    protected function convertTextToParagraphs(string $text): string
    {
        return collect(preg_split('/\r\n|\r|\n/', $text))
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->map(fn ($line) => '<p>'.e($line).'</p>')
            ->implode('');
    }

    public function render()
    {
        return view('livewire.student-progress-notes');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                RichEditor::make('note')
                    ->label('Note')
                    ->placeholder('Write a quick update...')
                    ->toolbarButtons([
                        'bold',
                        'italic',
                        'underline',
                        'strike',
                        'blockquote',
                        'bulletList',
                        'orderedList',
                        'link',
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }
}
