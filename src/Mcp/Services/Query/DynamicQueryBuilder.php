<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DynamicQueryBuilder
 *
 * Converts metric definition arrays into Laravel Query Builder instances.
 *
 * This class BUILDS queries — it does NOT execute them.
 * Execution is the responsibility of the caller (or DynamicQueryExecutor).
 *
 * Supported component types:
 *   - KPI       → buildKpiQuery()       → SELECT COUNT/SUM/AVG/MIN/MAX as value
 *   - Chart     → buildChartQuery()     → SELECT group_col, AGG() GROUP BY ...
 *   - Table     → buildTableQuery()     → SELECT col1, col2 ... LIMIT N
 *   - Filter    → buildFilterQuery()    → SELECT DISTINCT column
 *
 * All connection info is pulled dynamically from config/mcp-dashboard-studio.php.
 * No table names, column names, or aggregates are hardcoded.
 */
class DynamicQueryBuilder
{
    protected string $connection;

    protected int $sampleRows;

    /**
     * Allowed aggregate functions — prevents arbitrary SQL injection via
     * the 'aggregate' key in metric definitions.
     */
    private const ALLOWED_AGGREGATES = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    public function __construct()
    {
        $this->connection = config('mcp-dashboard-studio.database.connection')
            ?? config('database.default');

        $this->sampleRows = (int) config('mcp-dashboard-studio.database.discovery.sample_rows', 10);
    }

    // =========================================================================
    // KPI Query
    // =========================================================================

    /**
     * Build a KPI aggregate query from a metric definition.
     *
     * Input:
     * [
     *     'table'     => 'employees',
     *     'aggregate' => 'COUNT',
     *     'column'    => '*',
     * ]
     *
     * Output:
     * DB::table('employees')->selectRaw('COUNT(*) as value')
     *
     * @param  array  $metric  Metric definition with table, aggregate, column
     * @return Builder|null    Query builder ready for ->get() / ->first(), or null on bad input
     */
    public function buildKpiQuery(array $metric): ?Builder
    {
        $table     = $metric['table']     ?? null;
        $aggregate = strtoupper($metric['aggregate'] ?? 'COUNT');
        $column    = $metric['column']    ?? '*';

        if (! $table || ! $this->isValidTable($table) || ! $this->isAllowedAggregate($aggregate) || ! $this->isValidColumn($table, $column)) {
            Log::warning('DynamicQueryBuilder: Invalid KPI metric request', compact('table', 'aggregate', 'column'));
            return null;
        }

        $query = DB::connection($this->connection)->table($table);

        $aggExpr = $this->buildAggregateExpression($aggregate, $column);

        return $query->selectRaw("{$aggExpr} as value");
    }

    // =========================================================================
    // Chart Queries
    // =========================================================================

    /**
     * Build a chart query — dispatches to the correct sub-builder based on chart type.
     *
     * @param  array  $metric  Chart definition with type, table, columns, aggregate, etc.
     * @return Builder|null
     */
    public function buildChartQuery(array $metric): ?Builder
    {
        $chartType = $metric['type'] ?? 'bar';

        return match ($chartType) {
            'line'          => $this->buildTimeSeriesQuery($metric),
            'pie', 'donut'  => $this->buildDistributionQuery($metric),
            'bar'           => $this->buildBarQuery($metric),
            default         => $this->buildBarQuery($metric),
        };
    }

    /**
     * Build a time-series query grouped by a date column.
     */
    public function buildTimeSeriesQuery(array $metric): ?Builder
    {
        $table       = $metric['table']       ?? null;
        $xColumn     = $metric['x_column']    ?? 'created_at';
        $granularity = $metric['x_group_by']  ?? 'month';
        $yAggregate  = strtoupper($metric['y_aggregate'] ?? 'COUNT');
        $yColumn     = $metric['y_column']    ?? '*';
        $limit       = $this->sanitizeLimit($metric['limit'] ?? 24);

        if (! $table || ! $this->isValidTable($table) || ! $this->isAllowedAggregate($yAggregate)
            || ! $this->isValidColumn($table, $xColumn) || ! $this->isValidColumn($table, $yColumn)) {
            Log::warning('DynamicQueryBuilder: Invalid TimeSeries metric request', compact('table', 'xColumn', 'yColumn'));
            return null;
        }

        $periodExpr = $this->buildDateTruncExpression($xColumn, $granularity);
        $aggExpr    = $this->buildAggregateExpression($yAggregate, $yColumn);

        return DB::connection($this->connection)
            ->table($table)
            ->selectRaw("{$periodExpr} as period, {$aggExpr} as value")
            ->whereNotNull($xColumn)
            ->groupBy('period')
            ->orderBy('period')
            ->limit($limit);
    }

