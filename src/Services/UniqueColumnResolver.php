<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Services;

use Illuminate\Support\Facades\Schema;

/**
 * Discovers the single-column unique indexes on a table so the deletion
 * worker can null them once the grace period elapses. Two modes:
 *
 *   - "auto"  (default) → introspect indexes via Schema::getIndexes()
 *   - array          → use the host-supplied list verbatim
 *
 * Results are cached per-request to avoid repeated schema reads.
 */
class UniqueColumnResolver
{
    /** @var array<string, array<int, string>> */
    private array $cache = [];

    /**
     * @return array<int, string>
     */
    public function resolve(string $table = 'users'): array
    {
        if (isset($this->cache[$table])) {
            return $this->cache[$table];
        }

        $configured = config('auth_system.account.deletion.unique_columns', 'auto');
        $exclude    = array_map('strval', (array) config('auth_system.account.deletion.unique_exclude', ['id']));

        if (is_array($configured)) {
            $columns = array_values(array_diff(array_map('strval', $configured), $exclude));
            return $this->cache[$table] = $columns;
        }

        // "auto" mode — introspect via the schema builder. getIndexes() returns
        // every index on the table, including the primary key. We want only
        // single-column non-primary unique indexes.
        $columns = [];

        try {
            $indexes = Schema::getIndexes($table);
        } catch (\Throwable) {
            return $this->cache[$table] = [];
        }

        foreach ($indexes as $index) {
            $isUnique  = (bool) ($index['unique'] ?? false);
            $isPrimary = (bool) ($index['primary'] ?? false);
            $cols      = (array) ($index['columns'] ?? []);

            if (! $isUnique || $isPrimary || count($cols) !== 1) {
                continue;
            }

            $column = (string) $cols[0];

            if (in_array($column, $exclude, true) || in_array($column, $columns, true)) {
                continue;
            }

            $columns[] = $column;
        }

        return $this->cache[$table] = $columns;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
