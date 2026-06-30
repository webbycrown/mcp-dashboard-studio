<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RelationshipDetector
 *
 * Detects relationships between tables using two complementary strategies:
 *
 *  1. Explicit FK Strategy  — reads actual FOREIGN KEY constraints discovered
 *     by DatabaseSchemaExplorer::discoverForeignKeys(). 100% reliable.
 *
 *  2. Convention Strategy   — scans every column for the `{something}_id` pattern,
 *     then resolves the referenced table by trying:
 *       a) exact match      (customer   → customer)
 *       b) plural match     (customer   → customers)
 *       c) singular match   (customers  → customer)
 *     No hardcoded column or table names — the full list of known tables is
 *     passed in at runtime.
 *
 * Output format (from detect()):
 * [
 *     [
 *         'from' => 'orders.customer_id',
 *         'to'   => 'customers.id',
 *         'type' => 'foreign_key',   // or 'convention'
 *     ],
 * ]
 */
class RelationshipDetector
{
    /**
     * Main entry point.
     *
     * @param  array  $schema  Full output of DatabaseSchemaExplorer::explore()
     *                         Shape: ['table' => ['columns'=>[], 'indexes'=>[], 'foreign_keys'=>[]]]
     * @return array[]         Deduplicated list of detected relationships
     */
    public function detect(array $schema): array
    {
        $fromFk         = $this->detectFromForeignKeys($schema);
        $fromConvention = $this->detectFromNamingConventions($schema);

        $all = array_merge($fromFk, $fromConvention);

        // Deduplicate by the "from → to" pair (FKs take priority)
        $deduplicated = $this->deduplicate($all);

        Log::info('RelationshipDetector: Detection complete', [
            'fk_detected'         => count($fromFk),
            'convention_detected' => count($fromConvention),
            'total_unique'        => count($deduplicated),
        ]);

        return $deduplicated;
    }

    // -------------------------------------------------------------------------
    // Strategy 1 — Explicit Foreign Key Constraints
    // -------------------------------------------------------------------------

    /**
     * Extract relationships from declared FOREIGN KEY constraints.
     * Uses the 'foreign_keys' array produced by DatabaseSchemaExplorer for each table.
     *
     * @param  array  $schema  Full explorer output
     * @return array[]
     */
    public function detectFromForeignKeys(array $schema): array
    {
        $relationships = [];

        foreach ($schema as $tableName => $tableData) {
            foreach ($tableData['foreign_keys'] ?? [] as $fk) {
                // A FK may span multiple columns — create one entry per column pair
                $fromColumns = (array) ($fk['columns']         ?? []);
                $toColumns   = (array) ($fk['foreign_columns'] ?? []);
                $toTable     = $fk['foreign_table'] ?? null;

                if (! $toTable || empty($fromColumns) || empty($toColumns)) {
                    continue;
                }

                foreach ($fromColumns as $i => $fromCol) {
                    $toCol = $toColumns[$i] ?? 'id';

                    $relationships[] = [
                        'from' => "{$tableName}.{$fromCol}",
                        'to'   => "{$toTable}.{$toCol}",
                        'type' => 'foreign_key',
                    ];
                }
            }
        }

        return $relationships;
    }

    // -------------------------------------------------------------------------
    // Strategy 2 — Naming Convention (`*_id` pattern)
    // -------------------------------------------------------------------------

    /**
     * Detect implied relationships by scanning columns for `{name}_id` patterns,
     * then resolving the referenced table from the live table list.
     *
     * No table or column name is hardcoded — detection is driven entirely by
     * what exists in the schema at runtime.
     *
     * @param  array  $schema  Full explorer output
     * @return array[]
     */
    public function detectFromNamingConventions(array $schema): array
    {
        $knownTables   = array_keys($schema);
        $relationships = [];

        foreach ($schema as $tableName => $tableData) {
            foreach ($tableData['columns'] ?? [] as $column) {
                $colName = $column['name'] ?? '';

                // Only process columns that end with `_id`
                if (! Str::endsWith($colName, '_id')) {
                    continue;
                }

                // Derive the referenced entity name by stripping `_id`
                $entity = Str::beforeLast($colName, '_id'); // e.g. "customer_id" → "customer"

                if (empty($entity)) {
                    continue;
                }

                Log::info('Checking convention relation', [
                    'table' => $tableName,
                    'column' => $colName,
                    'entity' => $entity,
                    'known_tables' => $knownTables,
                ]);
                $referencedTable = $this->resolveTable($entity, $knownTables);
                $currentTable = $this->normalizeTableName($tableName);

                if ($referencedTable === $currentTable) {
                    continue;
                }
                Log::info('Convention resolution result', [
                    'column' => $colName,
                    'resolved_table' => $referencedTable,
                ]);

                if ($referencedTable === null) {
                    Log::debug('RelationshipDetector: No matching table for convention column', [
                        'table'  => $tableName,
                        'column' => $colName,
                        'entity' => $entity,
                    ]);
                    continue;
                }

                $relationships[] = [
                    'from' => "{$tableName}.{$colName}",
                    'to'   => "{$referencedTable}.id",
                    'type' => 'convention',
                ];
            }
        }

        return $relationships;
    }

    // -------------------------------------------------------------------------
    // Resolution helpers
    // -------------------------------------------------------------------------

    /**
     * Attempt to resolve an entity name to an actual table name using three
     * strategies in order of specificity:
     *   1. Exact match          e.g. entity="customer"  → table "customer"
     *   2. Simple plural         e.g. entity="customer"  → table "customers"
     *   3. Naive singular        e.g. entity="customers" → table "customer"  (strips trailing s)
     *
     * Returns null if no match is found among the known tables.
     *
     * @param  string    $entity      Stripped entity name, e.g. "customer", "product"
     * @param  string[]  $knownTables All table names currently in the database
     */
    protected function resolveTable(string $entity, array $knownTables): ?string
    {
        $candidates = [
            $entity,               // exact:    "customer"
            $entity . 's',         // plural:   "customers"
            $entity . 'es',        // plural:   "statuses" → "status" + "es"
            rtrim($entity, 's'),   // singular: "customers" → "customer"
        ];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $knownTables, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Deduplicate relationships by their `from → to` key.
     * When the same pair appears as both `foreign_key` and `convention`,
     * the `foreign_key` entry is kept (it appears first after merge).
     *
     * @param  array[]  $relationships
     * @return array[]
     */
    protected function deduplicate(array $relationships): array
    {
        $seen   = [];
        $unique = [];

        foreach ($relationships as $rel) {
            $key = $rel['from'] . '→' . $rel['to'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[]   = $rel;
        }

        return $unique;
    }

    /**
     * Remove database/schema prefix from table name.
     *
     * Examples:
     * laravel_hrms.users => users
     * users => users
     */
    protected function normalizeTableName(string $table): string
    {
        if (str_contains($table, '.')) {
            return explode('.', $table)[1];
        }

        return $table;
    }
}
