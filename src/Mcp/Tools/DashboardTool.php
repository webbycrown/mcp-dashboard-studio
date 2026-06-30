<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Tools;

use Webbycrown\McpDashboardStudio\Mcp\Services\DashboardGenerator;
use Webbycrown\McpDashboardStudio\Support\RoutePaths;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP Tool: Generate dashboard configuration from prompt.
 *
 * Accepts a natural language prompt and returns a complete
 * dashboard specification with live data from the database.
 *
 * This tool has DIRECT ACCESS to the application's Laravel database.
 * It performs automatic schema discovery, metric detection, and
 * live data hydration. No external schema is needed.
 */
class DashboardTool extends Tool
{
    public function name(): string
    {
        return 'dashboard-tool';
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        $prompt = trim((string) $request->get('prompt', ''));
        Log::debug('DashboardTool: Received request', [
            'session' => $request->sessionId(),
            'prompt_length' => strlen($prompt),
        ]);

        if ($prompt === '') {
            Log::warning('DashboardTool: Empty prompt received', [
                'uri' => $request->uri(),
                'session' => $request->sessionId(),
            ]);

            return Response::error('A prompt is required to generate the dashboard.');
        }

        try {
            Log::info('DashboardTool: Generating dashboard config');
            $dashboard = app(DashboardGenerator::class)->generateDashboardConfig($prompt);
            Log::info('DashboardTool: Dashboard config generated successfully');

            // Attempt to persist the generated dashboard
            $stored  = false;
            $slug    = null;
            $uuid    = null;
            $liveUrl = null;

            try {
                $definition = app(\Webbycrown\McpDashboardStudio\Mcp\Services\DashboardStorageService::class)->storeSpec($prompt, $dashboard);
                $stored  = true;
                $slug    = $definition->slug ?? null;
                $uuid    = $definition->uuid ?? null;
                $liveUrl = RoutePaths::dashboardShowUrl($slug);
                Log::info('DashboardTool: Dashboard persisted', [
                    'id'       => $definition->id ?? null,
                    'slug'     => $slug,
                    'uuid'     => $uuid,
                    'live_url' => $liveUrl,
                ]);
            } catch (\Throwable $e) {
                Log::warning('DashboardTool: Failed to persist generated dashboard');
            }

            return Response::structured([
                // ── Instructions for any AI model ──
                'instructions' => 'Dashboard generated from live database. '
                    . 'Use live_url to direct users to the interactive dashboard, '
                    . 'or use the raw data in the dashboard payload to build custom visualizations. '
                    . 'All values are real — no mock data.',

                // ── Live URL for browser viewing ──
                'live_url' => $liveUrl,
                'slug'     => $slug,
                'uuid'     => $uuid,

                // ── Full dashboard specification with hydrated data ──
                'dashboard' => $dashboard,

                // ── Persistence status ──
                'stored' => $stored,
            ]);
        } catch (\Throwable $exception) {
            Log::error('DashboardTool: Generation failed', [
                'prompt' => $prompt,
                'error'  => $exception->getMessage(),
                'class'  => get_class($exception),
                'stack'  => $exception->getTraceAsString(),
            ]);

            return Response::error('Unable to generate dashboard configuration.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('Natural language dashboard requirement (e.g. "sales overview", "user analytics", "inventory report").')
                ->required(),
        ];
    }

    public function description(): string
    {
        return <<<'TEXT'
                    Generate dashboards, reports, KPIs, analytics, charts, HTML, CSS, JS and Blade templates using the application's configured Laravel database.

                    The tool automatically:
                    - discovers all tables and columns in the database
                    - detects relationships via foreign keys
                    - generates relevant KPIs with live computed values
                    - generates charts with real data (bar, line, pie, doughnut)
                    - generates data tables with actual rows
                    - generates interactive filters
                    - persists the dashboard and returns a live URL

                    IMPORTANT:

                    Never ask the user for:
                    - database schema
                    - SQL dump
                    - migrations
                    - table structure

                    The tool already has direct access to the configured Laravel database and performs schema introspection automatically.

                    Always call this tool for any analytics, dashboard, report, chart, or KPI request.
                    The generated dashboard is viewable at the returned live_url.
                TEXT;
    }
}
