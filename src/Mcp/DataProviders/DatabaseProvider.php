<?php

namespace Webbycrown\McpDashboardStudio\DataProviders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DatabaseProvider
 *
 * Implements DataProviderInterface by running live, parameterized queries
 * against the configured MCP database connection.
 *
 * Responsibilities:
 *   count()    — COUNT(*) or COUNT(column) per table
 *   sum()      — SUM(column) per table
 *   avg()      — AVG(column) per table
 *   groupBy()  — grouped aggregates for charts (time-series, distribution)
 *
 * All connection settings, sample limits, and driver-specific behaviour are
 * read dynamically from config/mcp-dashboard-studio.php — nothing is hardcoded.
 *
 * Error handling: individual query failures add an '_error' key to the
 * component and continue — one broken metric never crashes the dashboard.
 */
class DatabaseProvider implements DataProviderInterface
{
    protected string $connection;

    protected int $sampleRows;

    /** Cached table listing for this request lifecycle. */
    protected ?array $existingTables = null;

    public function __construct()
    {
        $this->connection = config('mcp-dashboard-studio.database.connection')
            ?? config('database.default');

        $this->sampleRows = (int) config('mcp-dashboard-studio.database.discovery.sample_rows', 10);
    }

    // =========================================================================
    // DataProviderInterface
    // =========================================================================

    /**
     * {@inheritDoc}
     *
     * Dispatches to the correct resolver based on the component's type hint:
     *   - aggregate / column / table → KPI
     *   - type = line / bar / pie    → Chart
     *   - columns / rows             → Data Table
     *   - type = select / date_range → Filter
     */
    public function resolve(array $component): array
    {
        // Determine component kind dynamically from its shape
        $kind = $this->detectComponentKind($component);

        return match ($kind) {
            'kpi'    => $this->resolveKpi($component),
            'chart'  => $this->resolveChart($component),
            'table'  => $this->resolveDataTable($component),
            'filter' => $this->resolveFilter($component),
            default  => $this->resolveKpi($component), // safe fallback
        };
    }

    // =========================================================================
    // Primitive Aggregate Methods
    // =========================================================================

    /**
     * Run a COUNT query on a table.
     *
     * @param  string  $table   Table name
     * @param  string  $column  Column name or '*'
     * @return int
     */
    public function count(string $table, string $column = '*'): int
    {
        return (int) DB::connection($this->connection)
            ->table($table)
            ->count($column === '*' ? '*' : $column);
    }

    /**
     * Run a SUM query on a table column.
     *
     * @param  string  $table   Table name
     * @param  string  $column  Numeric column name
     * @return float
     */
    public function sum(string $table, string $column): float
    {
        return (float) DB::connection($this->connection)
            ->table($table)
            ->sum($column);
    }

    /**
     * Run an AVG query on a table column.
     *
     * @param  string  $table   Table name
     * @param  string  $column  Numeric column name
     * @return float
     */
    public function avg(string $table, string $column): float
    {
        return (float) DB::connection($this->connection)
            ->table($table)
            ->avg($column);
    }

    /**
     * Run a MIN query on a table column.
     *
     * @param  string  $table   Table name
     * @param  string  $column  Column name
     * @return mixed
     */
    public function min(string $table, string $column): mixed
    {
        return DB::connection($this->connection)
            ->table($table)
            ->min($column);
    }

    /**
     * Run a MAX query on a table column.
     *
     * @param  string  $table   Table name
     * @param  string  $column  Column name
     * @return mixed
     */
    public function max(string $table, string $column): mixed
    {
        return DB::connection($this->connection)
            ->table($table)
            ->max($column);
    }

