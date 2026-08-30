<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\Student;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendanceRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'attendanceRecords';

    protected static ?string $title = 'Attendance';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('class_session_id')
                ->label('Class Session')
                ->options(function () {
                    // Offer recent and upcoming sessions for convenience
                    return ClassSession::query()
                        ->orderByDesc('session_date')
                        ->limit(500)
                        ->get()
                        ->mapWithKeys(function ($s) {
                            /** @var ClassSession $s */
                            $label = sprintf('%s %s–%s · %s', (string) $s->session_date, (string) $s->start_time, (string) $s->end_time, (string) ($s->calendar_name ?? 'Calendar'));
                            return [$s->id => $label];
                        })
                        ->toArray();
                })
                ->searchable()
                ->required()
                ->native(false),
            Forms\Components\Select::make('status')
                ->options([
                    'present' => 'Present',
                    'late' => 'Late',
                    'absent' => 'Absent',
                    'cancelled' => 'Cancelled',
                ])
                ->required()
                ->native(false),
            Forms\Components\DateTimePicker::make('marked_at')
                ->label('Marked At')
                ->default(fn() => now())
                ->seconds(false)
                ->timezone(config('app.timezone')),
            Forms\Components\Textarea::make('notes')
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('class_session_id')
            ->modifyQueryUsing(function (Builder $query) {
                // Most recent first
                $query->orderByDesc('marked_at')->orderByDesc('id');
            })
            ->columns([
                Tables\Columns\TextColumn::make('classSession.session_date')->label('Date')->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('classSession.start_time')->label('Start')->time('H:i')->sortable(),
                Tables\Columns\TextColumn::make('classSession.end_time')->label('End')->time('H:i')->sortable(),
                Tables\Columns\TextColumn::make('classSession.calendar_name')->label('Calendar')->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn($state) => match ((string) $state) {
                        'present' => 'success',
                        'late' => 'warning',
                        'absent' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('marked_at')->label('Marked')->since()->sortable(),
                Tables\Columns\TextColumn::make('notes')->toggleable(isToggledHiddenByDefault: true)->limit(40),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->using(function (array $data, RelationManager $livewire) {
                        // Ensure unique per session per student
                        $record = AttendanceRecord::recordStatus(
                            (int) $data['class_session_id'],
                            $livewire->getOwnerRecord()->id,
                            $data['status'] ?? 'present',
                            [
                                'marked_at' => $data['marked_at'] ?? now(),
                                'notes' => $data['notes'] ?? null,
                                'marked_by' => optional(auth()->user())->id,
                            ]
                        );
                        $livewire->notifyAttendanceUpdated();

                        return $record;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data, AttendanceRecord $record) {
                        // Lock session and student pair on edit
                        $data['class_session_id'] = $record->class_session_id;
                        $data['student_id'] = $record->student_id;
                        $data['marked_by'] = optional(auth()->user())->id;
                        return $data;
                    })
                    ->after(fn () => $this->notifyAttendanceUpdated()),
                Tables\Actions\DeleteAction::make()
                    ->after(fn () => $this->notifyAttendanceUpdated()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected function notifyAttendanceUpdated(): void
    {
        $student = $this->getOwnerRecord();
        if ($student instanceof Student) {
            $this->dispatch('attendance-updated', studentId: $student->id);
        }
    }
}
