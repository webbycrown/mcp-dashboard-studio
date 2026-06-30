<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardSpec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DataSourceResolver
 *
 * The bridge between metric candidates (schema-derived, value=null) and
 * real data (live DB queries, value=1542).
 *
 * Takes the full output of MetricDiscoveryService::discover() and resolves
 * every candidate's value/data by executing safe, parameterized DB queries
 * against the configured MCP database connection.
 *
 * Also provides hydrate() which takes a DashboardSpec and resolves all
 * components in-place — used by DashboardGenerator in the pipeline.
 *
 * Errors on individual metrics are caught and surfaced via `_error` key
 * so the rest of the dashboard is never blocked by one bad query.
 */
class DataSourceResolver
{
    protected string $connection;

    protected int $sampleRows;

    /** Tables verified to exist on this connection (cached per request). */
    protected ?array $existingTables = null;

    public function __construct()
    {
        $this->connection = config('mcp-dashboard-studio.database.connection')
            ?? config('database.default');

        $this->sampleRows = (int) config('mcp-dashboard-studio.database.discovery.sample_rows', 10);
    }

    // -------------------------------------------------------------------------
    // DashboardSpec Hydration (used by DashboardGenerator pipeline)
    // -------------------------------------------------------------------------

    /**
     * Hydrate a DashboardSpec by resolving all its components against the live DB.
     *
     * Reads the `data` metadata on each KPI/chart component to determine
     * what query to run. When DB mode is disabled, returns the spec unchanged.
     *
     * @param  DashboardSpec  $spec  Spec with null/placeholder values
     * @return DashboardSpec         Same spec with live data populated
     */
    public function hydrate(DashboardSpec $spec): DashboardSpec
    {
        // Skip hydration if database mode is disabled
        if (! $this->isDatabaseEnabled()) {
            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::debug('DataSourceResolver: DB mode disabled, skipping hydration');
            }
            return $spec;
        }

