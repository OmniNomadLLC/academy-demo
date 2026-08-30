<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Tutorial extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'category',
        'content_type',
        'file_path',
        'content',
        'visible_to_roles',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'visible_to_roles' => 'array',
        'sort_order'       => 'integer',
    ];

    // Phase B: when content_type=article, render the content field as rich HTML instead of PDF link.

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeVisibleToUser(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }
        $role = strtolower((string) ($user->role ?? ''));
        return $query->whereJsonContains('visible_to_roles', $role);
    }

    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }
        return Storage::disk('public')->url($this->file_path);
    }

    public function getIconAttribute(): string
    {
        return $this->content_type === 'article'
            ? 'heroicon-o-book-open'
            : 'heroicon-o-document-text';
    }
}
