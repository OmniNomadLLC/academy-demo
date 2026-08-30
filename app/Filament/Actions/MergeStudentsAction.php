<?php

namespace App\Filament\Actions;

use App\Models\Student;
use App\Models\StudentMergeSuggestion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MergeStudentsAction extends Action
{
    /** Tables with a student_id FK that need to be repointed to the primary student. */
    private static array $fkTables = [
        'class_sessions',
        'attendance_records',
        'student_assessments',
        'student_progress_notes',
        'student_enrollment_messages',
        'employment_outcomes',
        'employment_profiles',
        'student_skill_progress_logs',
    ];

    public static function make(?string $name = null): static
    {
        // Note: the action() closure is intentionally left empty here.
        // The widget's mergeAction() method overrides it to pass $keepStudentId.
        return parent::make($name ?? 'merge_students')
            ->label('Merge →')
            ->color('warning')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalHeading('Confirm student merge')
            ->modalDescription(null)
            ->modalSubmitActionLabel('Yes, merge students')
            ->modalCancelActionLabel('Cancel')
            ->modalContent(fn (array $arguments) => self::buildModalContent($arguments));
    }

    public static function buildModalContent(array $arguments): \Illuminate\Contracts\View\View
    {
        $suggestion = StudentMergeSuggestion::with(['studentA', 'studentB'])
            ->find($arguments['suggestionId'] ?? null);

        return view('filament.actions.merge-students-modal', compact('suggestion'));
    }

    /**
     * @param  array<string, mixed>  $arguments  Must contain 'suggestionId'
     * @param  int  $keepStudentId  ID of the student to keep; 0 defaults to student_a
     */
    public static function execute(array $arguments, int $keepStudentId = 0): void
    {
        $suggestion = StudentMergeSuggestion::with(['studentA', 'studentB'])
            ->find($arguments['suggestionId'] ?? null);

        if (! $suggestion || $suggestion->status !== 'pending') {
            Notification::make()
                ->title('Merge skipped')
                ->body('This suggestion is no longer pending.')
                ->warning()
                ->send();
            return;
        }

        $studentA = $suggestion->studentA;
        $studentB = $suggestion->studentB;

        if (! $studentA || ! $studentB) {
            Notification::make()
                ->title('Merge failed')
                ->body('One or both student records could not be found.')
                ->danger()
                ->send();
            return;
        }

        // Honour the user's choice; default to keeping student A
        if ($keepStudentId === $studentB->id) {
            $primary   = $studentB;
            $duplicate = $studentA;
        } else {
            $primary   = $studentA;
            $duplicate = $studentB;
        }

        DB::transaction(function () use ($suggestion, $primary, $duplicate) {
            // Repoint all FK references from duplicate → primary
            foreach (self::$fkTables as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'student_id')) {
                    continue;
                }

                $moved = DB::table($table)
                    ->where('student_id', $duplicate->id)
                    ->update(['student_id' => $primary->id]);

                if ($moved) {
                    Log::info("MergeStudentsAction: repointed {$moved} rows in {$table}", [
                        'primary_id'   => $primary->id,
                        'duplicate_id' => $duplicate->id,
                    ]);
                }
            }

            // Copy acuity_client_id to the kept student if they are missing it
            if (empty($primary->acuity_client_id) && ! empty($duplicate->acuity_client_id)) {
                $primary->acuity_client_id = $duplicate->acuity_client_id;
                $primary->save();
            }

            // Soft-delete the removed student
            $duplicate->delete();

            // Mark suggestion resolved
            $suggestion->update([
                'status'      => 'merged',
                'resolved_by' => Auth::id(),
                'resolved_at' => now(),
            ]);

            Log::info('MergeStudentsAction: merge completed', [
                'suggestion_id' => $suggestion->id,
                'kept_id'       => $primary->id,
                'removed_id'    => $duplicate->id,
                'resolved_by'   => Auth::id(),
            ]);
        });

        Notification::make()
            ->title('Students merged')
            ->body(
                "#{$duplicate->id} ({$duplicate->first_name} {$duplicate->last_name}) merged into " .
                "{$primary->first_name} {$primary->last_name} (#{$primary->id}) — all data transferred."
            )
            ->success()
            ->send();
    }
}
