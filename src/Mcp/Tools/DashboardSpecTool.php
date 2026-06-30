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
 * MCP Tool: Generate complete dashboard specification.
 *
 * Primary tool for dashboard generation.
 * Takes a prompt and outputs a complete, structured dashboard spec
 * ready for rendering or further processing.
 */
class DashboardSpecTool extends Tool
{
    public function name(): string
    {
        return 'dashboard-spec-tool';
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        $prompt = trim((string) $request->get('prompt', ''));
        Log::debug('DashboardSpecTool: Received request', [
            'session' => $request->sessionId(),
            'prompt_length' => strlen($prompt),
        ]);

        if ($prompt === '') {
            Log::warning('DashboardSpecTool: Empty prompt received', [
                'session' => $request->sessionId(),
                'uri' => $request->uri(),
            ]);

            return Response::error('A prompt is required to build the dashboard spec.');
        }

        try {
            Log::info('DashboardSpecTool: Building dashboard spec from prompt');
            $dashboard = app(DashboardGenerator::class)->generateDashboardConfig($prompt);
            Log::info('DashboardSpecTool: Dashboard spec built successfully');

            // Persist the generated spec so clients invoking MCP receive a stored dashboard
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
                Log::info('DashboardSpecTool: Dashboard spec persisted', [
                    'id'       => $definition->id ?? null,
                    'slug'     => $slug,
                    'uuid'     => $uuid,
                    'live_url' => $liveUrl,
                ]);
            } catch (\Throwable $e) {
                Log::warning('DashboardSpecTool: Failed to persist generated spec', [
                    'error' => $e->getMessage(),
                ]);
            }

            return Response::structured([
                'instructions' => 'Dashboard spec generated from live database. '
                    . 'Use live_url for interactive viewing, or use the dashboard data to render custom output.',
                'live_url'  => $liveUrl,
                'slug'      => $slug,
                'uuid'      => $uuid,
                'dashboard' => $dashboard,
                'stored'    => $stored,
            ]);
        } catch (\Throwable $exception) {
            Log::error('DashboardSpecTool: Build failed', [
                'prompt' => $prompt,
                'error' => $exception->getMessage(),
                'class' => get_class($exception),
                'stack' => $exception->getTraceAsString(),
            ]);

            return Response::error('Unable to build the dashboard specification.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()->required(),
        ];
    }
}