    /**
     * Build a distribution query (pie/donut) grouped by a category column.
     */
    public function buildDistributionQuery(array $metric): ?Builder
    {
        $table     = $metric['table']     ?? null;
        $groupCol  = $metric['group_by']  ?? null;
        $aggregate = strtoupper($metric['aggregate'] ?? 'COUNT');
        $limit     = $this->sanitizeLimit($metric['limit'] ?? 20);

        if (! $table || ! $groupCol || ! $this->isValidTable($table) || ! $this->isValidColumn($table, $groupCol) || ! $this->isAllowedAggregate($aggregate)) {
            Log::warning('DynamicQueryBuilder: Invalid Distribution metric request', compact('table', 'groupCol'));
            return null;
        }

        $aggExpr = $this->buildAggregateExpression($aggregate, '*');

        $driver = DB::connection($this->connection)->getDriverName();
        $quotedGroup = match ($driver) {
            'pgsql', 'sqlite' => "\"{$groupCol}\"",
            'sqlsrv' => "[{$groupCol}]",
            default => "`{$groupCol}`",
        };

        return DB::connection($this->connection)
            ->table($table)
            ->selectRaw("{$quotedGroup} as label, {$aggExpr} as value")
            ->whereNotNull($groupCol)
            ->groupBy($groupCol)
            ->orderByDesc('value')
            ->limit($limit);
    }

    /**
     * Build a bar chart query — aggregate on a numeric column.
     */
    public function buildBarQuery(array $metric): ?Builder
    {
        $table      = $metric['table']      ?? null;
        $yColumn    = $metric['y_column']    ?? null;
        $yAggregate = strtoupper($metric['y_aggregate'] ?? 'SUM');

        if (! $table || ! $yColumn || ! $this->isValidTable($table) || ! $this->isValidColumn($table, $yColumn) || ! $this->isAllowedAggregate($yAggregate)) {
            Log::warning('DynamicQueryBuilder: Invalid Bar metric request', compact('table', 'yColumn'));
            return null;
        }

        $aggExpr = $this->buildAggregateExpression($yAggregate, $yColumn);

        return DB::connection($this->connection)
            ->table($table)
            ->selectRaw("{$aggExpr} as value");
    }

    // =========================================================================
    // Data Table Query
    // =========================================================================

    /**
     * Build a data-table query with selected columns, sort, and limit.
     */
    public function buildTableQuery(array $metric, ?int $limit = null): ?Builder
    {
        $table    = $metric['table']         ?? null;
        $columns  = $metric['columns']       ?? [];
        $sortCol  = $metric['default_sort']  ?? null;
        $sortDir  = $metric['default_order'] ?? 'desc';
        $finalLimit = $this->sanitizeLimit($limit ?? $metric['limit'] ?? null);

        if (! $table || ! $this->isValidTable($table)) {
            Log::warning('DynamicQueryBuilder: Invalid Table query request', compact('table'));
            return null;
        }

        // Validate all columns
        foreach ($columns as $col) {
            if (! $this->isValidColumn($table, $col)) {
                Log::warning('DynamicQueryBuilder: Invalid column requested in table query', compact('table', 'col'));
                return null;
            }
        }

        // Validate sort column
        if ($sortCol && ! $this->isValidColumn($table, $sortCol)) {
            Log::warning('DynamicQueryBuilder: Invalid sort column requested', compact('table', 'sortCol'));
            return null;
        }

        $query = DB::connection($this->connection)->table($table);

        if (! empty($columns)) {
            $query->select($columns);
        }

        if ($sortCol) {
            // Ensure sortDir is safe
            $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';
            $query->orderBy($sortCol, $sortDir);
        }

        return $query->limit($finalLimit);
    }

    // =========================================================================
    // Filter Query
    // =========================================================================

    /**
     * Build a filter-options query — distinct values from a column.
     */
    public function buildFilterQuery(array $metric, ?int $limit = null): ?Builder
    {
        $table  = $metric['table']  ?? null;
        $column = $metric['column'] ?? null;
        $finalLimit = $this->sanitizeLimit($limit ?? 100);

        if (! $table || ! $column || ! $this->isValidTable($table) || ! $this->isValidColumn($table, $column)) {
            Log::warning('DynamicQueryBuilder: Invalid Filter query request', compact('table', 'column'));
            return null;
        }

        // Date-range filters don't need a query — options are set by UI
        if (($metric['type'] ?? '') === 'date_range') {
            return null;
        }

        return DB::connection($this->connection)
            ->table($table)
            ->select($column)
            ->distinct()
            ->whereNotNull($column)
            ->orderBy($column)
            ->limit($finalLimit);
    }

    // =========================================================================
    // Generic / Auto-Dispatch Builder
    // =========================================================================

