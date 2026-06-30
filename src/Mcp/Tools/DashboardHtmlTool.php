<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Tools;

use Webbycrown\McpDashboardStudio\Mcp\DTO\DashboardSpec;
use Webbycrown\McpDashboardStudio\Mcp\Services\DashboardGenerator;
use Webbycrown\McpDashboardStudio\Support\RoutePaths;
use Webbycrown\McpDashboardStudio\Mcp\Services\Database\Renderer\DashboardCssRenderer;
use Webbycrown\McpDashboardStudio\Mcp\Services\Database\Renderer\DashboardHtmlRenderer;
use Webbycrown\McpDashboardStudio\Mcp\Services\Database\Renderer\DashboardJsRenderer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP Tool: Generate a full interactive analytics dashboard from a natural language prompt.
 *
 * This tool performs live database introspection, generates KPIs, charts, tables,
 * and filters based on the actual schema, and returns BOTH a live dashboard URL
 * AND the full raw data for AI models to enhance or present directly.
 *
 * The response is designed to be consumed by ANY AI model (Claude, GPT, Gemini, etc.)
 * — it provides everything needed to display a rich analytics dashboard.
 */
#[Description(
    'Generate a full interactive analytics dashboard with live database data. '
    . 'Returns a persisted live dashboard URL (viewable in browser with full interactivity), '
    . 'raw hydrated data (KPIs with computed values, chart datasets, table rows), '
    . 'and pre-rendered HTML/CSS/JS. '
    . 'The AI model can either direct users to the live URL or use the raw data to build custom visualizations. '
    . 'All data comes from live database queries — no mock data.',
)]
class DashboardHtmlTool extends Tool
{
    public function name(): string
    {
        return 'dashboard-html-tool';
    }

    public function handle(Request $request): Response
    {
        $prompt = trim((string) $request->get('prompt', ''));
        Log::info('DashboardHtmlTool: Received rendering request', [
            'tool'          => $this->name(),
            'prompt_length' => strlen($prompt),
            'session_id'    => $request->sessionId(),
            'uri'           => $request->uri(),
        ]);

        if ($prompt === '') {
            Log::warning('DashboardHtmlTool: Empty prompt received', [
                'session_id' => $request->sessionId(),
                'uri'        => $request->uri(),
            ]);

            return Response::error('A prompt is required to generate dashboard HTML and CSS.');
        }

        try {
            Log::info('DashboardHtmlTool: Starting HTML/CSS/JS generation');

            // Phase 1-5: Build dashboard through existing pipeline
            $generator = app(DashboardGenerator::class);
            $dashboard = $generator->generateDashboardConfig($prompt);
            $spec      = new DashboardSpec($dashboard);

            // Persist the generated dashboard so it appears in mcp_dashboard_definitions
            $stored = false;
            $slug   = null;
            $uuid   = null;
            $liveUrl = null;
            try {
                $definition = app(\Webbycrown\McpDashboardStudio\Mcp\Services\DashboardStorageService::class)->storeSpec($prompt, $dashboard);
                $stored = true;
                $slug   = $definition->slug ?? null;
                $uuid   = $definition->uuid ?? null;
                $liveUrl = RoutePaths::dashboardShowUrl($slug);
                Log::info('DashboardHtmlTool: Dashboard persisted', [
                    'id'       => $definition->id ?? null,
                    'slug'     => $slug,
                    'uuid'     => $uuid,
                    'live_url' => $liveUrl,
                ]);
            } catch (\Throwable $e) {
                Log::warning('DashboardHtmlTool: Failed to persist dashboard', ['error' => $e->getMessage()]);
            }

            // Phase 6: Rendering layer (independent — never modifies the spec)
            $htmlRenderer  = app(DashboardHtmlRenderer::class);
            $cssRenderer   = app(DashboardCssRenderer::class);
            $jsRenderer    = app(DashboardJsRenderer::class);
            $bladeRenderer = app(DashboardBladeRenderer::class);

            // Build the AI-model-agnostic response payload
            $payload = [
                // ── Instructions for any AI model ──
                'instructions' => 'This response contains a complete analytics dashboard generated from live database data. '
                    . 'You have three options: '
                    . '(1) Direct the user to the live_url for a fully interactive experience with AJAX filters, theme toggle, and Chart.js visualizations. '
                    . '(2) Use the raw_data to build your own custom HTML/visualization with the actual computed values. '
                    . '(3) Use the pre-rendered HTML/CSS/JS from the render section. '
                    . 'The raw_data contains real database values — use them to create accurate, data-driven dashboards.',

                // ── Live interactive dashboard URL ──
                'live_url' => $liveUrl,
                'slug'     => $slug,
                'uuid'     => $uuid,

                // ── Dashboard metadata ──
                'dashboard' => [
                    'title'       => $dashboard['title'] ?? 'Dashboard',
                    'description' => $dashboard['description'] ?? '',
                    'component_counts' => [
                        'kpis'    => count($dashboard['kpis'] ?? []),
                        'charts'  => count($dashboard['charts'] ?? []),
                        'tables'  => count($dashboard['tables'] ?? []),
                        'filters' => count($dashboard['filters'] ?? []),
                    ],
                ],

                // ── Raw hydrated data (for AI models to build custom visualizations) ──
                'raw_data' => [
                    'kpis'    => $this->extractKpiData($dashboard['kpis'] ?? []),
                    'charts'  => $this->extractChartData($dashboard['charts'] ?? []),
                    'tables'  => $this->extractTableData($dashboard['tables'] ?? []),
                    'filters' => $this->extractFilterData($dashboard['filters'] ?? []),
                ],

                // ── Pre-rendered HTML/CSS/JS ──
                'render' => [
                    'html' => $htmlRenderer->render($spec),
                    'css'  => $cssRenderer->render(),
                    'js'   => $jsRenderer->render($spec),
                ],

                // ── Blade template for Laravel integration ──
                'blade' => $bladeRenderer->render($spec),

                // ── Persistence status ──
                'stored' => $stored,
            ];

            Log::info('DashboardHtmlTool: Rendering complete', [
                'html_length'  => strlen($payload['render']['html']),
                'blade_length' => strlen($payload['blade']['content'] ?? ''),
                'stored'       => $stored,
                'slug'         => $slug,
                'live_url'     => $liveUrl,
                'kpi_count'    => count($payload['raw_data']['kpis']),
                'chart_count'  => count($payload['raw_data']['charts']),
                'table_count'  => count($payload['raw_data']['tables']),
            ]);

            return Response::json($payload);
        } catch (\Throwable $exception) {
            Log::error('DashboardHtmlTool: Rendering failed', [
                'error'  => $exception->getMessage(),
                'class'  => get_class($exception),
                'prompt' => $prompt,
                'stack'  => $exception->getTraceAsString(),
            ]);

            return Response::error('Unable to generate dashboard HTML and CSS at this time.');
        }
    }

