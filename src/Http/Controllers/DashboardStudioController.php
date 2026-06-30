<?php

namespace Webbycrown\McpDashboardStudio\Http\Controllers;

use Webbycrown\McpDashboardStudio\Models\McpDashboardAuditLog;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * DashboardStudioController
 *
 * Handles rendering of stored dashboards and AJAX filter re-queries.
 *
 * Layout JSON format (what this controller reads):
 *   layout_json.components[] = {
 *     id, type, grid,
 *     data: { display data for Blade },
 *     datasource: { query metadata for re-querying }
 *   }
 *
 * Schema-agnostic: works with any table/column combination.
 * All database errors are caught and logged — never breaks the host app.
 */
class DashboardStudioController extends Controller
{
    /**
     * Render a stored dashboard by slug.
     *
     * Loads the layout_json from DB and extracts chart data for
     * Chart.js initialization via the `window.mcpCharts` JS variable.
     *
     * @param  string  $slug  Dashboard slug from URL
     * @return \Illuminate\View\View
     */
    public function show(string $slug)
    {
        try {
            $definition = McpDashboardDefinition::where('slug', $slug)->firstOrFail();
            $layout = $definition->layout_json;

            // ── Track view analytics + audit ──────────────────────────────
            try {
                $definition->increment('view_count');
                $definition->update(['last_viewed_at' => now()]);
                AuditLogger::write(
                    $definition->id,
                    McpDashboardAuditLog::EVENT_VIEW,
                    \Illuminate\Support\Facades\Auth::check() ? McpDashboardAuditLog::ACTOR_SYSTEM_USER : McpDashboardAuditLog::ACTOR_GUEST,
                    \Illuminate\Support\Facades\Auth::id(),
                    \Illuminate\Support\Facades\Auth::user()?->email,
                    [],
                    request()->ip()
                );
            } catch (\Throwable) {
                // Never break a page render for analytics
            }

            // Extract chart components for Chart.js initialization in JavaScript.
            // JS expects: { id, chartType, data: {labels:[], datasets:[]} }
            $charts = $this->extractChartsForJs($layout['components'] ?? []);

            return view('mcp-dashboard-studio::dashboard-studio.index', compact('layout', 'charts', 'slug'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('DashboardStudioController: Dashboard not found', [
                'slug'  => $slug,
                'error' => $e->getMessage(),
            ]);
            abort(404, 'Dashboard not found.');
        } catch (\Throwable $e) {
            Log::error('DashboardStudioController: Failed to render dashboard', [
                'slug'  => $slug,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            abort(500, 'Unable to load dashboard.');
        }
    }

    /**
     * Extract chart components into the format Chart.js (app.js) expects.
     *
     * Layout format:
     *   component.data = { title, chartType, data: {labels, datasets} }
     *
     * JS expects:
     *   { id, chartType, data: {labels, datasets} }
     *
     * @param  array  $components  All components from layout_json
     * @return array  Chart data array for window.mcpCharts
     */
    protected function extractChartsForJs(array $components): array
    {
        $charts = [];

        foreach ($components as $component) {
            $type = $component['type'] ?? '';

            if (! str_contains($type, 'chart')) {
                continue;
            }

            try {
                $componentData = $component['data'] ?? [];

                $charts[] = [
                    'id'        => $component['id'] ?? '',
                    'chartType' => $componentData['chartType'] ?? str_replace('_chart', '', $type),
                    'title'     => $componentData['title'] ?? 'Chart',
                    'data'      => $componentData['data'] ?? ['labels' => [], 'datasets' => []],
                ];
            } catch (\Throwable $e) {
                Log::warning('DashboardStudioController: Chart extraction failed', [
                    'component_id' => $component['id'] ?? 'unknown',
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return $charts;
    }

    /**
     * AJAX endpoint: Apply filters and return updated dashboard data.
     *
     * Accepts POST with filter values and re-queries the database
     * for KPIs, charts, and tables with WHERE clauses applied.
     *
     * Schema-agnostic: works with any table/column combination.
     *
     * @param  Request  $request  POST body: { filters: { column: value, ... } }
     * @param  string   $slug     Dashboard slug
     * @return JsonResponse
     */
    public function filter(Request $request, string $slug): JsonResponse
    {
        try {
            $definition = McpDashboardDefinition::where('slug', $slug)->firstOrFail();
            $layout     = $definition->layout_json;
            $filters    = $request->input('filters', []);
            $connection = $this->getConnection();

            if (config('mcp-dashboard-studio.logging_enabled', false)) {
                Log::debug('DashboardStudioController: Filter request', [
                    'slug'    => $slug,
                    'filters' => $filters,
                ]);
            }

            // Only keep non-empty filter values
            $activeFilters = collect($filters)->filter(fn($v) => $v !== '' && $v !== null);

            // Re-resolve each component with active filter conditions
            $updatedComponents = [];

            foreach ($layout['components'] ?? [] as $component) {
                $type = $component['type'] ?? '';

                // Filters pass through unchanged
                if ($type === 'filter') {
                    $updatedComponents[] = $component;
                    continue;
                }

                // Resolve the table this component queries from
                $table = $this->resolveComponentTable($component);

                if (! $table) {
                    $updatedComponents[] = $component;
                    continue;
                }

                // Determine which filters apply to this component's table
                $applicableFilters = $this->findApplicableFilters(
                    $activeFilters->toArray(),
                    $table,
                    $layout['components'] ?? [],
                    $connection
                );

                try {
                    if ($type === 'kpi') {
                        $component = $this->resolveKpiWithFilters($component, $applicableFilters, $connection);
                    } elseif (str_contains($type, 'chart')) {
                        $component = $this->resolveChartWithFilters($component, $applicableFilters, $connection);
                    } elseif ($type === 'table') {
                        $component = $this->resolveTableWithFilters($component, $applicableFilters, $connection);
                    }
                } catch (\Throwable $e) {
                    Log::warning('DashboardStudioController: Filter resolve failed for component', [
                        'component_id'   => $component['id'] ?? '',
                        'component_type' => $type,
                        'table'          => $table,
                        'error'          => $e->getMessage(),
                    ]);
                    // Return the component unchanged on error
                }

                $updatedComponents[] = $component;
            }

            return response()->json([
                'success'    => true,
                'components' => $updatedComponents,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('DashboardStudioController: Dashboard not found for filter', [
                'slug' => $slug,
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Dashboard not found.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('DashboardStudioController: Filter endpoint error', [
                'slug'  => $slug,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Unable to apply filters.',
            ], 500);
        }
    }

    // ─── Filter Helpers ──────────────────────────────────────────────────

    /**
     * Resolve which database table a component queries from.
     *
     * Checks datasource first (preferred), then falls back to data.
     *
     * @param  array  $component  Component from layout_json
     * @return string|null  Table name or null
     */
    protected function resolveComponentTable(array $component): ?string
    {
        return $component['datasource']['table']
            ?? $component['data']['table']
            ?? null;
    }

    /**
     * Find which filter columns apply to a given table.
     *
     * Schema-agnostic: checks if the filter's column exists in the target table
     * by querying the database schema directly.
     *
     * @param  array   $activeFilters  Currently active filter values { column: value }
     * @param  string  $table          Target table to check applicability for
     * @param  array   $components     All components (to find filter definitions)
     * @param  string  $connection     Database connection name
     * @return array   Applicable filters { column: value }
     */
    protected function findApplicableFilters(
        array $activeFilters,
        string $table,
        array $components,
        string $connection
    ): array {
        $applicable = [];

        foreach ($components as $comp) {
            if (($comp['type'] ?? '') !== 'filter') {
                continue;
            }

            // Read filter's table and column from datasource (preferred) or data
            $filterTable  = $comp['datasource']['table'] ?? $comp['data']['table'] ?? null;
            $filterColumn = $comp['datasource']['column'] ?? $comp['data']['column'] ?? $comp['data']['field'] ?? null;

            if (! $filterColumn || ! isset($activeFilters[$filterColumn])) {
                continue;
            }

            // Apply filter if: same table, OR the column exists in the target table
            if ($filterTable === $table || $this->columnExistsInTable($table, $filterColumn, $connection)) {
                $applicable[$filterColumn] = $activeFilters[$filterColumn];
            }
        }

        return $applicable;
    }

    /**
     * Check if a column exists in a table (schema-agnostic).
     *
     * @param  string  $table       Table name
     * @param  string  $column      Column name
     * @param  string  $connection  Database connection name
     * @return bool
     */
    protected function columnExistsInTable(string $table, string $column, string $connection): bool
    {
        try {
            $columns = Schema::connection($connection)->getColumnListing($table);
            return in_array($column, $columns, true);
        } catch (\Throwable $e) {
            Log::debug('DashboardStudioController: Column existence check failed', [
                'table'  => $table,
                'column' => $column,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ─── Component Re-resolvers (with filters) ───────────────────────────

    /**
     * Re-resolve a KPI component with filter WHERE clauses.
     *
     * Reads aggregate info from datasource, runs the query with filters,
     * and updates the component's display value.
     *
     * @param  array   $component   KPI component from layout_json
     * @param  array   $filters     Applicable filters { column: value }
     * @param  string  $connection  Database connection name
     * @return array   Updated component
     */
    protected function resolveKpiWithFilters(array $component, array $filters, string $connection): array
    {
        if (empty($filters)) {
            return $component;
        }

        $datasource = $component['datasource'] ?? [];
        $table      = $datasource['table'] ?? null;
        $metric     = $datasource['metric'] ?? $datasource['aggregate'] ?? 'COUNT';
        $column     = $datasource['column'] ?? '*';

        if (! $table) {
            return $component;
        }

        try {
            $query = DB::connection($connection)->table($table);

            foreach ($filters as $col => $val) {
                $query->where($col, $val);
            }

            $value = match (strtoupper($metric)) {
                'SUM'   => $query->sum($column),
                'AVG'   => round((float) $query->avg($column), 2),
                'MAX'   => $query->max($column),
                'MIN'   => $query->min($column),
                default => $query->count(),
            };

            $component['data']['value'] = $value;
        } catch (\Throwable $e) {
            Log::warning('DashboardStudioController: KPI filter query failed', [
                'component_id' => $component['id'] ?? '',
                'table'        => $table,
                'metric'       => $metric,
                'error'        => $e->getMessage(),
            ]);
        }

        return $component;
    }

    /**
     * Re-resolve a chart component with filter WHERE clauses.
     *
     * Reads grouping/aggregation info from datasource, runs the query
     * with filters, and updates the chart's labels + datasets.
     *
     * @param  array   $component   Chart component from layout_json
     * @param  array   $filters     Applicable filters { column: value }
     * @param  string  $connection  Database connection name
     * @return array   Updated component
     */
    protected function resolveChartWithFilters(array $component, array $filters, string $connection): array
    {
        if (empty($filters)) {
            return $component;
        }

        $datasource = $component['datasource'] ?? [];
        $data       = $component['data'] ?? [];
        $table      = $datasource['table'] ?? null;
        $groupBy    = $datasource['group_by'] ?? null;
        $xColumn    = $datasource['x_column'] ?? $groupBy;
        $yAggregate = $datasource['y_aggregate'] ?? 'COUNT';
        $yColumn    = $datasource['y_column'] ?? '*';

        if (! $table || ! $xColumn) {
            return $component;
        }

        try {
            $query = DB::connection($connection)->table($table);

            foreach ($filters as $col => $val) {
                $query->where($col, $val);
            }

            $agg = match (strtoupper($yAggregate)) {
                'SUM'   => DB::raw("SUM(`{$yColumn}`) as value"),
                'AVG'   => DB::raw("AVG(`{$yColumn}`) as value"),
                'MAX'   => DB::raw("MAX(`{$yColumn}`) as value"),
                'MIN'   => DB::raw("MIN(`{$yColumn}`) as value"),
                default => DB::raw('COUNT(*) as value'),
            };

            $results = $query->select($xColumn, $agg)
                ->groupBy($xColumn)
                ->orderBy($xColumn)
                ->limit(20)
                ->get();

            $labels = $results->pluck($xColumn)->toArray();
            $values = $results->pluck('value')->map(fn($v) => (float) $v)->toArray();

            // Update the chart data in the display layer
            $component['data']['data'] = [
                'labels'   => $labels,
                'datasets' => [['label' => $data['title'] ?? 'Data', 'data' => $values]],
            ];
        } catch (\Throwable $e) {
            Log::warning('DashboardStudioController: Chart filter query failed', [
                'component_id' => $component['id'] ?? '',
                'table'        => $table,
                'x_column'     => $xColumn,
                'error'        => $e->getMessage(),
            ]);
        }

        return $component;
    }

    /**
     * Re-resolve a table component with filter WHERE clauses.
     *
     * Re-queries the table with filters applied and updates the rows.
     *
     * @param  array   $component   Table component from layout_json
     * @param  array   $filters     Applicable filters { column: value }
     * @param  string  $connection  Database connection name
     * @return array   Updated component
     */
    protected function resolveTableWithFilters(array $component, array $filters, string $connection): array
    {
        if (empty($filters)) {
            return $component;
        }

        $datasource = $component['datasource'] ?? [];
        $table      = $datasource['table'] ?? null;

        if (! $table) {
            return $component;
        }

        try {
            $columns = $component['data']['columns'] ?? $component['data']['headers'] ?? [];
            $selectCols = collect($columns)
                ->map(fn($c) => is_array($c) ? ($c['name'] ?? null) : $c)
                ->filter()
                ->toArray();

            $query = DB::connection($connection)->table($table);

            // Prefix columns to avoid ambiguity in joins
            foreach ($filters as $col => $val) {
                $query->where("{$table}.{$col}", $val);
            }

            if (! empty($selectCols)) {
                $prefixed = array_map(fn($c) => "{$table}.{$c}", $selectCols);
                $query->select($prefixed);
            }

            // Order by created_at if available, otherwise no specific order
            try {
                $tableColumns = Schema::connection($connection)->getColumnListing($table);
                if (in_array('created_at', $tableColumns, true)) {
                    $query->orderByDesc("{$table}.created_at");
                }
            } catch (\Throwable $e) {
                // Skip ordering if schema introspection fails
            }

            $limit = $datasource['limit'] ?? 10;
            $rows  = $query->limit($limit)->get();

            $component['data']['rows'] = $rows->map(fn($r) => (array) $r)->toArray();
        } catch (\Throwable $e) {
            Log::warning('DashboardStudioController: Table filter query failed', [
                'component_id' => $component['id'] ?? '',
                'table'        => $table,
                'error'        => $e->getMessage(),
            ]);
        }

        return $component;
    }

    // ─── Utility ─────────────────────────────────────────────────────────

    /**
     * Get the configured database connection name.
     *
     * @return string
     */
    protected function getConnection(): string
    {
        return config('mcp-dashboard-studio.database.connection')
            ?: config('database.default', 'mysql');
    }
}
