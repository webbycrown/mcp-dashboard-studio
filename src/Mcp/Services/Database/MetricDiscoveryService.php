<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Database;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webbycrown\McpDashboardStudio\Mcp\DTO\MetricDefinitionDTO;


/**
 * MetricDiscoveryService
 *
 * Inspects a live database schema and generates dashboard-ready candidates:
 *   - KPI cards   (COUNT per table + SUM/AVG on numeric columns)
 *   - Charts      (time-series on date columns, distribution on category columns)
 *   - Data tables (column preview per table)
 *   - Filters     (date ranges and select filters from category/status columns)
 *
 * Everything is driven by the actual schema — no table names, column names,
 * or metric labels are hardcoded. Detection uses column naming patterns and
 * SQL type families resolved at runtime.
 *
 * INPUT  : full output of DatabaseSchemaExplorer::explore()
 *          ['table' => ['columns' => [...], 'indexes' => [...], 'foreign_keys' => [...]]]
 *
 * OUTPUT :
 * [
 *     'kpis'    => [ ['title' => 'Total Orders',  'table' => 'orders',  ...], ... ],
 *     'charts'  => [ ['title' => 'Orders by Status', ...], ... ],
 *     'tables'  => [ ['title' => 'Orders List',   'table' => 'orders',  ...], ... ],
 *     'filters' => [ ['title' => 'Order Status',  'table' => 'orders',  ...], ... ],
 * ]
 */
class MetricDiscoveryService
{
    // -------------------------------------------------------------------------
    // Column-name signal patterns (all lowercase, matched with str_contains)
    // -------------------------------------------------------------------------

    /**
     * Column name fragments that indicate a numeric/monetary metric.
     * Columns matching these produce SUM / AVG KPI candidates.
     */
    private const NUMERIC_SIGNALS = [
        'amount',
        'total',
        'price',
        'cost',
        'revenue',
        'salary',
        'fee',
        'balance',
        'income',
        'profit',
        'quantity',
        'qty',
        'stock',
        'count',
        'weight',
        'discount',
        'tax',
        'charge',
        'rate',
        'budget',
    ];

    /**
     * Column name fragments that indicate a date/time value.
     * Columns matching these produce time-series chart and date-range filter candidates.
     */
    private const DATE_SIGNALS = [
        'created_at',
        'updated_at',
        'deleted_at',
        'date',
        'timestamp',
        'time',
        'started_at',
        'ended_at',
        'completed_at',
        'ordered_at',
        'shipped_at',
        'delivered_at',
        'registered_at',
        'joined_at',
        'hired_at',
        'born_at',
        'dob',
        'birth_date',
        'expired_at',
        'expires_at',
        'due_date',
    ];

