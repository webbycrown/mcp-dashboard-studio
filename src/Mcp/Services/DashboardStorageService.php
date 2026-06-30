<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Services;

use Webbycrown\McpDashboardStudio\Models\McpDashboardAccess;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DashboardStorageService
 *
 * Converts the hydrated DashboardSpec array (output of the generation pipeline)
 * into the unified component-based layout_json format used by:
 *   - DashboardStudioController::show()  → Blade rendering
 *   - DashboardStudioController::filter() → AJAX re-query
 *   - app.js → Chart.js initialization
 *
 * The layout_json format uses a flat `components[]` array where each component has:
 *   - id         : unique component identifier
 *   - type       : component type (kpi, bar_chart, line_chart, table, filter, etc.)
 *   - grid       : grid position {x, y, w, h}
 *   - data       : display data (what Blade templates render)
 *   - datasource : query metadata (what the filter endpoint uses to re-query)
 *
 * SPEC FORMAT (input — from DashboardGenerator pipeline):
 *   KPI:    { id, type, title, value, format, data: {provider, table, metric, column} }
 *   Chart:  { id, type, title, chartType, data: {provider, table, group_by, x_column, ..., labels, datasets} }
 *   Table:  { id, type, title, columns, headers, rows, data: {provider, table, sort, order} }
 *   Filter: { id, type, title, field, control, options, data: {provider, table, column} }
 */
