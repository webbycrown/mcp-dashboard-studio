<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database;

use Illuminate\Support\Str;

/**
 * SchemaAnalyzer
 *
 * Analyzes database schema structure without domain classification.
 * Produces a schema profile that classifies tables by structural role:
 *
 *   - Primary entities:    standalone data tables (users, products, employees)
 *   - Transaction tables:  record events/actions with FKs (orders, attendance)
 *   - Lookup tables:       small reference tables (categories, statuses)
 *   - Framework tables:    Laravel/package infrastructure (filtered out)
 *
 * This service is the primary schema intelligence source for the
 * dashboard generation pipeline.
 */
class SchemaAnalyzer
{
    /**
     * Tables that are part of the Laravel framework or common packages.
     */
    protected array $frameworkTables = [
        'users', 'password_resets', 'password_reset_tokens',
        'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs',
        'migrations', 'personal_access_tokens', 'notifications',
        'roles', 'permissions', 'model_has_roles',
        'model_has_permissions', 'role_has_permissions',
        'oauth_access_tokens', 'oauth_auth_codes', 'oauth_clients',
        'oauth_personal_access_clients', 'oauth_refresh_tokens',
        'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring',
        'pulse_aggregates', 'pulse_entries', 'pulse_values',
        'settings', 'company_settings', 'media', 'activity_log',
        'websockets_statistics_entries',
        'mcp_dashboard_definitions',
    ];

    public function __construct()
    {
        $extra = config('mcp-dashboard-studio.schema_analysis.framework_tables', []);
        if (! empty($extra)) {
            $this->frameworkTables = array_unique(array_merge($this->frameworkTables, $extra));
        }
    }

    /**
     * Analyze the full schema and return a structural profile.
     *
     * @param  array  $schema  Full output of DatabaseSchemaExplorer::explore()
     * @return array{
     *   primary_entities: string[],
     *   transaction_tables: string[],
     *   lookup_tables: string[],
     *   framework_tables: string[],
     *   all_business_tables: string[],
     *   table_profiles: array<string, array>,
     * }
     */
    public function analyze(array $schema): array
    {
        $allTables     = array_keys($schema);
        $framework     = [];
        $entities      = [];
        $transactions  = [];
        $lookups       = [];
        $tableProfiles = [];

        foreach ($allTables as $table) {
            if ($this->isFrameworkTable($table)) {
                $framework[] = $table;
                continue;
            }

            $definition = $schema[$table] ?? [];
            $columns    = $definition['columns'] ?? [];
            $foreignKeys = $definition['foreign_keys'] ?? [];
            $colNames   = array_map(fn($c) => $c['name'] ?? '', $columns);

            $fkCount = count($foreignKeys) + $this->countConventionFks($colNames, $allTables);
            $colCount = count($columns);

            $profile = [
                'table'       => $table,
                'columns'     => count($columns),
                'fk_count'    => $fkCount,
                'has_timestamps' => in_array('created_at', $colNames) && in_array('updated_at', $colNames),
                'role'        => 'entity', // default
            ];

            // Classification logic
            if ($this->isLookupTable($table, $colCount, $fkCount)) {
                $profile['role'] = 'lookup';
                $lookups[] = $table;
            } elseif ($this->isTransactionTable($table, $colNames, $fkCount, $colCount)) {
                $profile['role'] = 'transaction';
                $transactions[] = $table;
            } else {
                $profile['role'] = 'entity';
                $entities[] = $table;
            }

            $tableProfiles[$table] = $profile;
        }

        return [
            'primary_entities'    => $entities,
            'transaction_tables'  => $transactions,
            'lookup_tables'       => $lookups,
            'framework_tables'    => $framework,
            'all_business_tables' => array_merge($entities, $transactions, $lookups),
            'table_profiles'      => $tableProfiles,
        ];
    }

    /**
     * Get only business tables (framework tables filtered out).
     */
    public function getBusinessTables(array $schema): array
    {
        return array_values(array_filter(
            array_keys($schema),
            fn($t) => ! $this->isFrameworkTable($t)
        ));
    }

    // =========================================================================
    // Classification Rules
    // =========================================================================

    protected function isFrameworkTable(string $table): bool
    {
        if (str_contains($table, '.')) {
            $table = explode('.', $table)[1];
        }

        $lower = strtolower($table);
        if (in_array($lower, $this->frameworkTables, true)) {
            return true;
        }
        // Wildcard prefixes
        foreach (['telescope_', 'horizon_', 'pulse_', 'nova_'] as $prefix) {
            if (str_starts_with($lower, $prefix)) return true;
        }
        return false;
    }

    protected function isLookupTable(string $table, int $colCount, int $fkCount): bool
    {
        // Lookup tables: few columns, no FKs, naming patterns
        if ($colCount <= 4 && $fkCount === 0) return true;

        $lookupPatterns = ['categories', 'statuses', 'types', 'levels', 'priorities', 'tags'];
        foreach ($lookupPatterns as $pattern) {
            if (str_contains(strtolower($table), $pattern)) return true;
        }

        return false;
    }

    protected function isTransactionTable(string $table, array $colNames, int $fkCount, int $colCount): bool
    {
        // Transaction tables: many FKs, timestamp columns, transaction naming
        if ($fkCount >= 2) return true;

        $transactionPatterns = [
            '_items', '_details', '_logs', '_entries', '_records',
            '_history', '_movements', '_transactions', '_payments',
        ];

        $lower = strtolower($table);
        foreach ($transactionPatterns as $suffix) {
            if (str_ends_with($lower, $suffix)) return true;
        }

        // Tables with transaction-like columns
        $txColumns = ['amount', 'total', 'quantity', 'invoice_number', 'transaction_id'];
        $txMatch = count(array_intersect(array_map('strtolower', $colNames), $txColumns));
        if ($txMatch >= 2) return true;

        return false;
    }

    protected function countConventionFks(array $colNames, array $allTables): int
    {
        $count = 0;
        $tableSet = array_flip(array_map('strtolower', $allTables));

        foreach ($colNames as $col) {
            if (str_ends_with($col, '_id') && $col !== 'id') {
                $ref = str_replace('_id', '', $col);
                if (isset($tableSet[$ref]) || isset($tableSet[Str::plural($ref)])) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