    /**
     * Run a GROUP BY aggregate query.
     *
     * @param  string  $table       Table name
     * @param  string  $groupColumn Column to group by
     * @param  string  $aggFn       Aggregate function: COUNT, SUM, AVG, MIN, MAX
     * @param  string  $aggColumn   Column for the aggregate (or '*' for COUNT)
     * @param  int     $limit       Max groups returned
     * @param  string  $order       Sort direction for the aggregate value: asc | desc
     * @return array{labels: list<string>, values: list<int|float>}
     */
    public function groupBy(
        string $table,
        string $groupColumn,
        string $aggFn   = 'COUNT',
        string $aggColumn = '*',
        int    $limit   = 20,
        string $order   = 'desc',
    ): array {
        $aggExpr = $this->buildAggregateExpression($aggFn, $aggColumn);

        $rows = DB::connection($this->connection)
            ->table($table)
            ->selectRaw("{$groupColumn} as label, {$aggExpr} as value")
            ->whereNotNull($groupColumn)
            ->groupBy($groupColumn)
            ->orderBy('value', $order)
            ->limit($limit)
            ->get();

        return [
            'labels' => $rows->pluck('label')->toArray(),
            'values' => $rows->pluck('value')->map(fn ($v) => is_numeric($v) ? $v + 0 : $v)->toArray(),
        ];
    }

    /**
     * Run a time-grouped aggregate query for time-series charts.
     *
     * @param  string  $table       Table name
     * @param  string  $dateColumn  Date/timestamp column to group by
     * @param  string  $aggFn       Aggregate function
     * @param  string  $aggColumn   Column for the aggregate
     * @param  string  $granularity Grouping: day | week | month | year
     * @param  int     $limit       Max periods returned
     * @return array{labels: list<string>, values: list<int|float>}
     */
    public function groupByDate(
        string $table,
        string $dateColumn,
        string $aggFn      = 'COUNT',
        string $aggColumn  = '*',
        string $granularity = 'month',
        int    $limit      = 24,
    ): array {
        $aggExpr    = $this->buildAggregateExpression($aggFn, $aggColumn);
        $periodExpr = $this->buildDateTruncExpression($dateColumn, $granularity);

        $rows = DB::connection($this->connection)
            ->table($table)
            ->selectRaw("{$periodExpr} as period, {$aggExpr} as value")
            ->whereNotNull($dateColumn)
            ->groupBy('period')
            ->orderBy('period')
            ->limit($limit)
            ->get();

        return [
            'labels' => $rows->pluck('period')->toArray(),
            'values' => $rows->pluck('value')->map(fn ($v) => is_numeric($v) ? $v + 0 : $v)->toArray(),
        ];
    }

    /**
     * Fetch distinct values from a column (for filter option lists).
     *
     * @param  string  $table   Table name
     * @param  string  $column  Column name
     * @param  int     $limit   Max values returned
     * @return list<mixed>
     */
    public function distinct(string $table, string $column, int $limit = 100): array
    {
        return DB::connection($this->connection)
            ->table($table)
            ->select($column)
            ->distinct()
            ->whereNotNull($column)
            ->orderBy($column)
            ->limit($limit)
            ->pluck($column)
            ->toArray();
    }

    /**
     * Fetch sample rows from a table.
     *
     * @param  string       $table    Table name
     * @param  list<string> $columns  Columns to select (empty = all)
     * @param  string|null  $sortCol  Column to sort by
     * @param  string       $sortDir  asc | desc
     * @param  int|null     $limit    Override the config sample_rows limit
     * @return array{headers: list<string>, rows: list<array>}
     */
    public function sampleRows(
        string  $table,
        array   $columns = [],
        ?string $sortCol = null,
        string  $sortDir = 'desc',
        ?int    $limit   = null,
    ): array {
        $query = DB::connection($this->connection)->table($table);

        if (! empty($columns)) {
            $query->select($columns);
        }

        if ($sortCol) {
            $query->orderBy($sortCol, $sortDir);
        }

        $rows = $query
            ->limit($limit ?? $this->sampleRows)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();

        return [
            'headers' => ! empty($rows) ? array_keys($rows[0]) : $columns,
            'rows'    => $rows,
        ];
    }

    // =========================================================================
    // Component Resolvers (dispatch from resolve())
    // =========================================================================

