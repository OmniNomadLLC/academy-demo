<?php

namespace App\Filament\Portal\Pages;

use App\Models\Tutorial;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class Help extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Help';

    protected static ?int $navigationSort = 99;

    protected static ?string $title = 'Help & Tutorials';

    protected static string $view = 'filament.pages.help';

    public string $search = '';

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public function canManage(): bool
    {
        return false;
    }

    public function manageUrl(): ?string
    {
        return null;
    }

    public function getGroupedTutorials(): Collection
    {
        $user = Auth::user();
        $search = trim($this->search);

        return Tutorial::query()
            ->visibleToUser($user)
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.strtolower($search).'%';
                $q->where(function ($inner) use ($term) {
                    $inner->whereRaw('LOWER(title) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(description, "")) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(category, "")) LIKE ?', [$term]);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->groupBy(fn (Tutorial $t) => $t->category ?: 'General');
    }
}
