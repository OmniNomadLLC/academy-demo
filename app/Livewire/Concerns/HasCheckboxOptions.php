<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Checkbox option safety utilities for Livewire components.
 *
 * NEVER store Eloquent models in Livewire public properties.
 * ALWAYS normalize before assigning to state.
 * ALWAYS sanitize before saving.
 * ALWAYS cast IDs to int.
 * ALWAYS call values()->all() after mapping.
 *
 * This trait is intentionally strict and fails fast if data contracts break.
 * WARNING: This system depends on ['id', 'name'] structures globally.
 * Modifying the keys requires updating every checkbox consumer.
 */
trait HasCheckboxOptions
{
    protected function normalizeOptions($collection): array
    {
        if (! $collection instanceof Collection) {
            return [];
        }

        $normalized = $collection
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
            ])
            ->values()
            ->all();

        foreach ($normalized as $item) {
            if (! isset($item['id'], $item['name'])) {
                throw new \RuntimeException('Normalization failed: invalid item structure.');
            }
        }

        return $normalized;
    }

    protected function normalizeIds(array $ids): array
    {
        $ids = array_map('intval', (array) $ids);
        $ids = array_filter($ids, fn ($id) => $id > 0);
        $ids = array_unique($ids);

        return array_values($ids);
    }

    protected function hydratePivotIds(Relation $relation, ?string $column = null): array
    {
        $column ??= $relation->getRelated()->getTable().'.id';

        return $relation
            ->pluck($column)
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    protected function assertOptionsAreValid(array $options): void
    {
        if (! is_array($options)) {
            throw new \RuntimeException('Options must be an array.');
        }

        if (empty($options)) {
            return;
        }

        $first = $options[array_key_first($options)];
        if (! is_array($first) || ! array_key_exists('id', $first) || ! array_key_exists('name', $first)) {
            throw new \RuntimeException('Invalid options structure: expected [id, name].');
        }
    }

    protected function assertIdsAreValid(array $ids): void
    {
        foreach ($ids as $id) {
            if (! is_int($id)) {
                throw new \RuntimeException('Selected IDs must be integers.');
            }
        }
    }

    protected function safeSync(Relation $relation, array $ids): void
    {
        $relation->sync($this->normalizeIds($ids));
    }

    protected function ensureArray(&$property): void
    {
        if (! is_array($property)) {
            $property = [];
        }
    }
}
