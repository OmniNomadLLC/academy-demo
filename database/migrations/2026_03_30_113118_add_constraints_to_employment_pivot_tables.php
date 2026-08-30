<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        try {
            $this->ensureDataIsClean();
            $this->addUniqueConstraints();
            $this->addForeignKeys();

            Artisan::call('check:employment-pivot-integrity');
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to add employment pivot constraints: '.$e->getMessage(), 0, $e);
        }
    }

    public function down(): void
    {
        $this->dropForeignKeys();
        $this->dropUniqueConstraints();
    }

    protected function ensureDataIsClean(): void
    {
        if ($this->hasDuplicates('employment_profile_interest', ['employment_profile_id', 'employment_interest_id'])) {
            throw new \RuntimeException('Duplicate rows exist in employment_profile_interest.');
        }

        if ($this->hasDuplicates('employment_profile_availability', ['employment_profile_id', 'employment_availability_option_id'])) {
            throw new \RuntimeException('Duplicate rows exist in employment_profile_availability.');
        }

        if ($this->hasNulls('employment_profile_interest', ['employment_profile_id', 'employment_interest_id'])) {
            throw new \RuntimeException('Null foreign keys detected in employment_profile_interest.');
        }

        if ($this->hasNulls('employment_profile_availability', ['employment_profile_id', 'employment_availability_option_id'])) {
            throw new \RuntimeException('Null foreign keys detected in employment_profile_availability.');
        }

        if ($this->hasOrphans('employment_profile_interest', 'employment_profile_id', 'employment_profiles')) {
            throw new \RuntimeException('Orphan employment_profile_id detected in employment_profile_interest.');
        }

        if ($this->hasOrphans('employment_profile_interest', 'employment_interest_id', 'employment_interests')) {
            throw new \RuntimeException('Orphan employment_interest_id detected in employment_profile_interest.');
        }

        if ($this->hasOrphans('employment_profile_availability', 'employment_profile_id', 'employment_profiles')) {
            throw new \RuntimeException('Orphan employment_profile_id detected in employment_profile_availability.');
        }

        if ($this->hasOrphans('employment_profile_availability', 'employment_availability_option_id', 'employment_availability_options')) {
            throw new \RuntimeException('Orphan employment_availability_option_id detected in employment_profile_availability.');
        }
    }

    protected function addUniqueConstraints(): void
    {
        if (! $this->uniqueExists('employment_profile_interest', 'epi_unique_profile_interest')) {
            Schema::table('employment_profile_interest', function (Blueprint $table) {
                $table->unique(
                    ['employment_profile_id', 'employment_interest_id'],
                    'epi_unique_profile_interest'
                );
            });
        }

        if (! $this->uniqueExists('employment_profile_availability', 'epa_unique_profile_availability')) {
            Schema::table('employment_profile_availability', function (Blueprint $table) {
                $table->unique(
                    ['employment_profile_id', 'employment_availability_option_id'],
                    'epa_unique_profile_availability'
                );
            });
        }
    }

    protected function addForeignKeys(): void
    {
        if (! $this->foreignKeyExists('employment_profile_interest', 'epi_profile_fk')) {
            Schema::table('employment_profile_interest', function (Blueprint $table) {
                $table->foreign('employment_profile_id', 'epi_profile_fk')
                    ->references('id')->on('employment_profiles')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->foreignKeyExists('employment_profile_interest', 'epi_interest_fk')) {
            Schema::table('employment_profile_interest', function (Blueprint $table) {
                $table->foreign('employment_interest_id', 'epi_interest_fk')
                    ->references('id')->on('employment_interests')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->foreignKeyExists('employment_profile_availability', 'epa_profile_fk')) {
            Schema::table('employment_profile_availability', function (Blueprint $table) {
                $table->foreign('employment_profile_id', 'epa_profile_fk')
                    ->references('id')->on('employment_profiles')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->foreignKeyExists('employment_profile_availability', 'epa_availability_fk')) {
            Schema::table('employment_profile_availability', function (Blueprint $table) {
                $table->foreign('employment_availability_option_id', 'epa_availability_fk')
                    ->references('id')->on('employment_availability_options')
                    ->cascadeOnDelete();
            });
        }
    }

    protected function dropUniqueConstraints(): void
    {
        if ($this->uniqueExists('employment_profile_interest', 'epi_unique_profile_interest')) {
            Schema::table('employment_profile_interest', function (Blueprint $table) {
                $table->dropUnique('epi_unique_profile_interest');
            });
        }

        if ($this->uniqueExists('employment_profile_availability', 'epa_unique_profile_availability')) {
            Schema::table('employment_profile_availability', function (Blueprint $table) {
                $table->dropUnique('epa_unique_profile_availability');
            });
        }
    }

    protected function dropForeignKeys(): void
    {
        if ($this->foreignKeyExists('employment_profile_interest', 'epi_profile_fk')) {
            Schema::table('employment_profile_interest', function (Blueprint $table) {
                $table->dropForeign('epi_profile_fk');
            });
        }

        if ($this->foreignKeyExists('employment_profile_interest', 'epi_interest_fk')) {
            Schema::table('employment_profile_interest', function (Blueprint $table) {
                $table->dropForeign('epi_interest_fk');
            });
        }

        if ($this->foreignKeyExists('employment_profile_availability', 'epa_profile_fk')) {
            Schema::table('employment_profile_availability', function (Blueprint $table) {
                $table->dropForeign('epa_profile_fk');
            });
        }

        if ($this->foreignKeyExists('employment_profile_availability', 'epa_availability_fk')) {
            Schema::table('employment_profile_availability', function (Blueprint $table) {
                $table->dropForeign('epa_availability_fk');
            });
        }
    }

    protected function hasDuplicates(string $table, array $columns): bool
    {
        return DB::table($table)
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    protected function hasNulls(string $table, array $columns): bool
    {
        return DB::table($table)
            ->where(function ($query) use ($columns) {
                foreach ($columns as $column) {
                    $query->orWhereNull($column);
                }
            })
            ->exists();
    }

    protected function hasOrphans(string $table, string $foreignKey, string $parentTable): bool
    {
        $ids = DB::table($parentTable)->pluck('id');

        if ($ids->isEmpty()) {
            return DB::table($table)->exists();
        }

        return DB::table($table)
            ->whereNotIn($foreignKey, $ids)
            ->exists();
    }

    protected function uniqueExists(string $table, string $index): bool
    {
        // Schema::getIndexes() is driver-agnostic (MySQL, SQLite, Postgres),
        // unlike the previous raw information_schema query.
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $idx) => strcasecmp($idx['name'], $index) === 0);
    }

    protected function foreignKeyExists(string $table, string $constraint): bool
    {
        // SQLite reports foreign keys without names; fall back to matching on
        // the constrained columns so re-runs stay idempotent there too.
        $foreignKeys = collect(Schema::getForeignKeys($table));

        if ($foreignKeys->contains(fn (array $fk) => strcasecmp((string) ($fk['name'] ?? ''), $constraint) === 0)) {
            return true;
        }

        if (DB::getDriverName() === 'sqlite') {
            $column = match ($constraint) {
                'epi_profile_fk', 'epa_profile_fk' => 'employment_profile_id',
                'epi_interest_fk' => 'employment_interest_id',
                'epa_availability_fk' => 'employment_availability_option_id',
                default => null,
            };

            return $column !== null && $foreignKeys->contains(
                fn (array $fk) => in_array($column, $fk['columns'] ?? [], true)
            );
        }

        return false;
    }
};