    /**
     * Resolve a KPI component by executing its aggregate query.
     */
    protected function resolveKpi(array $component): array
    {
        $table     = $component['table']     ?? null;
        $aggregate = strtoupper($component['aggregate'] ?? 'COUNT');
        $column    = $component['column']    ?? '*';

        if (! $table || ! $this->tableExists($table)) {
            return array_merge($component, ['value' => null, '_error' => "Table '{$table}' not found."]);
        }

        try {
            $value = match ($aggregate) {
                'COUNT' => $this->count($table, $column),
                'SUM'   => $this->sum($table, $column),
                'AVG'   => $this->avg($table, $column),
                'MIN'   => $this->min($table, $column),
                'MAX'   => $this->max($table, $column),
                default => $this->count($table, $column),
            };

            // Apply formatting hint
            $value = $this->formatValue($value, $component['format'] ?? 'number');

            return array_merge($component, ['value' => $value]);

        } catch (\Throwable $e) {
            Log::warning('DatabaseProvider: KPI resolve failed', [
                'title' => $component['title'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return array_merge($component, ['value' => null, '_error' => $e->getMessage()]);
        }
    }

    /**
     * Resolve a chart component by dispatching to the correct grouped query.
     */
    protected function resolveChart(array $component): array
    {
        $table = $component['table'] ?? null;

        if (! $table || ! $this->tableExists($table)) {
            return array_merge($component, ['data' => null, '_error' => "Table '{$table}' not found."]);
        }

        try {
            $data = match ($component['type'] ?? 'bar') {
                'line'          => $this->resolveLineChart($component),
                'pie', 'donut' => $this->resolvePieChart($component),
                'bar'           => $this->resolveBarChart($component),
                default         => $this->resolveBarChart($component),
            };

            return array_merge($component, ['data' => $data]);

        } catch (\Throwable $e) {
            Log::warning('DatabaseProvider: Chart resolve failed', [
                'title' => $component['title'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return array_merge($component, ['data' => null, '_error' => $e->getMessage()]);
        }
    }

    /**
     * Resolve a data-table component to headers + rows.
     */
    protected function resolveDataTable(array $component): array
    {
        $table = $component['table'] ?? null;

        if (! $table || ! $this->tableExists($table)) {
            return array_merge($component, ['headers' => [], 'rows' => [], '_error' => "Table '{$table}' not found."]);
        }

        try {
            $result = $this->sampleRows(
                table  : $table,
                columns: $component['columns']       ?? [],
                sortCol: $component['default_sort']   ?? null,
                sortDir: $component['default_order']  ?? 'desc',
            );

            return array_merge($component, $result);

        } catch (\Throwable $e) {
            Log::warning('DatabaseProvider: Table resolve failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return array_merge($component, ['headers' => [], 'rows' => [], '_error' => $e->getMessage()]);
        }
    }

    /**
     * Resolve a filter component — populates options for 'select' type.
     */
    protected function resolveFilter(array $component): array
    {
        // Date-range filters need no DB resolution
        if (($component['type'] ?? '') !== 'select') {
            return $component;
        }

        $table  = $component['table']  ?? null;
        $column = $component['column'] ?? null;

        if (! $table || ! $column || ! $this->tableExists($table)) {
            return $component;
        }

        try {
            $options = $this->distinct($table, $column);

            return array_merge($component, ['options' => $options]);

        } catch (\Throwable $e) {
            Log::warning('DatabaseProvider: Filter resolve failed', [
                'filter' => $component['title'] ?? '',
                'error'  => $e->getMessage(),
            ]);

            return array_merge($component, ['options' => [], '_error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // Chart Sub-Resolvers
    // =========================================================================

    /**
     * Line chart: time-series grouped by a date column.
     */
    protected function resolveLineChart(array $chart): array
    {
        $result = $this->groupByDate(
            table      : $chart['table'],
            dateColumn : $chart['x_column']   ?? 'created_at',
            aggFn      : $chart['y_aggregate'] ?? 'COUNT',
            aggColumn  : $chart['y_column']    ?? '*',
            granularity: $chart['x_group_by']  ?? 'month',
        );

        return [
            'labels'   => $result['labels'],
            'datasets' => [[
                'label' => $chart['title'] ?? $chart['table'],
                'data'  => $result['values'],
            ]],
        ];
    }

    /**
     * Pie/donut chart: distribution by a category column.
     */
    protected function resolvePieChart(array $chart): array
    {
        $groupCol = $chart['group_by'] ?? null;

        if (! $groupCol) {
            return ['labels' => [], 'datasets' => [['data' => []]]];
        }

        $result = $this->groupBy(
            table      : $chart['table'],
            groupColumn: $groupCol,
            aggFn      : $chart['aggregate'] ?? 'COUNT',
            aggColumn  : '*',
        );

        return [
            'labels'   => $result['labels'],
            'datasets' => [[
                'label' => $chart['title'] ?? $groupCol,
                'data'  => $result['values'],
            ]],
        ];
    }

    /**
     * Bar chart: aggregate on a numeric column (single bar or grouped).
     */
    protected function resolveBarChart(array $chart): array
    {
        $yColumn = $chart['y_column'] ?? null;
        $groupCol = $chart['group_by'] ?? $chart['x_column'] ?? null;

        // If a grouping column is provided, return multiple bars
        if ($groupCol) {
            $result = $this->groupBy(
                table      : $chart['table'],
                groupColumn: $groupCol,
                aggFn      : $chart['y_aggregate'] ?? 'SUM',
                aggColumn  : $yColumn ?? '*',
            );

            return [
                'labels'   => $result['labels'],
                'datasets' => [[
                    'label' => $chart['title'] ?? $yColumn ?? 'Value',
                    'data'  => $result['values'],
                ]],
            ];
        }

        // Fallback to single bar if no grouping is defined
        if (! $yColumn) {
            return ['labels' => [], 'datasets' => []];
        }

        $aggFn = strtoupper($chart['y_aggregate'] ?? 'SUM');
        $value = match ($aggFn) {
            'SUM' => $this->sum($chart['table'], $yColumn),
            'AVG' => $this->avg($chart['table'], $yColumn),
            'MIN' => $this->min($chart['table'], $yColumn),
            'MAX' => $this->max($chart['table'], $yColumn),
            default => $this->sum($chart['table'], $yColumn),
        };

        return [
            'labels'   => [$chart['title'] ?? $yColumn],
            'datasets' => [[
                'label' => $chart['title'] ?? $yColumn,
                'data'  => [(float) $value],
            ]],
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Detect what kind of component an array represents, by inspecting its keys.
     * No hardcoded type field required — shape-driven detection.
     */
    protected function detectComponentKind(array $component): string
    {
        // Charts have a chart 'type' (line, bar, pie)
        if (isset($component['type']) && in_array($component['type'], ['line', 'bar', 'pie', 'donut'], true)) {
            return 'chart';
        }

        // Filters have a filter 'type' (select, date_range)
        if (isset($component['type']) && in_array($component['type'], ['select', 'date_range'], true)) {
            return 'filter';
        }

        // Data tables have a 'columns' array (list of display columns)
        if (isset($component['columns']) && is_array($component['columns']) && ! isset($component['aggregate'])) {
            return 'table';
        }

        // KPIs have an 'aggregate' key
        if (isset($component['aggregate'])) {
            return 'kpi';
        }

        return 'kpi'; // safe default
    }

    /**
     * Check whether a table exists in the configured database.
     * Cached per instance lifecycle.
     */
    protected function tableExists(string $table): bool
    {
        if ($this->existingTables === null) {
            $conn   = DB::connection($this->connection);
            $dbName = $conn->getDatabaseName();

            // Get tables for THIS database only (not all databases on the server)
            $raw = $conn->getSchemaBuilder()->getTableListing($dbName);

            // Strip schema prefix (e.g. 'laravel_hrms.add_jobs' → 'add_jobs')
            $this->existingTables = array_map(function (string $t): string {
                return str_contains($t, '.') ? explode('.', $t)[1] : $t;
            }, $raw);
        }

        return in_array($table, $this->existingTables, true);
    }

    /**
     * Build a SQL aggregate expression string.
     */
    protected function buildAggregateExpression(string $fn, string $column): string
    {
        $fn = strtoupper($fn);

        if ($fn === 'COUNT' && $column === '*') {
            return 'COUNT(*)';
        }

        $allowed = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
        $fn = in_array($fn, $allowed, true) ? $fn : 'COUNT';

        return "{$fn}(`{$column}`)";
    }

    /**
     * Build a driver-appropriate date truncation expression.
     * Detects MySQL/MariaDB, PostgreSQL, and SQLite dynamically.
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
     * Apply formatting hint to a resolved scalar value.
     * Actual currency symbols / locale formatting are left to the frontend.
     */
    protected function formatValue(mixed $value, string $format): int|float|null
    {
        if ($value === null) {
            return null;
        }

        return match ($format) {
            'currency' => round((float) $value, 2),
            default    => (int) $value == (float) $value ? (int) $value : round((float) $value, 4),
        };
    }
}