        // Skip hydration for fallback dashboards — they have no DB-backed components
        if (! empty($spec->meta['fallback'])) {
            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::debug('DataSourceResolver: Fallback dashboard detected, skipping hydration');
            }
            return $spec;
        }

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('DataSourceResolver: Hydrating DashboardSpec', [
                'kpis'   => count($spec->kpis),
                'charts' => count($spec->charts),
                'tables' => count($spec->tables),
            ]);
        }

        // Hydrate KPIs — resolve values from their data.table + data.metric
        $spec->kpis = array_map(function (array $kpi): array {
            $data = $kpi['data'] ?? [];

            if (($data['provider'] ?? '') !== 'database' || empty($data['table'])) {
                return $kpi;
            }

            return $this->resolveKpi([
                'table'     => $data['table'],
                'aggregate' => $data['metric'] ?? 'COUNT',
                'column'    => $data['column'] ?? '*',
                'title'     => $kpi['title'] ?? 'KPI',
                'format'    => $kpi['format'] ?? 'number',
            ] + $kpi);
        }, $spec->kpis);

        // Hydrate Charts — resolve data from their data.* fields
        $spec->charts = array_map(function (array $chart): array {
            $data = $chart['data'] ?? [];

            if (($data['provider'] ?? '') !== 'database' || empty($data['table'])) {
                return $chart;
            }

            return $this->resolveChart([
                'table'       => $data['table'],
                'type'        => $chart['chartType'] ?? 'bar',
                'group_by'    => $data['group_by'] ?? null,
                'x_column'    => $data['x_column'] ?? null,
                'x_group_by'  => $data['x_group_by'] ?? null,
                'y_column'    => $data['y_column'] ?? null,
                'y_aggregate' => $data['y_aggregate'] ?? 'COUNT',
                'aggregate'   => $data['aggregate'] ?? 'COUNT',
                'title'       => $chart['title'] ?? 'Chart',
            ] + $chart);
        }, $spec->charts);

        // Hydrate Tables — resolve sample rows
        $spec->tables = $this->resolveTables($spec->tables);

        // Hydrate Filters — resolve distinct options
        $spec->filters = $this->resolveFilters($spec->filters);

        return $spec;
    }

    /**
     * Check if database mode is enabled.
     */
    protected function isDatabaseEnabled(): bool
    {
        $dataMode  = config('mcp-dashboard-studio.data_mode', 'schema');
        $dbEnabled = config('mcp-dashboard-studio.database.enabled', false);

        return $dbEnabled || in_array($dataMode, ['database', 'hybrid'], true);
    }

    // -------------------------------------------------------------------------
    // Main Entry Point
    // -------------------------------------------------------------------------

    /**
     * Resolve all candidate groups at once.
     * Accepts the direct output of MetricDiscoveryService::discover().
     *
     * @param  array{kpis: list<array>, charts: list<array>, tables: list<array>, filters: list<array>}  $candidates
     * @return array{kpis: list<array>, charts: list<array>, tables: list<array>, filters: list<array>}
     */
    public function resolveAll(array $candidates): array
    {
        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('DataSourceResolver: Starting full resolution', [
                'connection' => $this->connection,
                'kpis'       => count($candidates['kpis']   ?? []),
                'charts'     => count($candidates['charts']  ?? []),
                'tables'     => count($candidates['tables']  ?? []),
            ]);
        }

        return array_merge($candidates, [
            'kpis'    => $this->resolveKpis($candidates['kpis']     ?? []),
            'charts'  => $this->resolveCharts($candidates['charts']  ?? []),
            'tables'  => $this->resolveTables($candidates['tables']  ?? []),
            'filters' => $this->resolveFilters($candidates['filters'] ?? []),
        ]);
    }

    // -------------------------------------------------------------------------
    // KPI Resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve a list of KPI candidates by running their aggregate queries.
     *
     * Supported aggregates: COUNT, SUM, AVG, MIN, MAX
     *
     * @param  list<array>  $kpis
     * @return list<array>
     */
    public function resolveKpis(array $kpis): array
    {
        return array_map(fn(array $kpi) => $this->resolveKpi($kpi), $kpis);
    }

    /**
     * Resolve a single KPI candidate to a concrete scalar value.
     */
    public function resolveKpi(array $kpi): array
    {
        $data      = $kpi['data'] ?? [];
        $table     = $kpi['table']     ?? $data['table']     ?? null;
        $aggregate = strtoupper($kpi['aggregate'] ?? $data['aggregate'] ?? $data['metric'] ?? 'COUNT');
        $column    = $kpi['column']    ?? $data['column']    ?? '*';

        if (! $table || ! $this->tableExists($table)) {
            return array_merge($kpi, ['value' => null, '_error' => "Table '{$table}' not found."]);
        }

        try {
            $value = $this->runAggregate($table, $aggregate, $column);
            $value = $this->formatScalar($value, $kpi['format'] ?? 'number');

            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::debug('DataSourceResolver: KPI resolved', [
                    'title' => $kpi['title'] ?? '',
                    'value' => $value,
                ]);
            }

            return array_merge($kpi, ['value' => $value]);
        } catch (\Throwable $e) {
            Log::warning('DataSourceResolver: KPI query failed', [
                'kpi'   => $kpi['title'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return array_merge($kpi, ['value' => null, '_error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Chart Resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve a list of chart candidates to chart-ready data arrays.
     *
     * @param  list<array>  $charts
     * @return list<array>
     */
    public function resolveCharts(array $charts): array
    {
        return array_map(fn(array $chart) => $this->resolveChart($chart), $charts);
    }

    /**
     * Resolve a single chart candidate.
     * Dispatches to the correct strategy based on chart 'type'.
     */
    public function resolveChart(array $chart): array
    {
        $specData = $chart['data'] ?? [];
        $table    = $chart['table'] ?? $specData['table'] ?? null;
        $groupBy  = $chart['group_by'] ?? $specData['group_by'] ?? null;

        // Ensure table and group_by are at top level for downstream resolution methods
        $chart['table']    = $table;
        $chart['group_by'] = $groupBy;

        if (! $table || ! $this->tableExists($table)) {
            return array_merge($chart, ['data' => null, '_error' => "Table '{$table}' not found."]);
        }

        try {
            $data = match (true) {
                // Charts with group_by always use distribution (grouped aggregation)
                !empty($groupBy)                                 => $this->resolveDistribution($chart),
                ($chart['type'] ?? $chart['chartType'] ?? 'bar') === 'line' => $this->resolveTimeSeries($chart),
                in_array($chart['type'] ?? $chart['chartType'] ?? '', ['pie', 'donut', 'doughnut'], true) => $this->resolveDistribution($chart),
                default                                         => $this->resolveBarData($chart),
            };

            return array_merge($chart, ['data' => $data]);
        } catch (\Throwable $e) {
            Log::warning('DataSourceResolver: Chart query failed', [
                'chart' => $chart['title'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return array_merge($chart, ['data' => null, '_error' => $e->getMessage()]);
        }
    }

    /**
     * Resolve a time-series line chart: groups rows by a date column per month.
     *
     * Output: { labels: ['Jan 2024', ...], datasets: [{ label: '...', data: [120, ...] }] }
     */
    protected function resolveTimeSeries(array $chart): array
    {
        $table    = $chart['table'];
        $xColumn  = $chart['x_column']    ?? 'created_at';
        $yAgg     = strtoupper($chart['y_aggregate'] ?? 'COUNT');
        $yColumn  = $chart['y_column']    ?? '*';
        $groupBy  = $chart['x_group_by']  ?? 'month';

        // Build the date format dynamically based on grouping granularity
        $dateFormat = match ($groupBy) {
            'day'   => '%Y-%m-%d',
            'week'  => '%Y-%u',
            'month' => '%Y-%m',
            'year'  => '%Y',
            default => '%Y-%m',
        };

        $yExpr  = $yColumn === '*' ? 'COUNT(*)' : "{$yAgg}(`{$yColumn}`)";
        $driver = $this->getDriverName();

        // Build date truncation expression per driver
        $periodExpr = match ($driver) {
            'pgsql'  => "TO_CHAR(\"{$xColumn}\", '" . $this->pgDateFormat($groupBy) . "')",
            'sqlite' => "strftime('" . $this->sqliteDateFormat($groupBy) . "', \"{$xColumn}\")",
            default  => "DATE_FORMAT(`{$xColumn}`, '{$dateFormat}')",  // MySQL / MariaDB
        };

        $rows = DB::connection($this->connection)
            ->table($table)
            ->selectRaw("{$periodExpr} as period, {$yExpr} as value")
            ->whereNotNull($xColumn)
            ->groupBy('period')
            ->orderBy('period')
            ->limit(24)   // up to 24 periods
            ->get();

        return [
            'labels'   => $rows->pluck('period')->toArray(),
            'datasets' => [[
                'label' => $chart['title'] ?? $table,
                'data'  => $rows->pluck('value')->map(fn($v) => (float) $v)->toArray(),
            ]],
        ];
    }

    /**
     * Resolve a distribution (pie/donut/grouped-bar) chart: groups by a category column.
     *
     * Supports custom aggregates via the 'aggregate' key:
     *   - COUNT (default): COUNT(*) per group
     *   - SUM:  SUM(y_column) per group
     *   - AVG:  AVG(y_column) per group
     *   - MIN / MAX: MIN/MAX(y_column) per group
     *
     * Output: { labels: ['active', 'inactive', ...], datasets: [{ data: [80, 20] }] }
     */
    protected function resolveDistribution(array $chart): array
    {
        $table     = $chart['table'];
        $groupBy   = $chart['group_by'] ?? null;
        $aggregate = strtoupper($chart['aggregate'] ?? $chart['y_aggregate'] ?? 'COUNT');
        $yColumn   = $chart['y_column'] ?? '*';

        if (! $groupBy) {
            return ['labels' => [], 'datasets' => [['data' => []]]];
        }

        // Build the aggregate expression
        $aggExpr = match ($aggregate) {
            'SUM'   => "SUM(`{$yColumn}`)",
            'AVG'   => "AVG(`{$yColumn}`)",
            'MIN'   => "MIN(`{$yColumn}`)",
            'MAX'   => "MAX(`{$yColumn}`)",
            default => 'COUNT(*)',  // COUNT is the default
        };

        $rows = DB::connection($this->connection)
            ->table($table)
            ->selectRaw("`{$groupBy}` as label, {$aggExpr} as value")
            ->whereNotNull($groupBy)
            ->groupBy($groupBy)
            ->orderByDesc('value')
            ->limit(20)
            ->get();

        return [
            'labels'   => $rows->pluck('label')->toArray(),
            'datasets' => [[
                'label' => $chart['title'] ?? $groupBy,
                'data'  => $rows->pluck('value')->map(fn($v) => round((float) $v, 2))->toArray(),
            ]],
        ];
    }

    /**
     * Resolve a bar chart: SUM of a numeric column (single value, not grouped).
     *
     * Output: { labels: ['Total'], datasets: [{ label: '...', data: [98432.50] }] }
     */
    protected function resolveBarData(array $chart): array
    {
        $table    = $chart['table'];
        $yAgg     = strtoupper($chart['y_aggregate'] ?? 'SUM');
        $yColumn  = $chart['y_column']  ?? null;

        if (! $yColumn) {
            return ['labels' => [], 'datasets' => []];
        }

        $value = $this->runAggregate($table, $yAgg, $yColumn);

        return [
            'labels'   => [$chart['title'] ?? $yColumn],
            'datasets' => [[
                'label' => $chart['title'] ?? $yColumn,
                'data'  => [(float) $value],
            ]],
        ];
    }

    // -------------------------------------------------------------------------
    // Data Table Resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve a list of table candidates to rows + headers.
     *
     * @param  list<array>  $tables
     * @return list<array>
     */
    public function resolveTables(array $tables): array
    {
        return array_map(fn(array $table) => $this->resolveTable($table), $tables);
    }

    /**
     * Resolve a single data table candidate.
     *
     * Output adds:
     *   headers : list of column names
     *   rows    : array of row arrays (limited to sample_rows)
     */
    public function resolveTable(array $table): array
    {
        $data        = $table['data']           ?? [];
        $tableName   = $data['table']           ?? $table['table']         ?? null;
        $columns     = $table['columns']        ?? [];
        $sortCol     = $data['sort']            ?? $table['default_sort']  ?? null;
        $sortOrder   = $data['order']           ?? $table['default_order'] ?? 'desc';

        if (! $tableName || ! $this->tableExists($tableName)) {
            return array_merge($table, ['headers' => [], 'rows' => [], '_error' => "Table '{$tableName}' not found."]);
        }

        try {
            $query = DB::connection($this->connection)->table($tableName);
            $selectFields = [];

            // ── Schema-agnostic relationship joins ──────────────────────
            // Detect virtual columns that reference related tables via FK
            // e.g. column 'brand' on 'products' → auto-join 'brands' table
            $relationships = $this->detectRelationships($tableName);

            foreach ($columns as $idx => $col) {
                $colLower = strtolower($col);

                // Check if this column matches a FK-based relationship
                $fkColumn = $colLower . '_id';
                $matched = false;

                foreach ($relationships as $rel) {
                    $fromParts = explode('.', $rel['from']);
                    $toParts   = explode('.', $rel['to']);

                    if (count($fromParts) !== 2 || count($toParts) !== 2) continue;

                    $fromTable  = $fromParts[0];
                    $fromCol    = $fromParts[1];
                    $toTable    = $toParts[0];
                    $toCol      = $toParts[1];

                    // Match: column 'brand' → FK 'brand_id' → join 'brands'
                    if ($fromTable === $tableName && $fromCol === $fkColumn) {
                        $query->leftJoin($toTable, "{$tableName}.{$fromCol}", '=', "{$toTable}.{$toCol}");
                        $selectFields[] = "{$toTable}.name as {$colLower}";
                        unset($columns[$idx]);
                        $matched = true;
                        break;
                    }
                }

                // Check for computed count columns (e.g. 'product_count' on 'brands')
                if (! $matched && str_ends_with($colLower, '_count')) {
                    $entityName = str_replace('_count', '', $colLower);
                    $relatedTable = $this->resolveRelatedTable($entityName);

                    if ($relatedTable) {
                        // Find the FK column on the related table that references this table
                        $fkOnRelated = \Illuminate\Support\Str::singular($tableName) . '_id';
                        if ($this->columnExists($relatedTable, $fkOnRelated)) {
                            $query->leftJoin($relatedTable, "{$relatedTable}.{$fkOnRelated}", '=', "{$tableName}.id");
                            $query->groupBy("{$tableName}.id");
                            $selectFields[] = DB::raw("COUNT({$relatedTable}.id) as {$colLower}");
                            unset($columns[$idx]);

                            // If sorting by this computed column, flag it
                            if ($sortCol === $colLower) {
                                $sortCol = '__computed_count__';
                            }
                        }
                    }
                }
            }
            $columns = array_values($columns);

            // Select only specified columns if provided, otherwise all (*)
            if (! empty($columns)) {
                // Validate columns against actual DB schema to prevent SQL errors
                $actualColumns = $this->getTableColumns($tableName);
                $validColumns = array_filter($columns, fn($c) => in_array($c, $actualColumns, true));

                if (empty($validColumns)) {
                    // All requested columns are invalid — fall back to first 8 actual columns
                    $validColumns = array_slice($actualColumns, 0, 8);

                    if (config('mcp-dashboard-studio.logging_enabled', false)) {
                        Log::warning('DataSourceResolver: All requested columns invalid, using fallback', [
                            'table'     => $tableName,
                            'requested' => $columns,
                            'fallback'  => $validColumns,
                        ]);
                    }
                }

                foreach ($validColumns as $c) {
                    $selectFields[] = $tableName . '.' . $c;
                }

                // Update columns to only valid ones for headers
                $columns = $validColumns;
            } else {
                $selectFields[] = $tableName . '.*';
            }

            $query->select($selectFields);

            if ($sortCol) {
                if ($sortCol === '__computed_count__') {
                    // Sort by the computed COUNT column added during relationship join
                    $query->orderByRaw('2 ' . ($sortOrder === 'asc' ? 'ASC' : 'DESC'));
                } else {
                    $prefixedSort = str_contains($sortCol, '.') ? $sortCol : ($tableName . '.' . $sortCol);
                    $query->orderBy($prefixedSort, $sortOrder);
                }
            }

            $limit = $data['limit'] ?? $table['limit'] ?? $this->sampleRows;
            $rows = $query->limit($limit)->get()->toArray();
            $rows = array_map(fn($row) => (array) $row, $rows);

            $headers = [];
            if (! empty($rows)) {
                $headers = array_keys($rows[0]);
            } else {
                $headers = $table['columns'] ?? [];
            }

            return array_merge($table, [
                'headers' => $headers,
                'rows'    => $rows,
            ]);
        } catch (\Throwable $e) {
            Log::warning('DataSourceResolver: Table query failed', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);

            return array_merge($table, ['headers' => [], 'rows' => [], '_error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Filter Resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve filter candidates — populate select filters with distinct option values.
     * Date-range filters have no DB resolution (their range is chosen by the user).
     *
     * Handles both raw MetricDiscoveryService format (type='select', top-level table/column)
     * AND FilterGenerator format (type='filter', control='select', data.table/data.column).
     *
     * @param  list<array>  $filters
     * @return list<array>
     */
    public function resolveFilters(array $filters): array
    {
        return array_map(function (array $filter): array {
            // Detect filter kind — FilterGenerator uses 'control', MetricDiscovery uses 'type'
            $filterKind = $filter['control']
                ?? $filter['data']['control'] ?? $filter['data']['type']
                ?? $filter['filterType'] ?? $filter['type'] ?? '';

            // Skip date_range filters — user picks the range, no DB resolution needed
            if ($filterKind === 'date_range') {
                return $filter;
            }

            // Only resolve 'select' filters (or component type 'filter' with select control)
            if ($filterKind !== 'select' && ($filter['type'] ?? '') !== 'filter') {
                return $filter;
            }

            // Read table/column — FilterGenerator nests inside 'data', MetricDiscovery uses top-level
            $table  = $filter['data']['table']  ?? $filter['table']  ?? null;
            $column = $filter['data']['column'] ?? $filter['column'] ?? $filter['field'] ?? null;

            if (! $table || ! $column || ! $this->tableExists($table)) {
                return $filter;
            }

            try {
                $options = DB::connection($this->connection)
                    ->table($table)
                    ->select($column)
                    ->distinct()
                    ->whereNotNull($column)
                    ->where($column, '!=', '')
                    ->orderBy($column)
                    ->limit(100)
                    ->pluck($column)
                    ->toArray();

                // Populate options at both top-level and inside data (for storage compatibility)
                $filter['options'] = $options;
                if (isset($filter['data']) && is_array($filter['data'])) {
                    $filter['data']['options'] = $options;
                }

                if (config('mcp-dashboard-studio.logging_enabled', false)) {
                    Log::debug('DataSourceResolver: Filter options resolved', [
                        'filter' => $filter['title'] ?? '',
                        'table'  => $table,
                        'column' => $column,
                        'count'  => count($options),
                    ]);
                }

                return $filter;
            } catch (\Throwable $e) {
                Log::warning('DataSourceResolver: Filter options query failed', [
                    'filter' => $filter['title'] ?? '',
                    'table'  => $table,
                    'column' => $column,
                    'error'  => $e->getMessage(),
                ]);

                $filter['options'] = [];
                $filter['_error'] = $e->getMessage();
                return $filter;
            }
        }, $filters);
    }

    // -------------------------------------------------------------------------
    // Aggregate Query Runner
    // -------------------------------------------------------------------------

    /**
     * Execute a single aggregate query and return the scalar result.
     * Supported: COUNT, SUM, AVG, MIN, MAX
     *
     * Uses Laravel's query builder — column names go through the builder's
     * identifier quoting, preventing SQL injection.
     *
     * @param  string  $table
     * @param  string  $aggregate  One of: COUNT, SUM, AVG, MIN, MAX
     * @param  string  $column     Column name or '*' for COUNT(*)
     * @return int|float|null
     */
    protected function runAggregate(string $table, string $aggregate, string $column): int|float|null
    {
        $query = DB::connection($this->connection)->table($table);

        return match ($aggregate) {
            'COUNT' => $query->count($column === '*' ? '*' : $column),
            'SUM'   => $query->sum($column),
            'AVG'   => $query->avg($column),
            'MIN'   => $query->min($column),
            'MAX'   => $query->max($column),
            default => $query->count(),
        };
    }

    // -------------------------------------------------------------------------
    // Table Existence Guard
    // -------------------------------------------------------------------------

    /**
     * Check whether a table actually exists in the DB connection.
     * Results are cached for the lifetime of this resolver instance
     * to avoid repeated SHOW TABLES / pg_tables queries.
     */
    protected function tableExists(string $table): bool
    {
        if ($this->existingTables === null) {
            $conn   = DB::connection($this->connection);
            $dbName = $conn->getDatabaseName();

            // Get tables for THIS database only (not all 27 databases on the server)
            $raw = $conn->getSchemaBuilder()->getTableListing($dbName);

            // Strip schema prefix (e.g. 'laravel_hrms.add_jobs' → 'add_jobs')
            // This matches exactly what DatabaseSchemaExplorer::discoverTables() does
            $this->existingTables = array_map(function (string $t): string {
                return str_contains($t, '.') ? explode('.', $t)[1] : $t;
            }, $raw);
        }

        return in_array($table, $this->existingTables, true);
    }

    // -------------------------------------------------------------------------
    // Driver-specific Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the underlying PDO driver name for the configured connection.
     */
    protected function getDriverName(): string
    {
        return DB::connection($this->connection)->getDriverName();
    }

    /**
     * PostgreSQL date format strings per grouping granularity.
     */
    protected function pgDateFormat(string $groupBy): string
    {
        return match ($groupBy) {
            'day'   => 'YYYY-MM-DD',
            'week'  => 'IYYY-IW',
            'month' => 'YYYY-MM',
            'year'  => 'YYYY',
            default => 'YYYY-MM',
        };
    }

    /**
     * SQLite strftime format strings per grouping granularity.
     */
    protected function sqliteDateFormat(string $groupBy): string
    {
        return match ($groupBy) {
            'day'   => '%Y-%m-%d',
            'week'  => '%Y-%W',
            'month' => '%Y-%m',
            'year'  => '%Y',
            default => '%Y-%m',
        };
    }

    // -------------------------------------------------------------------------
    // Value Formatter
    // -------------------------------------------------------------------------

    /**
     * Format a raw scalar value for display.
     * Keeps values as numbers internally; formatting to currency symbols
     * is left to the frontend layer.
     *
     * @param  int|float|null  $value
     * @param  string          $format  'number' | 'currency'
     * @return int|float|null
     */
    protected function formatScalar(int|float|null $value, string $format): int|float|null
    {
        if ($value === null) {
            return null;
        }

        return match ($format) {
            'currency' => round((float) $value, 2),
            default    => (int) $value === (float) $value ? (int) $value : round((float) $value, 4),
        };
    }

    // -------------------------------------------------------------------------
    // Schema-Agnostic Relationship Helpers
    // -------------------------------------------------------------------------

    /**
     * Detect relationships for a given table using the RelationshipDetector.
     * Returns cached relationships filtered to those involving the specified table.
     */
    protected function detectRelationships(string $tableName): array
    {
        try {
            $schemaCache = app(\Webbycrown\McpDashboardStudio\Mcp\Services\Database\SchemaCache::class);
            $relationships = $schemaCache->getRelationships();

            return array_filter($relationships, function ($rel) use ($tableName) {
                $from = explode('.', $rel['from'] ?? '')[0] ?? '';
                return $from === $tableName;
            });
        } catch (\Throwable $e) {
            Log::debug('DataSourceResolver: Could not detect relationships', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Resolve an entity name to an existing table name (schema-agnostic).
     * Tries: exact match, plural (s), plural (es), singular (strip s).
     */
    protected function resolveRelatedTable(string $entityName): ?string
    {
        $candidates = [
            $entityName,
            $entityName . 's',
            $entityName . 'es',
            rtrim($entityName, 's'),
        ];

        foreach ($candidates as $candidate) {
            if ($this->tableExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Check if a specific column exists on a table (schema-agnostic).
     */
    protected function columnExists(string $table, string $column): bool
    {
        try {
            $schemaCache = app(\Webbycrown\McpDashboardStudio\Mcp\Services\Database\SchemaCache::class);
            $columns = $schemaCache->getColumns($table);
            foreach ($columns as $col) {
                if (($col['name'] ?? '') === $column) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('DataSourceResolver: Column check failed', [
                'table'  => $table,
                'column' => $column,
                'error'  => $e->getMessage(),
            ]);
        }
        return false;
    }

    /**
     * Get all column names for a table from the schema cache.
     *
     * @return list<string>
     */
    protected function getTableColumns(string $table): array
    {
        try {
            $schemaCache = app(\Webbycrown\McpDashboardStudio\Mcp\Services\Database\SchemaCache::class);
            $columns = $schemaCache->getColumns($table);
            return array_column($columns, 'name');
        } catch (\Throwable $e) {
            // Fallback: query DB schema directly
            try {
                return DB::connection($this->connection)
                    ->getSchemaBuilder()
                    ->getColumnListing($table);
            } catch (\Throwable) {
                return [];
            }
        }
    }
}
