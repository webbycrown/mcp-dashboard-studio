<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;

/**
 * DynamicQueryExecutor
 *
 * Executes query builder instances produced by DynamicQueryBuilder.
 *
 * Separation of concerns:
 *   DynamicQueryBuilder  → builds the query (no execution)
 *   DynamicQueryExecutor → executes the query (no building)
 *
 * Provides multiple execution strategies:
 *   execute()       → single row  → ['value' => 245]
 *   executeAll()    → all rows    → [['label'=>'a','value'=>10], ...]
 *   executeScalar() → raw scalar  → 245
 *   executeMetric() → full flow   → takes metric definition, builds & executes
 *
 * All errors are caught per-query so one failure never crashes the pipeline.
 */
class DynamicQueryExecutor
{
    public function __construct(
        protected DynamicQueryBuilder $builder,
    ) {}

    // =========================================================================
    // Core Execution Methods
    // =========================================================================

    /**
     * Execute a query and return the first row as an associative array.
     *
     * @param  Builder  $query  A query builder instance from DynamicQueryBuilder
     * @return array            e.g. ['value' => 245]
     */
    public function execute(Builder $query): array
    {
        $row = $query->first();

        return $row ? (array) $row : [];
    }

    /**
     * Execute a query and return all rows as an array of associative arrays.
     *
     * @param  Builder  $query
     * @return list<array>  e.g. [['label'=>'active','value'=>80], ['label'=>'inactive','value'=>20]]
     */
    public function executeAll(Builder $query): array
    {
        return $query->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Execute a query and return a single scalar value.
     * Extracts the 'value' key from the first row.
     *
     * @param  Builder  $query
     * @return int|float|null  e.g. 245
     */
    public function executeScalar(Builder $query): int|float|null
    {
        $row = $this->execute($query);

        if (! isset($row['value'])) {
            return null;
        }

        $val = $row['value'];

        return is_numeric($val) ? $val + 0 : null;
    }

    // =========================================================================
    // Metric-Level Execution (build + execute in one call)
    // =========================================================================

    /**
     * Build and execute a metric definition in one call.
     * Takes a raw metric array, builds the query via DynamicQueryBuilder,
     * executes it, and returns the enriched metric with resolved data.
     *
     * @param  array  $metric  Metric definition from MetricDiscoveryService
     * @return array           Same metric with 'value', 'data', 'rows', or 'options' populated
     */
    public function executeMetric(array $metric): array
    {
        $kind = $this->detectKind($metric);

        try {
            return match ($kind) {
                'kpi'    => $this->executeKpiMetric($metric),
                'chart'  => $this->executeChartMetric($metric),
                'table'  => $this->executeTableMetric($metric),
                'filter' => $this->executeFilterMetric($metric),
                default  => $this->executeKpiMetric($metric),
            };
        } catch (\Throwable $e) {
            Log::warning('DynamicQueryExecutor: Metric execution failed', [
                'metric' => $metric['title'] ?? '',
                'kind'   => $kind,
                'error'  => $e->getMessage(),
            ]);

            return array_merge($metric, ['_error' => $e->getMessage()]);
        }
    }

    /**
     * Build and execute all metrics in a discovery result set.
     * Accepts the direct output of MetricDiscoveryService::discover().
     *
     * @param  array{kpis: list<array>, charts: list<array>, tables: list<array>, filters: list<array>}  $candidates
     * @return array{kpis: list<array>, charts: list<array>, tables: list<array>, filters: list<array>}
     */
    public function executeAll_metrics(array $candidates): array
    {
        return [
            'kpis'    => array_map(fn ($m) => $this->executeKpiMetric($m), $candidates['kpis'] ?? []),
            'charts'  => array_map(fn ($m) => $this->executeChartMetric($m), $candidates['charts'] ?? []),
            'tables'  => array_map(fn ($m) => $this->executeTableMetric($m), $candidates['tables'] ?? []),
            'filters' => array_map(fn ($m) => $this->executeFilterMetric($m), $candidates['filters'] ?? []),
        ];
    }

    // =========================================================================
    // Per-Kind Executors
    // =========================================================================

    /**
     * Build + execute a KPI metric → returns metric with 'value' populated.
     *
     * Input:  ['title'=>'Total Orders', 'table'=>'orders', 'aggregate'=>'COUNT', 'column'=>'*']
     * Output: ['title'=>'Total Orders', ..., 'value' => 1542]
     */
    protected function executeKpiMetric(array $metric): array
    {
        $query = $this->builder->buildKpiQuery($metric);

        if (! $query) {
            return array_merge($metric, ['value' => null, '_error' => 'Failed to build KPI query.']);
        }

        try {
            $value = $this->executeScalar($query);

            return array_merge($metric, ['value' => $value]);

        } catch (\Throwable $e) {
            Log::warning('DynamicQueryExecutor: KPI execution failed', [
                'title' => $metric['title'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return array_merge($metric, ['value' => null, '_error' => $e->getMessage()]);
        }
    }

    /**
     * Build + execute a chart metric → returns metric with 'data' populated.
     *
     * Output adds:
     *   data.labels   : ['2024-01', '2024-02', ...]
     *   data.datasets : [{ label: '...', data: [120, 80, ...] }]
     */
    protected function executeChartMetric(array $metric): array
    {
        $query = $this->builder->buildChartQuery($metric);

        if (! $query) {
            return array_merge($metric, ['data' => null, '_error' => 'Failed to build chart query.']);
        }

        try {
            $rows = $this->executeAll($query);

            // Determine label and value column names from the rows
            $labelKey = isset($rows[0]['period']) ? 'period' : (isset($rows[0]['label']) ? 'label' : null);
            $valueKey = 'value';

            $labels = $labelKey ? array_column($rows, $labelKey) : [];
            $values = array_map(fn ($r) => is_numeric($r[$valueKey] ?? null) ? $r[$valueKey] + 0 : 0, $rows);

            $data = [
                'labels'   => $labels,
                'datasets' => [[
                    'label' => $metric['title'] ?? $metric['table'] ?? 'Chart',
                    'data'  => $values,
                ]],
            ];

            return array_merge($metric, ['data' => $data]);

        } catch (\Throwable $e) {
            Log::warning('DynamicQueryExecutor: Chart execution failed', [
                'title' => $metric['title'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return array_merge($metric, ['data' => null, '_error' => $e->getMessage()]);
        }
    }

    /**
     * Build + execute a data-table metric → returns metric with 'headers' + 'rows'.
     */
    protected function executeTableMetric(array $metric): array
    {
        $query = $this->builder->buildTableQuery($metric);

        if (! $query) {
            return array_merge($metric, ['headers' => [], 'rows' => [], '_error' => 'Failed to build table query.']);
        }

        try {
            $rows = $this->executeAll($query);

            return array_merge($metric, [
                'headers' => ! empty($rows) ? array_keys($rows[0]) : ($metric['columns'] ?? []),
                'rows'    => $rows,
            ]);

        } catch (\Throwable $e) {
            Log::warning('DynamicQueryExecutor: Table execution failed', [
                'table' => $metric['table'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return array_merge($metric, ['headers' => [], 'rows' => [], '_error' => $e->getMessage()]);
        }
    }

    /**
     * Build + execute a filter metric → returns metric with 'options' populated.
     * Date-range filters are passed through without a query.
     */
    protected function executeFilterMetric(array $metric): array
    {
        // Date-range filters don't need DB resolution
        if (($metric['type'] ?? '') === 'date_range') {
            return $metric;
        }

        $query = $this->builder->buildFilterQuery($metric);

        if (! $query) {
            return $metric;
        }

        try {
            $column  = $metric['column'] ?? '';
            $options = $query->pluck($column)->toArray();

            return array_merge($metric, ['options' => $options]);

        } catch (\Throwable $e) {
            Log::warning('DynamicQueryExecutor: Filter execution failed', [
                'filter' => $metric['title'] ?? '',
                'error'  => $e->getMessage(),
            ]);

            return array_merge($metric, ['options' => [], '_error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Detect metric kind from its array shape.
     */
    protected function detectKind(array $metric): string
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

        return 'kpi';
    }
}