class DashboardStorageService
{
    /**
     * Convert the standard DashboardSpec array output to the unified
     * components-based layout format for DB persistence.
     *
     * @param  array  $specArray  Output of DashboardSpec::toArray()
     * @return array  Unified layout with components[]
     */
    public function convertSpecToLayoutJson(array $specArray): array
    {
        $components = [];

        foreach ($specArray['kpis'] ?? [] as $kpi) {
            try {
                $components[] = $this->convertKpi($kpi, $specArray['layout'] ?? []);
            } catch (\Throwable $e) {
                Log::warning('DashboardStorageService: KPI conversion failed', [
                    'kpi_id' => $kpi['id'] ?? 'unknown',
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        foreach ($specArray['charts'] ?? [] as $chart) {
            try {
                $components[] = $this->convertChart($chart, $specArray['layout'] ?? []);
            } catch (\Throwable $e) {
                Log::warning('DashboardStorageService: Chart conversion failed', [
                    'chart_id' => $chart['id'] ?? 'unknown',
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        foreach ($specArray['tables'] ?? [] as $table) {
            try {
                $components[] = $this->convertTable($table, $specArray['layout'] ?? []);
            } catch (\Throwable $e) {
                Log::warning('DashboardStorageService: Table conversion failed', [
                    'table_id' => $table['id'] ?? 'unknown',
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        foreach ($specArray['filters'] ?? [] as $filter) {
            try {
                $components[] = $this->convertFilter($filter, $specArray['layout'] ?? []);
            } catch (\Throwable $e) {
                Log::warning('DashboardStorageService: Filter conversion failed', [
                    'filter_id' => $filter['id'] ?? 'unknown',
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return [
            'title'       => $specArray['title'] ?? 'Dashboard',
            'description' => $specArray['description'] ?? '',
            'components'  => $components,
            'layout'      => $specArray['layout'] ?? [],
            'meta'        => $specArray['meta'] ?? [],
        ];
    }

    // ─── Component Converters ────────────────────────────────────────────

    /**
     * Convert a KPI spec item to the unified component format.
     *
     * Spec format after hydration:
     *   { id, type:'kpi', title, value:1542, format:'number',
     *     data: {provider, table, metric, column} }
     *
     * Layout format (what Blade reads):
     *   { id, type:'kpi', grid:{...},
     *     data: {title, value, unit, format},
     *     datasource: {provider, table, metric, column} }
     */
    protected function convertKpi(array $kpi, array $layoutGrid): array
    {
        $id   = $kpi['id'] ?? ('kpi_' . Str::random(8));
        $grid = $this->findGridInLayout($layoutGrid, $id);
        $data = $kpi['data'] ?? [];

        return [
            'id'   => $id,
            'type' => 'kpi',
            'grid' => $grid ?: ['x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            'data' => [
                'title'  => $kpi['title'] ?? 'KPI',
                'value'  => $kpi['value'] ?? 0,
                'unit'   => $kpi['unit'] ?? $kpi['format'] ?? null,
                'format' => $kpi['format'] ?? 'number',
            ],
            'datasource' => [
                'provider'  => $data['provider'] ?? 'database',
                'table'     => $data['table'] ?? null,
                'metric'    => $data['metric'] ?? 'COUNT',
                'column'    => $data['column'] ?? '*',
                'aggregate' => $data['metric'] ?? 'COUNT',
            ],
        ];
    }

    /**
     * Convert a chart spec item to the unified component format.
     *
     * Spec format after hydration:
     *   { id, type:'chart', title, chartType:'line',
     *     data: {provider, table, group_by, x_column, y_column, y_aggregate,
     *            labels:[...], datasets:[...]} }
     *
     * Layout format (what Blade + JS read):
     *   { id, type:'line_chart', grid:{...},
     *     data: {title, chartType, data: {labels, datasets}},
     *     datasource: {provider, table, group_by, x_column, y_column, y_aggregate} }
     */
    protected function convertChart(array $chart, array $layoutGrid): array
    {
        $id        = $chart['id'] ?? ('chart_' . Str::random(8));
        $grid      = $this->findGridInLayout($layoutGrid, $id);
        $specData  = $chart['data'] ?? [];
        $chartType = $chart['chartType'] ?? $specData['chartType'] ?? 'bar';
        $type      = $chartType . '_chart';

        // After hydration, DataSourceResolver merges labels/datasets INTO $chart['data'].
        // Extract the hydrated chart data (labels + datasets) for the display layer.
        $hydratedData = [
            'labels'   => $specData['labels'] ?? [],
            'datasets' => $specData['datasets'] ?? [],
        ];

        // Extract datasource metadata for the filter endpoint to re-query.
        // These fields live inside $chart['data'] (the provider config), NOT at top-level.
        $datasource = [
            'provider'    => $specData['provider'] ?? 'database',
            'table'       => $specData['table'] ?? $chart['table'] ?? null,
            'group_by'    => $specData['group_by'] ?? $chart['group_by'] ?? null,
            'x_column'    => $specData['x_column'] ?? $chart['x_column'] ?? null,
            'x_group_by'  => $specData['x_group_by'] ?? null,
            'y_column'    => $specData['y_column'] ?? $chart['y_column'] ?? null,
            'y_aggregate' => $specData['y_aggregate'] ?? $specData['aggregate'] ?? 'COUNT',
        ];

        return [
            'id'   => $id,
            'type' => $type,
            'grid' => $grid ?: ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 5],
            'data' => [
                'title'     => $chart['title'] ?? 'Chart',
                'chartType' => $chartType,
                'data'      => $hydratedData,
            ],
            'datasource' => $datasource,
        ];
    }

    /**
     * Convert a table spec item to the unified component format.
     *
     * Spec format after hydration:
     *   { id, type:'table', title, columns:['name','email'],
     *     headers:['name','email'], rows:[{...},...],
     *     data: {provider, table, sort, order} }
     *
     * Layout format (what Blade reads):
     *   { id, type:'table', grid:{...},
     *     data: {title, columns, headers, rows},
     *     datasource: {provider, table, sort, order} }
     */
    protected function convertTable(array $table, array $layoutGrid): array
    {
        $id       = $table['id'] ?? ('table_' . Str::random(8));
        $grid     = $this->findGridInLayout($layoutGrid, $id);
        $specData = $table['data'] ?? [];

        return [
            'id'   => $id,
            'type' => 'table',
            'grid' => $grid ?: ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
            'data' => [
                'title'   => $table['title'] ?? 'Table',
                'columns' => $table['columns'] ?? [],
                'headers' => $table['headers'] ?? [],
                'rows'    => $table['rows'] ?? [],
            ],
            'datasource' => [
                'provider' => $specData['provider'] ?? 'database',
                'table'    => $specData['table'] ?? $table['table'] ?? null,
                'sort'     => $specData['sort'] ?? null,
                'order'    => $specData['order'] ?? 'desc',
                'limit'    => $specData['limit'] ?? null,
            ],
        ];
    }

    /**
     * Convert a filter spec item to the unified component format.
     *
     * Spec format after hydration:
     *   { id, type:'filter', title, field:'status', control:'select',
     *     options:['active','inactive'],
     *     data: {provider, table, column, options:[...]} }
     *
     * Layout format (what Blade reads):
     *   { id, type:'filter', grid:{...},
     *     data: {title, field, control, options},
     *     datasource: {provider, table, column} }
     */
    protected function convertFilter(array $filter, array $layoutGrid): array
    {
        $id       = $filter['id'] ?? ('filter_' . Str::random(8));
        $grid     = $this->findGridInLayout($layoutGrid, $id);
        $specData = $filter['data'] ?? [];

        // Options may exist at top-level (after hydration) or inside data
        $options = $filter['options'] ?? $specData['options'] ?? [];

        // Column/field may exist at top-level or inside data
        $column = $filter['field'] ?? $specData['column'] ?? $filter['column'] ?? null;

        return [
            'id'   => $id,
            'type' => 'filter',
            'grid' => $grid ?: ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 2],
            'data' => [
                'title'   => $filter['title'] ?? 'Filter',
                'field'   => $column,
                'column'  => $column,
                'control' => $filter['control'] ?? $filter['filterType'] ?? $specData['control'] ?? 'select',
                'options' => $options,
                'table'   => $specData['table'] ?? null,
            ],
            'datasource' => [
                'provider' => $specData['provider'] ?? 'database',
                'table'    => $specData['table'] ?? null,
                'column'   => $column,
            ],
        ];
    }

    // ─── Grid Layout Helpers ─────────────────────────────────────────────

    /**
     * Find grid layout by component ID.
     *
     * @param  array   $layout  Layout items from the spec
     * @param  string  $id      Component ID to look up
     * @return array|null  Grid position {x, y, w, h} or null
     */
    protected function findGridInLayout(array $layout, string $id): ?array
    {
        foreach ($layout as $item) {
            if (($item['id'] ?? null) === $id) {
                return [
                    'x' => $item['x'] ?? 0,
                    'y' => $item['y'] ?? 0,
                    'w' => $item['w'] ?? 3,
                    'h' => $item['h'] ?? 2,
                ];
            }
        }

        return null;
    }

    // ─── Validation ──────────────────────────────────────────────────────

    /**
     * Validate the components-based layout format before storage.
     *
     * Ensures every component has the required structural keys.
     * Returns a detailed result with per-component errors.
     *
     * @param  array  $layout  The layout_json array to validate
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateLayoutJson(array $layout): bool
    {
        if (!isset($layout['components']) || !is_array($layout['components'])) {
            Log::warning('DashboardStorageService: Layout validation failed — missing components array');
            return false;
        }

        if (empty($layout['components'])) {
            Log::warning('DashboardStorageService: Layout validation failed — components array is empty');
            return false;
        }

        foreach ($layout['components'] as $index => $component) {
            if (!isset($component['type']) || empty($component['type'])) {
                Log::warning('DashboardStorageService: Component missing type', ['index' => $index]);
                return false;
            }

            if (!isset($component['id']) || empty($component['id'])) {
                Log::warning('DashboardStorageService: Component missing id', ['index' => $index]);
                return false;
            }

            if (!isset($component['grid']) || !is_array($component['grid'])) {
                Log::warning('DashboardStorageService: Component missing grid', [
                    'index' => $index,
                    'id'    => $component['id'],
                ]);
                return false;
            }

            if (!isset($component['data']) || !is_array($component['data'])) {
                Log::warning('DashboardStorageService: Component missing data', [
                    'index' => $index,
                    'id'    => $component['id'],
                ]);
                return false;
            }
        }

        return true;
    }

    // ─── Persistence ─────────────────────────────────────────────────────

    /**
     * Convert and store standard spec array to DB.
     *
     * Converts the spec to layout_json format, validates it,
     * generates a unique slug, and persists to the database.
     *
     * @param  string  $prompt     The user's original prompt
     * @param  array   $specArray  Output of DashboardSpec::toArray()
     * @return McpDashboardDefinition
     *
     * @throws \InvalidArgumentException  If layout validation fails
     * @throws \Throwable                 If database write fails
     */
    public function storeSpec(string $prompt, array $specArray): McpDashboardDefinition
    {
        try {
            $layout = $this->convertSpecToLayoutJson($specArray);
        } catch (\Throwable $e) {
            Log::error('DashboardStorageService: Spec conversion failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            throw new \InvalidArgumentException(
                'Failed to convert dashboard spec to layout format: ' . $e->getMessage(),
                422,
                $e
            );
        }

        if (!$this->validateLayoutJson($layout)) {
            Log::error('DashboardStorageService: Layout validation failed', [
                'title'           => $layout['title'] ?? 'unknown',
                'component_count' => count($layout['components'] ?? []),
            ]);
            throw new \InvalidArgumentException(
                'Generated layout failed validation. Ensure the dashboard spec contains valid components.',
                422
            );
        }

        $name = $layout['title'] ?? 'Dashboard';

        try {
            $slug = $this->generateUniqueSlug($name);
        } catch (\Throwable $e) {
            Log::error('DashboardStorageService: Slug generation failed', [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
            // Fallback to UUID-based slug to prevent blocking
            $slug = Str::slug($name) . '-' . Str::random(6);
        }

        try {
            $definition = McpDashboardDefinition::create([
                'uuid'        => (string) Str::uuid(),
                'name'        => $name,
                'slug'        => $slug,
                'prompt'      => $prompt,
                'description' => $layout['description'] ?? null,
                'layout_json' => $layout,
                'status'      => 'private',
                'version'     => 1,
                'hash'        => md5(json_encode($layout)),
            ]);

            // Auto-grant creator access so the author can view their own dashboard
            $userId = Auth::id();
            if ($userId) {
                try {
                    McpDashboardAccess::create([
                        'dashboard_id' => $definition->id,
                        'user_id'      => $userId,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('DashboardStorageService: Failed to grant creator access', [
                        'dashboard_id' => $definition->id,
                        'slug'         => $slug,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }

            return $definition;
        } catch (\Throwable $e) {
            Log::error('DashboardStorageService: Database write failed', [
                'slug'  => $slug,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            throw $e;
        }
    }

    /**
     * Generate unique slug by appending counter if duplicate.
     *
     * @param  string  $name  Dashboard name to derive slug from
     * @return string  Unique slug
     */
    public function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);

        if (empty($slug)) {
            $slug = 'dashboard';
        }

        $originalSlug = $slug;
        $counter = 2;
        $maxAttempts = 100;

        while (McpDashboardDefinition::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;

            if ($counter > $maxAttempts) {
                $slug = $originalSlug . '-' . Str::random(6);
                break;
            }
        }

        return $slug;
    }
}
