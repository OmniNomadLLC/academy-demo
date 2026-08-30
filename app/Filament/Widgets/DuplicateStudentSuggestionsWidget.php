<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\MergeStudentsAction;
use App\Models\StudentMergeSuggestion;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class DuplicateStudentSuggestionsWidget extends Widget implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;

    protected static string $view = 'filament.widgets.duplicate-student-suggestions';

    // Render above all other dashboard widgets
    protected static ?int $sort = -1000;

    protected int | string | array $columnSpan = 'full';

    public bool $expanded = false;

    /** ID of the student the admin chose to keep in the merge modal. */
    public int $keepStudentId = 0;

    public function toggleExpanded(): void
    {
        $this->expanded = ! $this->expanded;
    }

    // -------------------------------------------------------------------------
    // Direct action triggers (avoids @js() / JSON.parse in wire:click attrs)
    // -------------------------------------------------------------------------

    public function initiateMerge(int $suggestionId): void
    {
        // Pre-select student A as the default "keep" choice
        $suggestion = StudentMergeSuggestion::find($suggestionId);
        $this->keepStudentId = $suggestion?->student_a_id ?? 0;

        $this->mountAction('merge', ['suggestionId' => $suggestionId]);
    }

    public function setKeepStudent(int $studentId): void
    {
        $this->keepStudentId = $studentId;
    }

    public function dismissSuggestion(int $suggestionId): void
    {
        $suggestion = StudentMergeSuggestion::find($suggestionId);

        if (! $suggestion || $suggestion->status !== 'pending') {
            return;
        }

        $suggestion->update([
            'status'      => 'dismissed',
            'resolved_by' => Auth::id(),
            'resolved_at' => now(),
        ]);

        Notification::make()
            ->title('Suggestion dismissed')
            ->success()
            ->send();
    }

    /**
     * Only render this widget when there are pending suggestions.
     */
    public static function canView(): bool
    {
        return StudentMergeSuggestion::pending()->exists();
    }

    public function getPendingSuggestions(): \Illuminate\Database\Eloquent\Collection
    {
        return StudentMergeSuggestion::pending()
            ->with(['studentA', 'studentB'])
            ->latest()
            ->get();
    }

    // -------------------------------------------------------------------------
    // Filament action registration
    // -------------------------------------------------------------------------

    protected function getActions(): array
    {
        return [
            $this->mergeAction(),
        ];
    }

    public function mergeAction(): Action
    {
        return MergeStudentsAction::make('merge')
            ->action(function (array $arguments) {
                // $this->keepStudentId is read here — after the user may have
                // changed their selection in the modal — not frozen at mount time.
                MergeStudentsAction::execute($arguments, $this->keepStudentId);
                $this->keepStudentId = 0;
                $this->dispatch('$refresh');
            });
    }
}
