<?php

namespace App\Filament\Widgets;

use App\Models\Student;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentStudents extends BaseWidget
{
    protected static bool $isDiscovered = false; // hide by default unless explicitly added
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Student::query()
                    ->where('is_active', true)
                    ->latest()
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->label('First Name'),
                Tables\Columns\TextColumn::make('last_name')
                    ->label('Last Name'),
                Tables\Columns\TextColumn::make('email'),
                Tables\Columns\TextColumn::make('registration_date')
                    ->date(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->since(),
            ]);
    }

    protected function getTableHeading(): string
    {
        return 'Recent Students';
    }
}
