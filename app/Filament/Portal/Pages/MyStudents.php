<?php

namespace App\Filament\Portal\Pages;

use App\Models\Student;
use App\Support\TeacherRoster;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MyStudents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'My Students';
    protected static ?string $title = 'My Students';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationGroup = null;
    protected static string $view = 'filament.portal.pages.my-students';
    protected static string $routePath = 'students';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->isTeachingRole() || $user->hasRole('admin', 'super_admin');
    }

    public function table(Table $table): Table
    {
        $user = Auth::user();

        $query = Student::query()->whereRaw('1 = 0');

        if ($user) {
            $keys = TeacherRoster::studentLookupKeys($user);

            if (! empty($keys['ids']) || ! empty($keys['emails']) || ! empty($keys['client_ids'])) {
                $query = Student::query()
                    ->when($user->restrictsByRegion(), fn ($q) => $q->whereIn('location', $user->allowedRegions()))
                    ->where(function ($q) use ($keys) {
                        if (! empty($keys['ids'])) {
                            $q->orWhereIn('id', $keys['ids']);
                        }
                        if (! empty($keys['emails'])) {
                            $q->orWhereIn('email_norm', $keys['emails']);
                        }
                        if (! empty($keys['client_ids'])) {
                            $q->orWhereIn('acuity_client_id', $keys['client_ids']);
                        }
                    });
            }
        }

        return $table
            ->query($query)
            ->defaultSort('last_name')
            ->recordUrl(fn (Student $record) => route('filament.portal.pages.student-profile', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->label('First name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->label('Last name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('location')
                    ->label('Region')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('attendance_rate')
                    ->label('Attendance')
                    ->formatStateUsing(fn ($state) => is_null($state) ? '—' : number_format((float) $state, 1).' %')
                    ->badge()
                    ->color(function ($state) {
                        if (is_null($state)) {
                            return 'gray';
                        }

                        $value = (float) $state;
                        return $value >= 90 ? 'success' : ($value >= 75 ? 'warning' : 'danger');
                    }),
                Tables\Columns\TextColumn::make('manager.name')
                    ->label('Manager')
                    ->toggleable()
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Open profile')
                    ->icon('heroicon-o-user-circle')
                    ->url(fn (Student $record) => route('filament.portal.pages.student-profile', ['record' => $record]))
                    ->button()
            ])
            ->striped()
            ->paginated(true)
            ->emptyStateHeading('No students yet')
            ->emptyStateDescription('Once your classes are linked, the learners assigned to your calendar will appear here.');
    }
}