    /**
     * Auto-detect the metric kind and build the appropriate query.
     * Useful when the caller doesn't know the component type ahead of time.
     *
     * @param  array  $metric  Any metric definition array
     * @return Builder|null
     */
    public function build(array $metric): ?Builder
    {
        $kind = $this->detectMetricKind($metric);

        Log::debug('DynamicQueryBuilder: Building query', [
            'kind'  => $kind,
            'table' => $metric['table'] ?? '—',
        ]);

        return match ($kind) {
            'kpi'    => $this->buildKpiQuery($metric),
            'chart'  => $this->buildChartQuery($metric),
            'table'  => $this->buildTableQuery($metric),
            'filter' => $this->buildFilterQuery($metric),
            default  => null,
        };
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a SQL aggregate expression from function name + column.
     * Validates the aggregate function against the allow-list.
     */
    protected function buildAggregateExpression(string $fn, string $column): string
    {
        $fn = strtoupper($fn);

        if (! $this->isAllowedAggregate($fn)) {
            $fn = 'COUNT';
        }

        if ($column === '*') {
            return "{$fn}(*)";
        }

        $driver = DB::connection($this->connection)->getDriverName();
        $quoted = match ($driver) {
            'pgsql', 'sqlite' => "\"{$column}\"",
            'sqlsrv' => "[{$column}]",
            default => "`{$column}`",
        };

        return "{$fn}({$quoted})";
    }

    /**
     * Build a driver-appropriate date truncation expression.
     */
    protected function buildDateTruncExpression(string $column, string $granularity): string
    {
        $driver = DB::connection($this->connection)->getDriverName();

        return match ($driver) {
            'pgsql' => "TO_CHAR(\"{$column}\", '" . match ($granularity) {
                'day'   => 'YYYY-MM-DD',
                'week'  => 'IYYY-IW',
                'month' => 'YYYY-MM',
                'year'  => 'YYYY',
                default => 'YYYY-MM',
            } . "')",

            'sqlite' => "strftime('" . match ($granularity) {
                'day'   => '%Y-%m-%d',
                'week'  => '%Y-%W',
                'month' => '%Y-%m',
                'year'  => '%Y',
                default => '%Y-%m',
            } . "', \"{$column}\")",

            'sqlsrv' => match ($granularity) {
                'day'   => "CONVERT(VARCHAR(10), [{$column}], 120)",
                'week'  => "CONCAT(CONVERT(VARCHAR(4), DATEPART(YEAR, [{$column}])), '-', FORMAT(DATEPART(WEEK, [{$column}]), '00'))",
                'month' => "CONVERT(VARCHAR(7), [{$column}], 120)",
                'year'  => "CONVERT(VARCHAR(4), [{$column}], 120)",
                default => "CONVERT(VARCHAR(7), [{$column}], 120)",
            },

            default => "DATE_FORMAT(`{$column}`, '" . match ($granularity) {
                'day'   => '%Y-%m-%d',
                'week'  => '%Y-%u',
                'month' => '%Y-%m',
                'year'  => '%Y',
                default => '%Y-%m',
            } . "')",
        };
    }

    /**
     * Check whether an aggregate function name is in the allow-list.
     */
    protected function isAllowedAggregate(string $fn): bool
    {
        return in_array(strtoupper($fn), self::ALLOWED_AGGREGATES, true);
    }

    /**
     * Detect metric kind from its array shape — no hardcoded type field required.
     */
    protected function detectMetricKind(array $metric): string
    {
        if (isset($metric['type']) && in_array($metric['type'], ['line', 'bar', 'pie', 'donut'], true)) {
            return 'chart';
        }

        if (isset($metric['type']) && in_array($metric['type'], ['select', 'date_range'], true)) {
            return 'filter';
        }

        if (isset($metric['columns']) && is_array($metric['columns']) && ! isset($metric['aggregate'])) {
            return 'table';
        }

        if (isset($metric['aggregate'])) {
            return 'kpi';
        }

        return 'kpi';
    }

    /**
     * Validate table access against whitelists and exclusions.
     */
    protected function isValidTable(string $table): bool
    {
        $lowerTable = strtolower($table);

        // Filter excluded tables
        $excludedTables = array_map('strtolower', config('mcp-dashboard-studio.database.discovery.excluded_tables', []));
        if (in_array($lowerTable, $excludedTables, true)) {
            return false;
        }

        // Filter whitelisted tables if non-empty
        $whitelistedTables = array_map('strtolower', config('mcp-dashboard-studio.database.discovery.whitelisted_tables', []));
        if (! empty($whitelistedTables) && ! in_array($lowerTable, $whitelistedTables, true)) {
            return false;
        }

        // Check exists
        try {
            return \Illuminate\Support\Facades\Schema::connection($this->connection)->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Validate column access and format to prevent SQL injection.
     */
    protected function isValidColumn(string $table, string $column): bool
    {
        if ($column === '*') {
            return true;
        }

        // Strictly match alphanumeric + underscores only
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }

        // Verify column exists in table
        try {
            return \Illuminate\Support\Facades\Schema::connection($this->connection)->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Enforce guardrail query limits.
     */
    protected function sanitizeLimit(?int $limit): int
    {
        $maxLimit = (int) config('mcp-dashboard-studio.database.discovery.max_query_limit', 100);
        $defaultLimit = (int) config('mcp-dashboard-studio.database.discovery.sample_rows', 10);

        if ($limit === null || $limit <= 0) {
            return $defaultLimit;
        }

        return min($limit, $maxLimit);
    }
}
