<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * DatabaseSchemaExplorer
 *
 * Dynamically explores a database connection and returns a structured schema map.
 *
 * Configuration is read from config/mcp-dashboard-studio.php:
 *   - database.connection   : named connection from config/database.php (null = app default)
 *   - database.discovery.max_tables  : upper limit of tables to process
 *   - database.discovery.sample_rows : reserved for callers that want row previews
 *
 * Example output of explore():
 * [
 *   'orders' => [
 *       'columns'      => [...],
 *       'indexes'      => [...],
 *       'foreign_keys' => [...],
 *   ],
 *   'users' => [...],
 * ]
 */
class DatabaseSchemaExplorer
{
    protected string $connection;

    protected int $maxTables;

    protected int $sampleRows;

    public function __construct()
    {
        // Pull connection name from MCP config; fall back to the app's default connection
        $this->connection = config('mcp-dashboard-studio.database.connection')
            ?? config('database.default');

        // Discovery limits – driven entirely by config
        $this->maxTables  = (int) config('mcp-dashboard-studio.database.discovery.max_tables', 100);
        $this->sampleRows = (int) config('mcp-dashboard-studio.database.discovery.sample_rows', 10);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Explore the full database schema and return a unified map.
     *
     * @return array<string, array{
     *     columns: list<array>,
     *     indexes: list<array>,
     *     foreign_keys: list<array>
     * }>
     */
    public function explore(): array
    {
        $tables = $this->discoverTables();
        $schema = [];

        foreach ($tables as $table) {
            try {
                $schema[$table] = [
                    'columns'      => $this->discoverColumns($table),
                    'indexes'      => $this->discoverIndexes($table),
                    'foreign_keys' => $this->discoverForeignKeys($table),
                ];
            } catch (\Throwable $e) {
                // Skip tables that can't be introspected and log the issue
                Log::warning('DatabaseSchemaExplorer: Skipping table due to error', [
                    'table'     => $table,
                    'error'     => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }
        }

        Log::info('DatabaseSchemaExplorer: Schema exploration complete', [
            'connection'   => $this->connection,
            'total_tables' => count($schema),
        ]);

        return $schema;
    }

    /**
     * Discover all user-defined table names in the connected database.
     *
     * Respects config('mcp-dashboard-studio.database.discovery.max_tables').
     *
     * @return list<string>
     */
    public function discoverTables(): array
    {
        $schema = DB::connection($this->connection)->getDatabaseName();

        $tables = Schema::connection($this->connection)
            ->getTableListing($schema);

        // Normalize tables to exclude schema prefixes
        $tables = array_map(function ($table) {
            if (str_contains($table, '.')) {
                return explode('.', $table)[1];
            }
            return $table;
        }, $tables);

        // Filter out framework and sensitive/excluded tables
        $tables = array_filter($tables, function ($table) {
            return !$this->isFrameworkTable($table) && !$this->isExcludedTable($table);
        });

        // Apply whitelisted tables if configured
        $whitelistedTables = array_map('strtolower', config('mcp-dashboard-studio.database.discovery.whitelisted_tables', []));
        if (!empty($whitelistedTables)) {
            $tables = array_filter($tables, function ($table) use ($whitelistedTables) {
                return in_array(strtolower($table), $whitelistedTables, true);
            });
        }

        Log::debug('DatabaseSchemaExplorer: Tables discovered', [
            'connection' => $this->connection,
            'count'      => count($tables),
            'tables'     => array_values($tables),
        ]);

        return array_values($tables);
    }

    protected function isExcludedTable(string $table): bool
    {
        $lower = strtolower($table);
        $excludedTables = array_map('strtolower', config('mcp-dashboard-studio.database.discovery.excluded_tables', []));

        return in_array($lower, $excludedTables, true);
    }

    protected function isFrameworkTable(string $table): bool
    {
        if (str_contains($table, '.')) {
            $table = explode('.', $table)[1];
        }

        $lower = strtolower($table);
        $frameworkTables = array_map('strtolower', config('mcp-dashboard-studio.schema_analysis.framework_tables', []));

        if (in_array($lower, $frameworkTables, true)) {
            return true;
        }

        foreach (['telescope_', 'horizon_', 'pulse_', 'nova_'] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Discover all columns for a given table.
     *
     * Each entry contains:
     *   name, type, type_name, nullable, default, auto_increment, comment
     *
     * @param  string  $table
     * @return list<array{
     *     name: string,
     *     type: string,
     *     type_name: string,
     *     nullable: bool,
     *     default: mixed,
     *     auto_increment: bool,
     *     comment: string|null
     * }>
     */
    public function discoverColumns(string $table): array
    {
        $raw = Schema::connection($this->connection)->getColumns($table);

        return array_map(function (array $col): array {
            return [
                'name'          => $col['name'],
                'type'          => $col['type'],          // full type e.g. "varchar(255)"
                'type_name'     => $col['type_name'],     // base type e.g. "varchar"
                'nullable'      => (bool) $col['nullable'],
                'default'       => $col['default'],
                'auto_increment' => (bool) ($col['auto_increment'] ?? false),
                'comment'       => $col['comment'] ?? null,
            ];
        }, $raw);
    }

    /**
     * Discover all indexes on a given table.
     *
     * Each entry contains:
     *   name, columns, unique, primary
     *
     * @param  string  $table
     * @return list<array{
     *     name: string,
     *     columns: list<string>,
     *     unique: bool,
     *     primary: bool
     * }>
     */
    public function discoverIndexes(string $table): array
    {
        $raw = Schema::connection($this->connection)->getIndexes($table);

        return array_map(function (array $index): array {
            return [
                'name'    => $index['name'],
                'columns' => $index['columns'],
                'unique'  => (bool) $index['unique'],
                'primary' => (bool) $index['primary'],
            ];
        }, $raw);
    }

    /**
     * Discover all foreign key constraints on a given table.
     *
     * Each entry contains:
     *   name, columns, foreign_table, foreign_columns, on_update, on_delete
     *
     * @param  string  $table
     * @return list<array{
     *     name: string,
     *     columns: list<string>,
     *     foreign_table: string,
     *     foreign_columns: list<string>,
     *     on_update: string,
     *     on_delete: string
     * }>
     */
    public function discoverForeignKeys(string $table): array
    {
        $raw = Schema::connection($this->connection)->getForeignKeys($table);

        return array_map(function (array $fk): array {
            return [
                'name'            => $fk['name'],
                'columns'         => $fk['columns'],
                'foreign_table'   => $fk['foreign_table'],
                'foreign_columns' => $fk['foreign_columns'],
                'on_update'       => $fk['on_update'] ?? 'NO ACTION',
                'on_delete'       => $fk['on_delete'] ?? 'NO ACTION',
            ];
        }, $raw);
    }

    // -------------------------------------------------------------------------
    // Accessors (useful for callers that need metadata without re-reading config)
    // -------------------------------------------------------------------------

    /**
     * Return the active DB connection name being used by this explorer.
     */
    public function getConnection(): string
    {
        return $this->connection;
    }

    /**
     * Return the configured max-table limit.
     */
    public function getMaxTables(): int
    {
        return $this->maxTables;
    }

    /**
     * Return the configured sample-rows value (for callers that fetch previews).
     */
    public function getSampleRows(): int
    {
        return $this->sampleRows;
    }
}
