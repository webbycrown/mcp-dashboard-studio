<?php

namespace Webbycrown\McpDashboardStudio\Mcp\Tools;

use Webbycrown\McpDashboardStudio\Mcp\Services\BladeFileGenerator;
use Webbycrown\McpDashboardStudio\Models\McpDashboardDefinition;
use Webbycrown\McpDashboardStudio\Support\RoutePaths;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP Tool: Create a Blade template file from an existing dashboard.
 *
 * This tool reads an already-generated dashboard from the database (by slug)
 * and creates a standalone Blade file in the host project at:
 *   resources/views/dashboard-studio/dashboard-studio.blade.php
 *
 * The generated Blade uses the same published CSS/JS assets:
 *   - /mcp-dashboard-studio/assets/css/style.css
 *   - /mcp-dashboard-studio/assets/js/app.js
 *
 * Flow:
 *   1. AI generates dashboard (via dashboard-tool) → returns slug + live_url
 *   2. AI asks user: "Want me to create a Blade file in your project?"
 *   3. User says yes → AI calls this tool with the slug
 *   4. Tool creates Blade file → returns file path + route suggestion
 *
 * Prerequisites:
 *   - Tool must be enabled in config: tools.dashboard-blade-create = true
 *   - Dashboard must exist in mcp_dashboard_definitions table
 *   - Package assets must be published (vendor:publish --tag=mcp-dashboard-studio-assets)
 */
class DashboardBladeCreateTool extends Tool
{
    public function name(): string
    {
        return 'dashboard-blade-create';
    }

    public function handle(Request $request): Response|\Laravel\Mcp\ResponseFactory
    {
        $slug = trim((string) $request->get('slug', ''));

        Log::debug('DashboardBladeCreateTool: Received request', [
            'session' => $request->sessionId(),
            'slug'    => $slug,
        ]);

        // ── Validate slug ──
        if ($slug === '') {
            Log::warning('DashboardBladeCreateTool: Empty slug received', [
                'session' => $request->sessionId(),
            ]);

            return Response::error(
                'A dashboard slug is required. First generate a dashboard using the dashboard-tool, '
                . 'then use the returned slug to create a Blade file.'
            );
        }

        // ── Check tool is enabled in config ──
        if (! config('mcp-dashboard-studio.tools.dashboard-blade-create', false)) {
            Log::warning('DashboardBladeCreateTool: Tool is disabled in config', [
                'slug' => $slug,
            ]);

            return Response::error(
                'The dashboard-blade-create tool is disabled in the configuration. '
                . 'Enable it by setting tools.dashboard-blade-create to true in config/mcp-dashboard-studio.php.'
            );
        }

        // ── Find the dashboard by slug ──
        try {
            $definition = McpDashboardDefinition::where('slug', $slug)->first();
        } catch (\Throwable $e) {
            Log::error('DashboardBladeCreateTool: Database query failed', [
                'slug'  => $slug,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            return Response::error(
                'Failed to query the dashboard database. Please check your database connection.'
            );
        }

        if (! $definition) {
            Log::warning('DashboardBladeCreateTool: Dashboard not found', [
                'slug' => $slug,
            ]);

            return Response::error(
                "No dashboard found with slug: \"{$slug}\". "
                . 'Please generate a dashboard first using the dashboard-tool, '
                . 'then use the returned slug to create a Blade file.'
            );
        }

        $layoutJson = $definition->layout_json;

        if (empty($layoutJson) || empty($layoutJson['components'] ?? [])) {
            Log::warning('DashboardBladeCreateTool: Dashboard has empty layout', [
                'slug' => $slug,
                'id'   => $definition->id,
            ]);

            return Response::error(
                "The dashboard \"{$slug}\" exists but contains no components. "
                . 'Please regenerate the dashboard with a more specific prompt.'
            );
        }

        // ── Generate the Blade file ──
        try {
            $generator = app(BladeFileGenerator::class);
            $result    = $generator->generate($layoutJson, $slug);
        } catch (\RuntimeException $e) {
            Log::error('DashboardBladeCreateTool: Blade file generation failed', [
                'slug'  => $slug,
                'error' => $e->getMessage(),
            ]);

            return Response::error(
                'Failed to create the Blade file: ' . $e->getMessage()
            );
        } catch (\Throwable $e) {
            Log::error('DashboardBladeCreateTool: Unexpected error during Blade generation', [
                'slug'  => $slug,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'stack' => $e->getTraceAsString(),
            ]);

            return Response::error(
                'An unexpected error occurred while creating the Blade file. Please check the application logs.'
            );
        }

        // ── Build live URL ──
        $liveUrl = RoutePaths::dashboardShowUrl($slug);

        Log::info('DashboardBladeCreateTool: Blade file created successfully', [
            'slug'      => $slug,
            'file_path' => $result['file_path'] ?? '',
            'view_name' => $result['view_name'] ?? '',
        ]);

        return Response::structured([
            // ── Instructions for the AI model ──
            'instructions' => 'A Blade template has been created at '
                . ($result['file_path'] ?? 'resources/views/dashboard-studio/dashboard-studio.blade.php') . '. '
                . 'To serve it, add a route to your web.php file: '
                . ($result['route_suggestion'] ?? '') . ' '
                . 'The Blade uses the published CSS/JS assets at /mcp-dashboard-studio/assets/. '
                . 'Make sure assets are published by running: php artisan vendor:publish --tag=mcp-dashboard-studio-assets. '
                . 'AJAX filters connect to the existing ' . RoutePaths::dashboardFilterUrl($slug) . ' endpoint.',

            // ── File creation result ──
            'success'          => true,
            'file_path'        => $result['file_path'] ?? '',
            'view_name'        => $result['view_name'] ?? '',
            'route_suggestion' => $result['route_suggestion'] ?? '',

            // ── Dashboard context ──
            'dashboard_title' => $layoutJson['title'] ?? 'Dashboard',
            'slug'            => $slug,
            'uuid'            => $definition->uuid ?? null,
            'live_url'        => $liveUrl,
            'component_count' => count($layoutJson['components'] ?? []),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()
                ->description(
                    'The slug of an already-generated dashboard. '
                    . 'This is returned by the dashboard-tool when a dashboard is first generated. '
                    . 'Example: "employee-department-designation-dashboard".'
                )
                ->required(),
        ];
    }

    public function description(): string
    {
        return <<<'TEXT'
                    Create a Blade template file in the host Laravel project from an existing dashboard.

                    This tool takes the slug of an already-generated dashboard and creates a standalone
                    Blade file at: resources/views/dashboard-studio/dashboard-studio.blade.php

                    The Blade file uses the same published CSS/JS assets as the package's live dashboard.
                    After creation, the developer can add a route to serve it directly.

                    USAGE:
                    1. First generate a dashboard using the dashboard-tool (get the slug from the response).
                    2. Ask the user if they want a Blade file created in their project.
                    3. If yes, call this tool with the slug.

                    PREREQUISITES:
                    - The dashboard must already exist (generated via dashboard-tool).
                    - Assets must be published: php artisan vendor:publish --tag=mcp-dashboard-studio-assets
                    - This tool must be enabled in config: tools.dashboard-blade-create = true

                    The generated Blade file is fully standalone and editable by the developer.
                TEXT;
    }
}