    /**
     * Column name fragments that indicate a categorical / enum value.
     * Columns matching these produce distribution charts and select-filter candidates.
     */
    private const CATEGORY_SIGNALS = [
        'status',
        'state',
        'stage',
        'type',
        'category',
        'kind',
        'gender',
        'role',
        'department',
        'priority',
        'level',
        'grade',
        'source',
        'channel',
        'medium',
        'country',
        'region',
        'city',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Discover all dashboard candidates from the given schema.
     *
     * @param  array  $schema  Output of DatabaseSchemaExplorer::explore()
     * @return array{
     *     kpis: list<array>,
     *     charts: list<array>,
     *     tables: list<array>,
     *     filters: list<array>
     * }
     */
    public function discover(array $schema): array
    {
        $kpis    = [];
        $charts  = [];
        $tables  = [];
        $filters = [];

        foreach ($schema as $tableName => $tableData) {

            $columns = $tableData['columns'] ?? [];

            // COUNT KPI
            $kpis[] = $this->buildCountKpi($tableName);

            // Numeric KPIs and bar charts
            $numericColumns = $this->numericColumns($columns);
            foreach ($numericColumns as $col) {
                $kpis[]   = $this->buildAggregateKpi($tableName, $col, 'SUM');
                $kpis[]   = $this->buildAggregateKpi($tableName, $col, 'AVG');

                $charts[] = $this->buildBarChart($tableName, $col, $columns);
            }

            // Date columns
            $dateColumns = $this->dateColumns($columns);
            foreach ($dateColumns as $col) {
                $charts[]  = $this->buildTimeSeriesChart($tableName, $col);
                $filters[] = $this->buildDateRangeFilter($tableName, $col);
            }

            // Numeric time-series charts when both date and numeric columns exist.
            if (! empty($numericColumns) && ! empty($dateColumns)) {
                foreach ($numericColumns as $numericCol) {
                    $charts[] = $this->buildNumericTimeSeriesChart($tableName, $numericCol, $dateColumns[0]);
                }
            }

            // Category columns
            foreach ($this->categoryColumns($columns) as $col) {
                $charts[]  = $this->buildDistributionChart($tableName, $col);
                $filters[] = $this->buildSelectFilter($tableName, $col);
            }

            // Table candidate
            $tables[] = $this->buildTableCandidate($tableName, $columns);
        }

        // Convert KPI DTOs to arrays ONCE here
        $kpis = array_map(
            fn($metric) => $metric instanceof MetricDefinitionDTO
                ? $metric->toArray()
                : $metric,
            $kpis
        );

        $result = [
            'kpis'    => $kpis,
            'charts'  => $charts,
            'tables'  => $tables,
            'filters' => $filters,
        ];

        if (config('mcp-dashboard-studio.logging_enabled', false)) {
            Log::info('MetricDiscoveryService: Discovery complete', [
                'tables_scanned' => count($schema),
                'kpis'           => count($kpis),
                'charts'         => count($charts),
                'data_tables'    => count($tables),
                'filters'        => count($filters),
            ]);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // KPI Builders
    // -------------------------------------------------------------------------

    /**
     * Build a COUNT(*) KPI for the total row count of a table.
     * e.g. "orders" → "Total Orders"
     */
    protected function buildCountKpi(string $table): MetricDefinitionDTO
    {
        return new MetricDefinitionDTO(
            title: 'Total ' . $this->humanize($table),
            table: $table,
            aggregate: 'COUNT',
            column: '*',
            unit: 'count',
            format: 'number',
        );
    }

    /**
     * Build a SUM or AVG KPI on a specific numeric column.
     * e.g. table="orders", column="total_amount", fn="SUM" → "Total Amount (Orders)"
     */
    protected function buildAggregateKpi(string $table, array $col, string $fn): MetricDefinitionDTO
    {
        $label = ($fn === 'SUM' ? 'Total ' : 'Average ') . $this->humanize($col['name']);
        $unit = $this->inferUnit($col['name']);
        $format = $this->inferFormat($col['name']);

        return new MetricDefinitionDTO(
            title: $label,
            table: $table,
            aggregate: $fn,
            column: $col['name'],
            unit: $unit,
            format: $format,
        );
    }

    // -------------------------------------------------------------------------
    // Chart Builders
    // -------------------------------------------------------------------------

    /**
     * Build a time-series line chart grouped by a date column.
     * e.g. table="orders", date_col="created_at" → "Orders Over Time"
     */
    protected function buildTimeSeriesChart(string $table, array $col): array
    {
        return [
            'title'       => $this->humanize($table) . ' Over Time',
            'type'        => 'line',
            'table'       => $table,
            'x_column'    => $col['name'],
            'x_group_by'  => 'month',        // day | week | month | year
            'y_aggregate' => 'COUNT',
            'y_column'    => '*',
        ];
    }

    /**
     * Build a horizontal/vertical bar chart for a numeric column.
     * e.g. table="orders", col="total_amount" → "Total Amount per Order"
     *
     * Automatically selects a grouping column when available so the chart
     * renders multiple bars (one per group) instead of a single aggregate bar.
     */
    protected function buildBarChart(string $table, array $col, array $columns): array
    {
        $groupBy = $this->findBarChartGroupBy($columns);

        return [
            'title'       => $this->humanize($col['name']) . ' per ' . Str::singular($this->humanize($table)),
            'type'        => 'bar',
            'table'       => $table,
            'y_column'    => $col['name'],
            'y_aggregate' => 'SUM',
            'group_by'    => $groupBy,
        ];
    }

    /**
     * Find a sensible grouping column for a bar chart.
     *
     * Priority:
     *  1. Category/status/type columns (e.g. status, category, state)
     *  2. Foreign-key columns (e.g. order_id, user_id) — excluding the PK 'id'
     *
     * Returns null when no suitable grouping column exists, which preserves
     * the existing single-bar aggregate behavior.
     */
    protected function findBarChartGroupBy(array $columns): ?string
    {
        // 1) Prefer category columns — these produce the most readable grouped bars
        $categoryCols = $this->categoryColumns($columns);
        if (! empty($categoryCols)) {
            return $categoryCols[0]['name'];
        }

        // 2) Fall back to FK columns (something_id) so "per entity" charts get grouped
        foreach ($columns as $col) {
            $name = strtolower($col['name']);
            if (str_ends_with($name, '_id') && $name !== 'id') {
                return $col['name'];
            }
        }

        return null;
    }

    /**
     * Build a pie/donut distribution chart for a category column.
     * e.g. table="orders", col="status" → "Orders by Status"
     */
    protected function buildDistributionChart(string $table, array $col): array
    {
        return [
            'title'    => $this->humanize($table) . ' by ' . $this->humanize($col['name']),
            'type'     => 'pie',
            'table'    => $table,
            'group_by' => $col['name'],
            'aggregate' => 'COUNT',
        ];
    }

    protected function buildNumericTimeSeriesChart(string $table, array $numericCol, array $dateCol): array
    {
        return [
            'title'       => $this->humanize($numericCol['name']) . ' Over Time',
            'type'        => 'line',
            'table'       => $table,
            'x_column'    => $dateCol['name'],
            'x_group_by'  => 'month',
            'y_aggregate' => 'SUM',
            'y_column'    => $numericCol['name'],
        ];
    }

    // -------------------------------------------------------------------------
    // Table Candidate Builder
    // -------------------------------------------------------------------------

    /**
     * Build a data-table candidate showing the most relevant columns.
     * Picks up to 6 columns, prioritising non-id, non-timestamp columns.
     */
    protected function buildTableCandidate(string $table, array $columns): array
    {
        $selected = $this->selectDisplayColumns($columns, (int) config('mcp-dashboard-studio.database.discovery.max_columns', 8));

        return [
            'title'         => $this->humanize($table),
            'table'         => $table,
            'columns'       => $selected,
            'default_sort'  => $this->findPrimaryDateColumn($columns),
            'default_order' => 'desc',
            'paginate'      => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Filter Builders
    // -------------------------------------------------------------------------

    /**
     * Build a date-range filter from a date column.
     */
    protected function buildDateRangeFilter(string $table, array $col): array
    {
        return [
            'title'  => $this->humanize($col['name']),
            'table'  => $table,
            'column' => $col['name'],
            'type'   => 'date_range',
        ];
    }

    /**
     * Build a select/dropdown filter from a category column.
     */
    protected function buildSelectFilter(string $table, array $col): array
    {
        return [
            'title'  => $this->humanize($col['name']),
            'table'  => $table,
            'column' => $col['name'],
            'type'   => 'select',
        ];
    }

    // -------------------------------------------------------------------------
    // Column Classification Helpers
    // -------------------------------------------------------------------------

    /**
     * Return columns that look like numeric/monetary values.
     * Matches by column name signals AND by SQL type family.
     *
     * @param  array[]  $columns
     * @return array[]
     */
    protected function numericColumns(array $columns): array
    {
        return array_values(array_filter($columns, function (array $col): bool {
            $name     = strtolower($col['name']);
            $typeName = strtolower($col['type_name'] ?? $col['type'] ?? '');

            // Skip primary keys and FK columns — not interesting as metrics
            if ($col['auto_increment'] ?? false) {
                return false;
            }
            if (str_ends_with($name, '_id') || $name === 'id') {
                return false;
            }

            // Match by SQL type family
            $numericTypes = [
                'int',
                'integer',
                'bigint',
                'smallint',
                'tinyint',
                'decimal',
                'numeric',
                'float',
                'double',
                'real',
                'money'
            ];

            foreach ($numericTypes as $type) {
                if (str_contains($typeName, $type)) {
                    return true;
                }
            }

            // Match by column name signal
            foreach (self::NUMERIC_SIGNALS as $signal) {
                if (str_contains($name, $signal)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Return columns that look like date/timestamp values.
     *
     * @param  array[]  $columns
     * @return array[]
     */
    protected function dateColumns(array $columns): array
    {
        return array_values(array_filter($columns, function (array $col): bool {
            $name     = strtolower($col['name']);
            $typeName = strtolower($col['type_name'] ?? $col['type'] ?? '');

            // Match by SQL type
            $dateTypes = ['date', 'datetime', 'timestamp', 'time', 'year'];
            foreach ($dateTypes as $type) {
                if (str_contains($typeName, $type)) {
                    return true;
                }
            }

            // Match by column name signal
            foreach (self::DATE_SIGNALS as $signal) {
                if (str_contains($name, $signal)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Return columns that look like categorical/enum values.
     * Excludes columns already flagged as date columns.
     *
     * @param  array[]  $columns
     * @return array[]
     */
    protected function categoryColumns(array $columns): array
    {
        $dateColNames = array_column($this->dateColumns($columns), 'name');

        return array_values(array_filter($columns, function (array $col) use ($dateColNames): bool {
            $name     = strtolower($col['name']);
            $typeName = strtolower($col['type_name'] ?? $col['type'] ?? '');

            // Exclude date columns, PKs, and FK columns
            if (in_array($col['name'], $dateColNames, true)) {
                return false;
            }
            if (str_ends_with($name, '_id') || $name === 'id') {
                return false;
            }

            // Match string/enum types that hold categories
            $catTypes = ['enum', 'set', 'char', 'varchar', 'tinyint'];
            foreach ($catTypes as $type) {
                if (str_contains($typeName, $type)) {
                    // Further narrow by name signal
                    foreach (self::CATEGORY_SIGNALS as $signal) {
                        if (str_contains($name, $signal)) {
                            return true;
                        }
                    }
                }
            }

            // Match purely by name signal regardless of type
            foreach (self::CATEGORY_SIGNALS as $signal) {
                if (str_contains($name, $signal)) {
                    return true;
                }
            }

            return false;
        }));
    }

    // -------------------------------------------------------------------------
    // Display Column Selector
    // -------------------------------------------------------------------------

    /**
     * Pick the most human-meaningful columns for display in a data table.
     * Order preference: name/title columns → category columns → numeric columns → PKs last.
     *
     * @param  array[]  $columns
     * @param  int      $limit    Max number of columns to return
     * @return list<string>       Ordered column names
     */
    protected function selectDisplayColumns(array $columns, int $limit = 6): array
    {
        $priority = [];

        foreach ($columns as $col) {
            $name = strtolower($col['name']);

            if (in_array($name, ['id', 'uuid'], true)) {
                $priority[$col['name']] = 0;
            } elseif (str_contains($name, 'name') || str_contains($name, 'title')) {
                $priority[$col['name']] = 4;
            } elseif ($this->isCategoryColumn($col)) {
                $priority[$col['name']] = 3;
            } elseif ($this->isNumericColumn($col)) {
                $priority[$col['name']] = 2;
            } elseif ($this->isDateColumn($col)) {
                $priority[$col['name']] = 1;
            } else {
                $priority[$col['name']] = 1;
            }
        }

        // Sort by priority descending
        arsort($priority);

        return array_slice(array_keys($priority), 0, $limit);
    }

    /**
     * Find the most relevant primary date column (for default table sort).
     */
    protected function findPrimaryDateColumn(array $columns): ?string
    {
        $preferred = ['created_at', 'ordered_at', 'date', 'updated_at'];

        foreach ($preferred as $preferred_col) {
            foreach ($columns as $col) {
                if ($col['name'] === $preferred_col) {
                    return $preferred_col;
                }
            }
        }

        // Fall back to first date column found
        $dateColumns = $this->dateColumns($columns);

        return $dateColumns[0]['name'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Single-column classifiers (for display column selector)
    // -------------------------------------------------------------------------

    protected function isNumericColumn(array $col): bool
    {
        return count($this->numericColumns([$col])) > 0;
    }

    protected function isDateColumn(array $col): bool
    {
        return count($this->dateColumns([$col])) > 0;
    }

    protected function isCategoryColumn(array $col): bool
    {
        return count($this->categoryColumns([$col])) > 0;
    }

    // -------------------------------------------------------------------------
    // Label / Unit / Format Helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a snake_case table or column name to a human-readable label.
     * e.g. "order_items" → "Order Items"
     */
    protected function humanize(string $name): string
    {
        return Str::title(str_replace('_', ' ', $name));
    }

    /**
     * Infer the display unit from a column name (dynamic pattern match).
     */
    protected function inferUnit(string $colName): string
    {
        $name = strtolower($colName);

        $currencySignals = [
            'amount',
            'price',
            'cost',
            'revenue',
            'salary',
            'fee',
            'balance',
            'income',
            'profit',
            'discount',
            'tax',
            'charge',
            'budget'
        ];
        foreach ($currencySignals as $signal) {
            if (str_contains($name, $signal)) {
                return 'currency';
            }
        }

        $countSignals = ['quantity', 'qty', 'stock', 'count', 'weight'];
        foreach ($countSignals as $signal) {
            if (str_contains($name, $signal)) {
                return 'number';
            }
        }

        return 'number';
    }

    /**
     * Infer the display format from a column name.
     */
    protected function inferFormat(string $colName): string
    {
        return match ($this->inferUnit($colName)) {
            'currency' => 'currency',
            default    => 'number',
        };
    }
}
