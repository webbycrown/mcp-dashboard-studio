<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SchemaCache
 *
 * Wraps DatabaseSchemaExplorer with Laravel's Cache layer to avoid
 * scanning the database schema on every request.
 *
 * All cache settings are driven by config/mcp-dashboard-studio.php:
 *   - database.schema.cache_ttl       : seconds to cache (default: 3600 = 1 hour)
 *   - database.schema.cache_prefix    : key prefix (default: 'mcp_schema')
 *   - database.connection             : which DB connection to cache for
 *
 * Cached items:
 *   - Full schema (explore())
 *   - Table listing (discoverTables())
 *   - Relationship detection results
 *   - Schema analysis profile
 */
class SchemaCache
{
    protected int $ttl;

    protected string $prefix;

    public function __construct(
        protected DatabaseSchemaExplorer $explorer,
        protected ?RelationshipDetector $relationshipDetector = null,
    ) {
        $this->ttl    = (int) config('mcp-dashboard-studio.database.schema.cache_ttl', 3600);
        $this->prefix = config('mcp-dashboard-studio.database.schema.cache_prefix', 'mcp_schema');
    }

    // =========================================================================
    // Schema Cache
    // =========================================================================

    /**
     * Get the full explored schema — cached.
     *
     * @return array  Full output of DatabaseSchemaExplorer::explore()
     */
    public function getSchema(): array
    {
        return Cache::remember(
            $this->key('schema'),
            $this->ttl,
            fn () => $this->explorer->explore()
        );
    }

    /**
     * Get the table listing — cached.
     *
     * @return list<string>
     */
    public function getTables(): array
    {
        return Cache::remember(
            $this->key('tables'),
            $this->ttl,
            fn () => $this->explorer->discoverTables()
        );
    }

    /**
     * Get columns for a specific table — cached.
     *
     * @param  string  $table
     * @return list<array>
     */
    public function getColumns(string $table): array
    {
        return Cache::remember(
            $this->key("columns_{$table}"),
            $this->ttl,
            fn () => $this->explorer->discoverColumns($table)
        );
    }

    /**
     * Get indexes for a specific table — cached.
     *
     * @param  string  $table
     * @return list<array>
     */
    public function getIndexes(string $table): array
    {
        return Cache::remember(
            $this->key("indexes_{$table}"),
            $this->ttl,
            fn () => $this->explorer->discoverIndexes($table)
        );
    }

    /**
     * Get foreign keys for a specific table — cached.
     *
     * @param  string  $table
     * @return list<array>
     */
    public function getForeignKeys(string $table): array
    {
        return Cache::remember(
            $this->key("fk_{$table}"),
            $this->ttl,
            fn () => $this->explorer->discoverForeignKeys($table)
        );
    }

    // =========================================================================
    // Relationship Cache
    // =========================================================================

    /**
     * Get detected relationships — cached.
     * Requires RelationshipDetector to be injected.
     *
     * @return array[]
     */
    public function getRelationships(): array
    {
        if (! $this->relationshipDetector) {
            return [];
        }

        return Cache::remember(
            $this->key('relationships'),
            $this->ttl,
            fn () => $this->relationshipDetector->detect($this->getSchema())
        );
    }

    // =========================================================================
    // Cache Management
    // =========================================================================

    /**
     * Flush all cached schema data.
     * Call this when the database structure changes (e.g. after migrations).
     */
    public function flush(): void
    {
        // Flush known static keys
        Cache::forget($this->key('schema'));
        Cache::forget($this->key('tables'));
        Cache::forget($this->key('relationships'));

        // Flush per-table keys (requires table listing which may be cached)
        try {
            $tables = $this->explorer->discoverTables();
            foreach ($tables as $table) {
                Cache::forget($this->key("columns_{$table}"));
                Cache::forget($this->key("indexes_{$table}"));
                Cache::forget($this->key("fk_{$table}"));
            }
        } catch (\Throwable $e) {
            Log::warning('SchemaCache: Could not flush per-table cache', [
                'error' => $e->getMessage(),
            ]);
        }

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('SchemaCache: Cache flushed');
        }
    }

    /**
     * Check if the schema cache is currently warm.
     */
    public function isWarm(): bool
    {
        return Cache::has($this->key('schema'));
    }

    /**
     * Warm the cache by pre-loading all schema data.
     * Useful to call in a scheduler or artisan command.
     */
    public function warm(): void
    {
        $this->getSchema();
        $this->getTables();
        $this->getRelationships();

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('SchemaCache: Cache warmed');
        }
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Return the configured TTL in seconds.
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a prefixed cache key.
     * Includes connection + database name to prevent collisions.
     */
    protected function key(string $suffix): string
    {
        $connection = $this->explorer->getConnection();
        $dbName = config("database.connections.{$connection}.database", '');

        return "{$this->prefix}:{$connection}:{$dbName}:{$suffix}";
    }
}