    // ─── Raw Data Extractors (schema-agnostic) ─────────────────────────

    /**
     * Extract clean KPI data for AI consumption.
     */
    protected function extractKpiData(array $kpis): array
    {
        return array_map(function ($kpi) {
            return [
                'title'  => $kpi['title'] ?? 'KPI',
                'value'  => $kpi['value'] ?? 0,
                'format' => $kpi['format'] ?? 'number',
                'unit'   => $kpi['unit'] ?? null,
                'table'  => $kpi['table'] ?? null,
                'column' => $kpi['column'] ?? null,
                'aggregate' => $kpi['aggregate'] ?? 'count',
            ];
        }, $kpis);
    }

    /**
     * Extract chart data with labels and datasets for AI consumption.
     */
    protected function extractChartData(array $charts): array
    {
        return array_map(function ($chart) {
            return [
                'title'     => $chart['title'] ?? 'Chart',
                'chartType' => $chart['chartType'] ?? 'bar',
                'table'     => $chart['table'] ?? null,
                'x_column'  => $chart['x_column'] ?? null,
                'y_column'  => $chart['y_column'] ?? null,
                'data'      => $chart['data'] ?? ['labels' => [], 'datasets' => []],
            ];
        }, $charts);
    }

    /**
     * Extract table data with headers and rows for AI consumption.
     */
    protected function extractTableData(array $tables): array
    {
        return array_map(function ($table) {
            return [
                'title'   => $table['title'] ?? 'Data Table',
                'table'   => $table['table'] ?? null,
                'headers' => $table['headers'] ?? $table['columns'] ?? [],
                'rows'    => $table['rows'] ?? [],
                'row_count' => count($table['rows'] ?? []),
            ];
        }, $tables);
    }

    /**
     * Extract filter metadata for AI consumption.
     */
    protected function extractFilterData(array $filters): array
    {
        return array_map(function ($filter) {
            return [
                'title'   => $filter['title'] ?? 'Filter',
                'column'  => $filter['column'] ?? $filter['field'] ?? null,
                'table'   => $filter['table'] ?? null,
                'type'    => $filter['filterType'] ?? $filter['control'] ?? 'select',
                'options' => $filter['options'] ?? [],
            ];
        }, $filters);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('Natural language dashboard requirement. Be specific about what data to show (e.g. "sales overview with revenue by month and top products").'),
        ];
    }
}
