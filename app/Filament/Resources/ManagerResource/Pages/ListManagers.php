<?php

namespace App\Filament\Resources\ManagerResource\Pages;

use App\Filament\Resources\ManagerResource;
use App\Models\Manager;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListManagers extends ListRecords
{
    protected static string $resource = ManagerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
            \Filament\Actions\Action::make('importCsv')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->form([
                    \Filament\Forms\Components\FileUpload::make('csv')
                        ->label('CSV file (name,email,phone optional)')
                        ->acceptedFileTypes(['text/csv','text/plain','application/csv'])
                        ->disk('local')
                        ->directory('imports/managers')
                        ->visibility('private')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $path = $data['csv'] ?? null;
                    if (!$path) {
                        return;
                    }

                    $disk = Storage::disk('local');
                    $full = $disk->path($path);
                    Log::info('Manager CSV import starting', [
                        'path' => $path,
                        'full' => $full,
                        'exists' => $disk->exists($path),
                    ]);

                    if (! $disk->exists($path)) {
                        return;
                    }

                    $contents = $disk->get($path);
                    $rows = preg_split("/(?:\r\n|\r|\n)/", $contents, -1, PREG_SPLIT_NO_EMPTY);
                    if (! $rows) {
                        Log::warning('Manager CSV import: no rows parsed', ['path' => $full]);
                        return;
                    }

                    $created = 0;
                    foreach ($rows as $line) {
                        if (trim($line) === '') {
                            continue;
                        }

                        $row = str_getcsv($line);
                        if (! is_array($row) || count($row) < 2) {
                            continue;
                        }

                        $name = trim((string) ($row[0] ?? ''));
                        $email = Str::lower(trim((string) ($row[1] ?? '')));
                        $phone = trim((string) ($row[2] ?? ''));

                        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            continue;
                        }

                        $manager = Manager::updateOrCreate(
                            ['email' => $email],
                            [
                                'name' => $name !== '' ? $name : $email,
                                'phone' => $phone !== '' ? $phone : null,
                            ]
                        );
                        $created++;
                        Log::info('Manager CSV import processed', [
                            'email' => $manager->email,
                            'id' => $manager->id,
                        ]);
                    }

                    if ($disk->exists($path)) {
                        $disk->delete($path);
                    }

                    \Filament\Notifications\Notification::make()
                        ->title('Managers imported')
                        ->body($created . ' row(s) processed')
                        ->success()
                        ->send();
                }),
        ];
    }
}
